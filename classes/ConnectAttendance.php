<?php

namespace block_signinsheet;

class ConnectAttendance {
    /**
     * @var \block_signinsheet\ConnectAttendance
     */
    private static $instance = null;

    /**
     * @var \core\plugininfo\mod
     */
    private $plugin_info;

    /**
     * @var string
     */
    private $plugin_dir;

    public static function getInstance()
    {
        if (self::$instance == null) {
          self::$instance = new ConnectAttendance();
        }

        return self::$instance;
    }

    private function __construct()
    {
        global $CFG;

        $this->plugin_info = \core_plugin_manager::instance()->get_plugin_info('mod_attendance');
        $this->plugin_dir = $CFG->dirroot . $this->plugin_info->get_dir();
    }

    public function getAttendanceModules($course_id)
    {
        $modules = get_course_mods($course_id);

        return array_filter($modules, function ($module) {
            return $module->modname === 'attendance' && $module->deletioninprogress === '0';
        });
    }

    public function getAttendanceForModule($module_id)
    {
        global $DB;

        $cm = get_coursemodule_from_id('attendance', $module_id, 0, false, MUST_EXIST);

        return $this->getAttendance($cm->instance);
    }

    public function getAttendance($attendance_id)
    {
        global $DB;

        $att = $DB->get_record('attendance', array('id' => $attendance_id), '*', MUST_EXIST);

        return $att;
    }

    public function getSessions($attendance_id)
    {
        global $DB;

        $sql = 'SELECT * FROM {attendance_sessions} WHERE attendanceid = :aid ORDER BY sessdate ASC';
        $params = array('aid'   => $attendance_id);

        return $DB->get_records_sql($sql, $params);
    }

    public function getSession($session_id)
    {
        global $DB;

        $session = $DB->get_record('attendance_sessions', array('id' => $session_id), '*', MUST_EXIST);

        return $session;
    }

    public function loadParams()
    {
        $fields = [
            'attendance',
            'session',
        ];

        return array_combine(
            $fields,
            array_map(function ($name) {
                return optional_param($name, 0, PARAM_INT);
            }, $fields)
        );
    }

    public function getContext()
    {
        if (!$this->plugin_info->is_enabled()) {
            return;
        }

        $params = $this->loadParams();

        if ($params['attendance'] === 0 || $params['session'] === 0) {
            return [
                'has_session' => false,
            ];
        }

        /**
         * Load helper functions: attendance_construct_session_time
         * regquires global $CFG
         */
        global $CFG;
        require_once($this->plugin_dir . '/locallib.php');

        $attendance = $this->getAttendance($params['attendance']);
        $session = $this->getSession($params['session']);

        $session_date = userdate($session->sessdate, get_string('strftimedmyw', 'attendance'));
        $session_time = attendance_construct_session_time($session->sessdate, $session->duration);

        $today = new \DateTime();
        $date = \DateTime::createFromFormat('U', $session->sessdate);
        $isToday = $date->diff($today)->format('%a') === '0';

        return [
            'has_session' => true,
            'attendance' => $attendance,
            'session' => $session,
            'session_date' => $session_date,
            'session_time' => $session_time,
            'session_display_datetime' => sprintf('%s %s', $session_date, $session_time),
            'session_datetime' => $date,
            'session_isToday' => $isToday,
        ];
    }

    public function getFormFields()
    {
        $params = $this->loadParams();

        $controls = array_map(
            function ($name, $value) {
                return sprintf(
                    '<input type="hidden" name="%s" value="%s" />',
                    $name,
                    $value,
                );
            },
            array_keys($params),
            $params
        );

        return implode('', $controls);
    }

    public function appendAttendanceSessionLinks($course_id, &$output)
    {
        if (!$this->plugin_info->is_enabled()) {
            return;
        }

        global $CFG;

        /**
         * Load helper functions: attendance_construct_session_time
         */
        require_once($this->plugin_dir . '/locallib.php');

        $today = new \DateTime();
        $icon = '<img src="' . $CFG->wwwroot . '/blocks/signinsheet/printer.gif"/>';

        $attendance_modules = $this->getAttendanceModules($course_id);

        foreach ($attendance_modules as $module) {
            $attendance = $this->getAttendanceForModule($module->id);
            $sessions = $this->getSessions($attendance->id);

            if (!count($sessions)) {
                continue;
            }

            $list = sprintf(
                '<h6 style="margin-top:1rem;padding-top:1rem;border-top:1px solid rgba(0,0,0,.125);">%s%s</h6><ul style="list-style-type: none;">',
                count($attendance_modules) > 1 ? sprintf('%s: ', $attendance->name) : '',
                get_string('sessions', 'mod_attendance')
            );

            foreach ($sessions as $session) {
                $date = \DateTime::createFromFormat('U', $session->sessdate);
                $isToday = $date->diff($today)->format('%a') === '0';

                $link = sprintf(
                    '<a href="%s" style="display:flex;justify-content:space-between;align-items:center;">%s<span style="flex-basis: 33%%">%s</span><span style="flex-basis: 33%%">%s</span></a>',
                    sprintf('%s/blocks/signinsheet/genlist/show.php?cid=%d&attendance=%d&session=%d', $CFG->wwwroot, $course_id, $attendance->id, $session->id),
                    $icon,
                    userdate($session->sessdate, get_string('strftimedmyw', 'attendance')),
                    attendance_construct_session_time($session->sessdate, $session->duration)
                );

                $list .= sprintf(
                    '<li style="padding:.125rem;font-size:.8rem;%s">%s</li>',
                    $isToday ? 'font-weight: bold;' : '',
                    $link
                );
            }
            $list .= sprintf('</ul>');

            $output .= $list;
            // $output .= '<pre>' . var_export(json_encode($sessions, JSON_PRETTY_PRINT), true) . '</pre>';
        }
    }
}