<?php

if (!defined('ABSPATH')) {
    exit;
}

class MC_Leads_Engine_Session {
    const COOKIE_NAME = 'mc_leads_engine_session';
    const TRANSIENT_PREFIX = 'mc_leads_engine_session_';

    protected $session_id = '';
    protected $data = array();

    public function maybe_start_session() {
        $this->session_id = $this->get_session_id_from_cookie();

        if (!$this->session_id) {
            $this->session_id = wp_generate_password(24, false, false);
            $this->persist_cookie($this->session_id);
        }

        $this->data = $this->load_data();

        if (empty($this->data['session_id'])) {
            $this->data = $this->default_data();
            $this->data['session_id'] = $this->session_id;
            $this->save_data();
        }

        return $this->session_id;
    }

    public function get_session_id() {
        if (!$this->session_id) {
            $this->session_id = $this->get_session_id_from_cookie();
        }

        return $this->session_id;
    }

    public function set_session_id($session_id) {
        $this->session_id = sanitize_text_field($session_id);
        $this->data = $this->load_data();
    }

    public function get_data() {
        if (empty($this->data)) {
            $this->data = $this->load_data();
        }

        if (empty($this->data)) {
            $this->data = $this->default_data();
        }

        return $this->data;
    }

    public function set_data($data) {
        $this->data = wp_parse_args(is_array($data) ? $data : array(), $this->default_data());
        $this->data['session_id'] = $this->get_session_id();
        $this->save_data();

        return $this->data;
    }

    public function update_data($data) {
        $current = $this->get_data();
        $merged = array_replace_recursive($current, is_array($data) ? $data : array());

        return $this->set_data($merged);
    }

    public function set_active_survey($survey_id) {
        $data = $this->get_data();
        $data['active_survey_id'] = absint($survey_id);
        $this->data = $data;

        return $this->save_data();
    }

    public function save_progress($survey_id, $current_step, $answers, $pricing = array()) {
        $data = $this->get_data();
        $data['active_survey_id'] = absint($survey_id);
        $data['current_step'] = max(1, absint($current_step));
        $data['answers'] = is_array($answers) ? $answers : array();
        $data['pricing'] = is_array($pricing) ? $pricing : array();
        $data['updated_at'] = current_time('mysql');

        $this->data = $data;

        return $this->save_data();
    }

    public function get_answers() {
        $data = $this->get_data();

        return !empty($data['answers']) && is_array($data['answers']) ? $data['answers'] : array();
    }

    public function mark_survey_started($survey_id) {
        $data = $this->get_data();
        if (!isset($data['started_surveys']) || !is_array($data['started_surveys'])) {
            $data['started_surveys'] = array();
        }

        $survey_id = absint($survey_id);
        if (!isset($data['started_surveys'][$survey_id])) {
            $data['started_surveys'][$survey_id] = current_time('mysql');
            $this->data = $data;
            $this->save_data();
            return true;
        }

        return false;
    }

    public function set_lead_id($lead_id) {
        $data = $this->get_data();
        $data['lead_id'] = absint($lead_id);
        $this->data = $data;

        return $this->save_data();
    }

    public function clear_session() {
        delete_transient($this->transient_key());
        $this->data = $this->default_data();
        $this->session_id = '';
        if (!headers_sent()) {
            $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
            $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
            setcookie(self::COOKIE_NAME, '', time() - 3600, $path, $domain, is_ssl(), true);
        }
        if (isset($_COOKIE[self::COOKIE_NAME])) {
            unset($_COOKIE[self::COOKIE_NAME]);
        }
    }

    protected function default_data() {
        return array(
            'session_id' => '',
            'active_survey_id' => 0,
            'current_step' => 1,
            'answers' => array(),
            'pricing' => array(
                'base_price' => 0,
                'total_price' => 0,
                'lead_score' => 0,
                'breakdown' => array(),
            ),
            'started_surveys' => array(),
            'lead_id' => 0,
            'updated_at' => current_time('mysql'),
        );
    }

    protected function save_data() {
        return set_transient($this->transient_key(), $this->data, DAY_IN_SECONDS * 14);
    }

    protected function load_data() {
        $data = get_transient($this->transient_key());

        if (!is_array($data)) {
            return array();
        }

        return $data;
    }

    protected function transient_key() {
        return self::TRANSIENT_PREFIX . $this->get_session_id();
    }

    protected function get_session_id_from_cookie() {
        if (!empty($this->session_id)) {
            return $this->session_id;
        }

        if (empty($_COOKIE[self::COOKIE_NAME])) {
            return '';
        }

        return sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME]));
    }

    protected function persist_cookie($session_id) {
        $expiration = time() + DAY_IN_SECONDS * 14;
        $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';

        if (!headers_sent()) {
            setcookie(self::COOKIE_NAME, $session_id, $expiration, $path, $domain, is_ssl(), true);
        }

        $_COOKIE[self::COOKIE_NAME] = $session_id;
    }
}
