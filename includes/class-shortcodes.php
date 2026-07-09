<?php

if (!defined('ABSPATH')) {
    exit;
}

class MC_Leads_Engine_Shortcodes {
    public function __construct() {
        add_shortcode('mc_survey', array($this, 'render_survey_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        add_action('wp_ajax_mc_leads_engine_save_progress', array($this, 'ajax_save_progress'));
        add_action('wp_ajax_nopriv_mc_leads_engine_save_progress', array($this, 'ajax_save_progress'));
        add_action('admin_post_mc_leads_engine_submit_survey', array($this, 'handle_submit_survey'));
        add_action('admin_post_nopriv_mc_leads_engine_submit_survey', array($this, 'handle_submit_survey'));

        add_action('wp_ajax_mc_leads_engine_get_thank_you', array($this, 'ajax_get_thank_you'));
        add_action('wp_ajax_nopriv_mc_leads_engine_get_thank_you', array($this, 'ajax_get_thank_you'));
        
        add_action('wp_ajax_mc_leads_engine_submit_survey_ajax', array($this, 'ajax_submit_survey'));
        add_action('wp_ajax_nopriv_mc_leads_engine_submit_survey_ajax', array($this, 'ajax_submit_survey'));
        add_action('init', array($this, 'maybe_handle_frontend_submit'));
    }

    public function maybe_handle_frontend_submit() {
        if (isset($_POST['action']) && $_POST['action'] === 'mc_leads_engine_submit_survey') {
            $this->handle_submit_survey();
        }
    }

    public function register_assets() {
        wp_register_style('mc-leads-engine-frontend', MC_LEADS_ENGINE_URL . 'assets/css/frontend.css', array(), MC_LEADS_ENGINE_VERSION);
        wp_register_script('mc-leads-engine-frontend', MC_LEADS_ENGINE_URL . 'assets/js/frontend.js', array(), MC_LEADS_ENGINE_VERSION, true);
    }

    public function render_survey_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => 0,
            'cf7' => 0,
        ), $atts, 'mc_survey');

        $survey_id = absint($atts['id']);
        $cf7_id = absint($atts['cf7']);
        $bundle = mc_leads_engine_survey_repository()->get_survey_bundle($survey_id);

        if (!$bundle) {
            return '<div class="mc-leads-engine-notice">' . esc_html__('Survey not found.', 'mc-leads-engine') . '</div>';
        }

        $cf7_shortcode = '';
        foreach ($bundle['sections'] as $section) {
            foreach ($section['questions'] as $question) {
                if (!empty($question['type']) && $question['type'] === 'cf7' && !empty(trim($question['question_text'] ?? ''))) {
                    $cf7_shortcode = trim($question['question_text']);
                    break 2;
                }
            }
        }

        if (!$cf7_shortcode && $cf7_id) {
            $cf7_shortcode = '[contact-form-7 id="' . $cf7_id . '"]';
        }

        wp_enqueue_style('mc-leads-engine-frontend');
        wp_enqueue_script('mc-leads-engine-frontend');

        $session = mc_leads_engine_session();
        $session->maybe_start_session();

        // Handle thank you success screen
        if (isset($_GET['mc_leads_submitted']) && (int) $_GET['mc_leads_submitted'] === 1) {
            $session->clear_session();
            wp_enqueue_style('mc-leads-engine-frontend');
            return mc_leads_engine_render_template('thank-you.php', array(
                'survey_id' => $survey_id,
                'lead_id'   => absint($_GET['lead_id'] ?? 0),
            ));
        }

        $session->set_active_survey($survey_id);

        if ($session->mark_survey_started($survey_id)) {
            mc_leads_engine_leads_repository()->record_survey_start($survey_id);
        }

        $saved_data = $session->get_data();
        $saved_answers = !empty($saved_data['answers']) && is_array($saved_data['answers']) ? $saved_data['answers'] : array();
        $pricing = !empty($saved_data['pricing']) && is_array($saved_data['pricing']) ? $saved_data['pricing'] : array();
        if (empty($pricing)) {
            $pricing = mc_leads_engine_pricing_engine()->calculate_survey_price($saved_answers, $survey_id);
        }

        wp_localize_script(
            'mc-leads-engine-frontend',
            'MCLeadsEngine',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mc_leads_engine_frontend'),
                'surveyId' => $survey_id,
                'sessionId' => $session->get_session_id(),
                'mode' => $cf7_shortcode ? 'cf7' : 'standard',
            )
        );

        return mc_leads_engine_render_template('survey.php', array(
            'renderer' => $this,
            'bundle' => $bundle,
            'survey_id' => $survey_id,
            'cf7_id' => $cf7_id,
            'cf7_shortcode' => $cf7_shortcode,
            'session_id' => $session->get_session_id(),
            'saved_answers' => $saved_answers,
            'pricing' => $pricing,
            'settings' => mc_leads_engine_get_settings(),
            'mode' => $cf7_shortcode ? 'cf7' : 'standard',
            'current_step' => absint($saved_data['current_step'] ?? 1),
        ));
    }

    public function render_section($section, $survey, $context = array()) {
        return mc_leads_engine_render_template('section.php', array(
            'renderer' => $this,
            'section' => $section,
            'survey' => $survey,
            'context' => $context,
        ));
    }

    public function render_question($question, $context = array()) {
        return mc_leads_engine_render_template('question.php', array(
            'renderer' => $this,
            'question' => $question,
            'context' => $context,
        ));
    }

    public function render_final_step($survey, $context = array()) {
        return mc_leads_engine_render_template('final-step.php', array(
            'renderer' => $this,
            'survey' => $survey,
            'context' => $context,
        ));
    }

    public function ajax_save_progress() {
        check_ajax_referer('mc_leads_engine_frontend', 'nonce');

        $survey_id = absint($_POST['survey_id'] ?? 0);
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $current_step = max(1, absint($_POST['current_step'] ?? 1));
        $answers = isset($_POST['answers']) ? json_decode(wp_unslash($_POST['answers']), true) : array();
        $answers = mc_leads_engine_sanitize_recursive(is_array($answers) ? $answers : array());

        $pricing = mc_leads_engine_pricing_engine()->calculate_survey_price($answers, $survey_id);
        
        $session = mc_leads_engine_session();
        if ($session_id) {
            $session->set_session_id($session_id);
        } else {
            $session->maybe_start_session();
        }
        
        $session->save_progress($survey_id, $current_step, $answers, $pricing);
        mc_leads_engine_leads_repository()->record_step_progress($survey_id, $current_step);

        wp_send_json_success(array(
            'pricing' => $pricing,
            'current_step' => $current_step,
            'answers' => $answers,
        ));
    }

    public function handle_submit_survey() {
        $survey_id  = absint($_POST['survey_id'] ?? 0);
        $session_id = sanitize_text_field($_POST['mc_leads_engine_session_id'] ?? '');
        $nonce      = isset($_POST['mc_leads_engine_nonce']) ? sanitize_text_field(wp_unslash($_POST['mc_leads_engine_nonce'])) : '';
        $redirect_to = isset($_POST['mc_leads_engine_redirect_to']) ? esc_url_raw(wp_unslash($_POST['mc_leads_engine_redirect_to'])) : '';

        // Always verify nonce for security
        if (!$nonce || !wp_verify_nonce($nonce, 'mc_leads_engine_submit_survey')) {
            wp_die(esc_html__('Security check failed. Please refresh the page and try again.', 'mc-leads-engine'), '', array('response' => 403));
        }

        $session = mc_leads_engine_session();
        if ($session_id) {
            $session->set_session_id($session_id);
        } else {
            $session->maybe_start_session();
        }
        $session_data = $session->get_data();

        // Priority 1: JSON-encoded answers sent by JS via the hidden mc_answers_json field
        $answers = array();
        if (!empty($_POST['mc_answers_json'])) {
            $decoded = json_decode(sanitize_text_field(wp_unslash($_POST['mc_answers_json'])), true);
            if (is_array($decoded) && !empty($decoded)) {
                $answers = mc_leads_engine_sanitize_recursive($decoded);
            }
        }
        // Priority 2: PHP array format (legacy or custom forms)
        if (empty($answers) && !empty($_POST['mc_answers']) && is_array($_POST['mc_answers'])) {
            $answers = mc_leads_engine_sanitize_recursive(wp_unslash($_POST['mc_answers']));
        }
        // Priority 3: session-stored answers (AJAX pre-saves)
        if (empty($answers) && !empty($session_data['answers']) && is_array($session_data['answers'])) {
            $answers = $session_data['answers'];
        }

        if (!$survey_id) {
            $survey_id = absint($session_data['active_survey_id'] ?? 0);
        }

        if (!$survey_id) {
            wp_die(esc_html__('Unable to submit this survey.', 'mc-leads-engine'));
        }

        $pricing = mc_leads_engine_pricing_engine()->calculate_survey_price($answers, $survey_id);
        $lead_id = mc_leads_engine_leads_repository()->create_lead($survey_id, $session_id, $answers, $pricing);
        $session->set_lead_id($lead_id);

        $settings = mc_leads_engine_get_settings();

        if (!empty($settings['thank_you_url'])) {
            $base = esc_url_raw($settings['thank_you_url']);
        } elseif ($redirect_to) {
            $base = $redirect_to;
        } else {
            $base = wp_get_referer();
        }

        if (!$base) {
            $base = home_url('/');
        }

        $redirect = add_query_arg(array('mc_leads_submitted' => 1, 'lead_id' => $lead_id), $base);

        wp_safe_redirect($redirect);
        exit;
    }

    public function ajax_get_thank_you() {
        check_ajax_referer('mc_leads_engine_frontend', 'nonce');
        $survey_id = absint($_POST['survey_id'] ?? 0);
        $lead_id = absint($_POST['lead_id'] ?? 0);
        $base_url = esc_url_raw($_POST['base_url'] ?? '');
        
        $html = mc_leads_engine_render_template('thank-you.php', array(
            'survey_id' => $survey_id,
            'lead_id'   => $lead_id,
            'base_url'  => $base_url,
        ));
        
        wp_send_json_success(array('html' => $html));
    }

    public function ajax_submit_survey() {
        check_ajax_referer('mc_leads_engine_frontend', 'nonce');

        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $survey_id = absint($_POST['survey_id'] ?? 0);
        $answers = isset($_POST['answers']) ? json_decode(wp_unslash($_POST['answers']), true) : array();
        $answers = mc_leads_engine_sanitize_recursive(is_array($answers) ? $answers : array());

        if (!$survey_id) {
            wp_send_json_error(array('message' => esc_html__('Unable to submit this survey.', 'mc-leads-engine')));
        }
        
        $pricing = mc_leads_engine_pricing_engine()->calculate_survey_price($answers, $survey_id);
        $lead_id = mc_leads_engine_leads_repository()->create_lead($survey_id, $session_id, $answers, $pricing);
        
        $session = mc_leads_engine_session();
        if ($session_id) {
            $session->set_session_id($session_id);
        } else {
            $session->maybe_start_session();
        }
        $session->set_lead_id($lead_id);
        $session->clear_session(); // clear session on successful submit
        
        $base_url = esc_url_raw($_POST['base_url'] ?? '');
        $html = mc_leads_engine_render_template('thank-you.php', array(
            'survey_id' => $survey_id,
            'lead_id'   => $lead_id,
            'base_url'  => $base_url,
        ));
        
        wp_send_json_success(array('html' => $html));
    }
}
