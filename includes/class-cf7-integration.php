<?php

if (!defined('ABSPATH')) {
    exit;
}

class MC_Leads_Engine_CF7_Integration {
    private $last_created_lead_id = 0;

    public function __construct() {
        add_action('wpcf7_before_send_mail', array($this, 'capture_submission'));
        add_filter('wpcf7_ajax_json_echo', array($this, 'add_lead_id_to_ajax_response'), 10, 2);
    }

    public function get_integration_by_survey($survey_id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . mc_leads_engine_table('cf7_integrations') . " WHERE survey_id = %d ORDER BY id DESC LIMIT 1",
                absint($survey_id)
            ),
            ARRAY_A
        );
    }

    public function get_integration_by_form($cf7_form_id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . mc_leads_engine_table('cf7_integrations') . " WHERE cf7_form_id = %d ORDER BY id DESC LIMIT 1",
                absint($cf7_form_id)
            ),
            ARRAY_A
        );
    }

    public function save_integration($survey_id, $cf7_form_id, $mapping_rules = array()) {
        global $wpdb;

        $survey_id = absint($survey_id);
        $cf7_form_id = absint($cf7_form_id);
        if (!$survey_id || !$cf7_form_id) {
            return false;
        }

        $existing = $this->get_integration_by_survey($survey_id);
        $payload = array(
            'survey_id' => $survey_id,
            'cf7_form_id' => $cf7_form_id,
            'mapping_rules' => wp_json_encode($mapping_rules),
        );

        if ($existing) {
            $wpdb->update(
                mc_leads_engine_table('cf7_integrations'),
                $payload,
                array('id' => absint($existing['id'])),
                array('%d', '%d', '%s'),
                array('%d')
            );

            return (int) $existing['id'];
        }

        $wpdb->insert(
            mc_leads_engine_table('cf7_integrations'),
            $payload,
            array('%d', '%d', '%s')
        );

        return (int) $wpdb->insert_id;
    }

    public function delete_integration($survey_id) {
        global $wpdb;

        return (bool) $wpdb->delete(mc_leads_engine_table('cf7_integrations'), array('survey_id' => absint($survey_id)), array('%d'));
    }

    public function capture_submission($contact_form) {
        if (!class_exists('WPCF7_Submission')) {
            return;
        }

        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
            return;
        }

        $posted_data = $submission->get_posted_data();
        if (!is_array($posted_data)) {
            return;
        }

        $cf7_form_id = method_exists($contact_form, 'id') ? absint($contact_form->id()) : 0;
        $integration = $cf7_form_id ? $this->get_integration_by_form($cf7_form_id) : null;

        $session_id = '';
        if (!empty($posted_data['mc_leads_session_id'])) {
            $session_id = sanitize_text_field($posted_data['mc_leads_session_id']);
        } elseif (!empty($_COOKIE[MC_Leads_Engine_Session::COOKIE_NAME])) {
            $session_id = sanitize_text_field(wp_unslash($_COOKIE[MC_Leads_Engine_Session::COOKIE_NAME]));
        }

        if (!$session_id) {
            return;
        }

        $session = mc_leads_engine_session();
        $session->set_session_id($session_id);
        $session_data = $session->get_data();
        $survey_id = !empty($session_data['active_survey_id']) ? absint($session_data['active_survey_id']) : absint($integration['survey_id'] ?? 0);

        if (!$survey_id) {
            return;
        }

        $answers = !empty($session_data['answers']) && is_array($session_data['answers']) ? $session_data['answers'] : array();
        $pricing = mc_leads_engine_pricing_engine()->calculate_survey_price($answers, $survey_id);
        $cf7_data = $this->sanitize_cf7_payload($posted_data);
        $cf7_data['cf7_form_id'] = $cf7_form_id;
        $cf7_data['survey_data'] = $answers;
        $cf7_data['pricing'] = $pricing;

        // Detect booking submission early so we can delay notifications until
        // after save_booking() has written the booking row to the database.
        $is_booking_submission = !empty($posted_data['mc_booking_type']);

        $lead_id = mc_leads_engine_leads_repository()->create_lead(
            $survey_id,
            $session_id,
            $answers,
            $pricing,
            $cf7_data,
            $is_booking_submission  // skip_notifications = true for bookings
        );

        // Capture booking details if present in WPCF7 submission
        if ($is_booking_submission) {
            $booking_data = array(
                'meeting_type'     => sanitize_text_field($posted_data['mc_booking_type']),
                'location_type'    => sanitize_text_field($posted_data['mc_booking_location_type'] ?? 'custom'),
                'location_name'    => sanitize_text_field($posted_data['mc_booking_location_name'] ?? ''),
                'location_address' => sanitize_text_field($posted_data['mc_booking_location_address'] ?? ''),
                'meeting_date'     => sanitize_text_field($posted_data['mc_booking_date'] ?? ''),
                'meeting_time'     => sanitize_text_field($posted_data['mc_booking_time'] ?? ''),
                'client_name'      => mc_leads_engine_leads_repository()->find_client_name($lead_id),
                'client_email'     => mc_leads_engine_leads_repository()->find_client_email($lead_id),
                'client_phone'     => mc_leads_engine_leads_repository()->find_client_phone($lead_id),
                'client_message'   => sanitize_textarea_field($posted_data['your-message'] ?? $posted_data['message'] ?? ''),
            );

            mc_leads_engine_booking()->save_booking($lead_id, $booking_data);

            // Now that the booking row exists, fire notifications with the correct
            // booking templates (is_booking check inside will now return true).
            mc_leads_engine_leads_repository()->send_submission_notifications($lead_id);
        }

        $session->set_lead_id($lead_id);
        $this->last_created_lead_id = $lead_id;
    }

    public function add_lead_id_to_ajax_response($response, $result) {
        if ($this->last_created_lead_id) {
            $response['mc_lead_id'] = $this->last_created_lead_id;
        }
        return $response;
    }

    protected function sanitize_cf7_payload($posted_data) {
        $clean = array();

        foreach ($posted_data as $key => $value) {
            if (strpos((string) $key, '_wpcf7') === 0 || strpos((string) $key, '_wpnonce') === 0) {
                continue;
            }

            $clean[sanitize_key($key)] = mc_leads_engine_sanitize_recursive($value);
        }

        return $clean;
    }
}
