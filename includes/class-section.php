<?php

if (!defined('ABSPATH')) {
    exit;
}

class MC_Leads_Engine_Section {
    public function get_sections($survey_id) {
        global $wpdb;

        $survey_id = absint($survey_id);
        if (!$survey_id) {
            return array();
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . mc_leads_engine_table('survey_sections') . " WHERE survey_id = %d ORDER BY order_index ASC, id ASC",
                $survey_id
            ),
            ARRAY_A
        );
    }

    public function get_section($section_id) {
        global $wpdb;

        $section_id = absint($section_id);
        if (!$section_id) {
            return null;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . mc_leads_engine_table('survey_sections') . " WHERE id = %d",
                $section_id
            ),
            ARRAY_A
        );
    }

    public function save_section($data) {
        global $wpdb;

        $payload = array(
            'survey_id' => absint($data['survey_id'] ?? 0),
            'title' => sanitize_text_field(wp_unslash($data['title'] ?? '')),
            'description' => wp_kses_post(wp_unslash($data['description'] ?? '')),
            'order_index' => absint($data['order_index'] ?? 0),
        );

        $section_id = absint($data['id'] ?? 0);

        if ($section_id) {
            $wpdb->update(
                mc_leads_engine_table('survey_sections'),
                $payload,
                array('id' => $section_id),
                array('%d', '%s', '%s', '%d'),
                array('%d')
            );

            return $section_id;
        }

        $payload['survey_id'] = absint($payload['survey_id']);
        $wpdb->insert(
            mc_leads_engine_table('survey_sections'),
            $payload,
            array('%d', '%s', '%s', '%d')
        );

        return (int) $wpdb->insert_id;
    }

    public function delete_section($section_id) {
        global $wpdb;

        $section_id = absint($section_id);
        if (!$section_id) {
            return false;
        }

        $question_repo = new MC_Leads_Engine_Question();
        $question_repo->delete_by_section($section_id);

        $wpdb->delete(mc_leads_engine_table('survey_sections'), array('id' => $section_id), array('%d'));

        return true;
    }
}
