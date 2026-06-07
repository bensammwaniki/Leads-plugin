<?php

if (!defined('ABSPATH')) {
    exit;
}

class MC_Leads_Engine_Survey {
    public function get_surveys($args = array()) {
        global $wpdb;

        $defaults = array(
            'status' => '',
            'limit' => 50,
            'offset' => 0,
        );
        $args = wp_parse_args($args, $defaults);

        $where = '1=1';
        $params = array();

        if (!empty($args['status'])) {
            $where .= ' AND status = %s';
            $params[] = sanitize_key($args['status']);
        }

        $limit = absint($args['limit']);
        $offset = absint($args['offset']);
        $sql = "SELECT * FROM " . mc_leads_engine_table('surveys') . " WHERE {$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";

        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }

    public function get_survey($survey_id) {
        global $wpdb;

        $survey_id = absint($survey_id);
        if (!$survey_id) {
            return null;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . mc_leads_engine_table('surveys') . " WHERE id = %d",
                $survey_id
            ),
            ARRAY_A
        );
    }

    public function create_survey($data) {
        global $wpdb;

        $inserted = $wpdb->insert(
            mc_leads_engine_table('surveys'),
            array(
                'title' => sanitize_text_field(wp_unslash($data['title'] ?? '')),
                'description' => wp_kses_post(wp_unslash($data['description'] ?? '')),
                'status' => sanitize_key(wp_unslash($data['status'] ?? 'draft')),
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s')
        );

        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    public function update_survey($survey_id, $data) {
        global $wpdb;

        $survey_id = absint($survey_id);
        if (!$survey_id) {
            return false;
        }

        return (bool) $wpdb->update(
            mc_leads_engine_table('surveys'),
            array(
                'title' => sanitize_text_field(wp_unslash($data['title'] ?? '')),
                'description' => wp_kses_post(wp_unslash($data['description'] ?? '')),
                'status' => sanitize_key(wp_unslash($data['status'] ?? 'draft')),
            ),
            array('id' => $survey_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }

    public function delete_survey($survey_id) {
        global $wpdb;

        $survey_id = absint($survey_id);
        if (!$survey_id) {
            return false;
        }

        $section_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM " . mc_leads_engine_table('survey_sections') . " WHERE survey_id = %d",
                $survey_id
            )
        );

        if ($section_ids) {
            $question_repo = new MC_Leads_Engine_Question();
            foreach ($section_ids as $section_id) {
                $question_repo->delete_by_section($section_id);
            }
        }

        $wpdb->delete(mc_leads_engine_table('survey_sections'), array('survey_id' => $survey_id), array('%d'));
        $wpdb->delete(mc_leads_engine_table('cf7_integrations'), array('survey_id' => $survey_id), array('%d'));
        $wpdb->delete(mc_leads_engine_table('surveys'), array('id' => $survey_id), array('%d'));

        return true;
    }

    public function get_survey_bundle($survey_id) {
        $survey = $this->get_survey($survey_id);

        if (!$survey) {
            return null;
        }

        $section_repo = new MC_Leads_Engine_Section();
        $question_repo = new MC_Leads_Engine_Question();

        $sections = $section_repo->get_sections($survey_id);
        foreach ($sections as &$section) {
            $questions = $question_repo->get_questions($section['id']);
            foreach ($questions as &$question) {
                $question['options'] = $question_repo->get_options($question['id']);
            }
            $section['questions'] = $questions;
        }

        return array(
            'survey' => $survey,
            'sections' => $sections,
        );
    }
}
