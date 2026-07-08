<?php

if (!defined('ABSPATH')) {
    exit;
}

class MC_Leads_Engine_Question {
    public function get_questions($section_id) {
        global $wpdb;

        $section_id = absint($section_id);
        if (!$section_id) {
            return array();
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . mc_leads_engine_table('survey_questions') . " WHERE section_id = %d ORDER BY order_index ASC, id ASC",
                $section_id
            ),
            ARRAY_A
        );
    }

    public function get_question($question_id) {
        global $wpdb;

        $question_id = absint($question_id);
        if (!$question_id) {
            return null;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . mc_leads_engine_table('survey_questions') . " WHERE id = %d",
                $question_id
            ),
            ARRAY_A
        );
    }

    public function get_options($question_id) {
        global $wpdb;

        $question_id = absint($question_id);
        if (!$question_id) {
            return array();
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . mc_leads_engine_table('question_options') . " WHERE question_id = %d ORDER BY order_index ASC, id ASC",
                $question_id
            ),
            ARRAY_A
        );
    }

    public function save_question($data) {
        global $wpdb;

        $payload = array(
            'section_id' => absint($data['section_id'] ?? 0),
            'question_text' => sanitize_textarea_field(wp_unslash($data['question_text'] ?? '')),
            'type' => sanitize_key(wp_unslash($data['type'] ?? 'text')),
            'required' => !empty($data['required']) ? 1 : 0,
            'order_index' => absint($data['order_index'] ?? 0),
        );

        $question_id = absint($data['id'] ?? 0);

        if ($question_id) {
            $wpdb->update(
                mc_leads_engine_table('survey_questions'),
                $payload,
                array('id' => $question_id),
                array('%d', '%s', '%s', '%d', '%d'),
                array('%d')
            );
        } else {
            $wpdb->insert(
                mc_leads_engine_table('survey_questions'),
                $payload,
                array('%d', '%s', '%s', '%d', '%d')
            );
            $question_id = (int) $wpdb->insert_id;
        }

        if ($question_id) {
            $this->save_options($question_id, $data['options'] ?? array());
        }

        return $question_id;
    }

    public function save_options($question_id, $options) {
        global $wpdb;

        $question_id = absint($question_id);
        if (!$question_id) {
            return false;
        }

        $wpdb->delete(mc_leads_engine_table('question_options'), array('question_id' => $question_id), array('%d'));

        if (!is_array($options)) {
            return true;
        }

        $order = 0;
        foreach ($options as $option) {
            $label = sanitize_text_field(wp_unslash($option['label'] ?? ''));
            $value = sanitize_text_field(wp_unslash($option['value'] ?? ''));
            if ($label === '' && $value === '') {
                continue;
            }

            $wpdb->insert(
                mc_leads_engine_table('question_options'),
                array(
                    'question_id'  => $question_id,
                    'label'        => $label ?: $value,
                    'value'        => $value ?: $label,
                    'description'  => isset($option['description']) ? sanitize_textarea_field(wp_unslash($option['description'])) : '',
                    'price_impact' => isset($option['price_impact']) ? (float) wp_unslash($option['price_impact']) : 0,
                    'score_impact' => isset($option['score_impact']) ? (int) wp_unslash($option['score_impact']) : 0,
                    'order_index'  => isset($option['order_index']) ? absint(wp_unslash($option['order_index'])) : $order,
                ),
                array('%d', '%s', '%s', '%s', '%f', '%d', '%d')
            );
            $order++;
        }

        return true;
    }

    public function delete_question($question_id) {
        global $wpdb;

        $question_id = absint($question_id);
        if (!$question_id) {
            return false;
        }

        $wpdb->delete(mc_leads_engine_table('question_options'), array('question_id' => $question_id), array('%d'));
        $wpdb->delete(mc_leads_engine_table('survey_questions'), array('id' => $question_id), array('%d'));

        return true;
    }

    public function delete_by_section($section_id) {
        global $wpdb;

        $section_id = absint($section_id);
        if (!$section_id) {
            return false;
        }

        $question_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM " . mc_leads_engine_table('survey_questions') . " WHERE section_id = %d",
                $section_id
            )
        );

        if ($question_ids) {
            foreach ($question_ids as $question_id) {
                $this->delete_question($question_id);
            }
        }

        return true;
    }
}
