<?php

if (!defined('ABSPATH')) {
    exit;
}

class MC_Leads_Engine_Leads {
    public function create_lead($survey_id, $session_id, $answers, $pricing, $cf7_data = array(), $skip_notifications = false) {
        global $wpdb;

        $survey_id = absint($survey_id);
        $session_id = sanitize_text_field($session_id);
        $answers = is_array($answers) ? $answers : array();
        $pricing = is_array($pricing) ? $pricing : array();
        $cf7_data = is_array($cf7_data) ? $cf7_data : array();

        $existing = $this->find_lead_by_session($session_id, $survey_id);
        
        $payload = array(
            'survey_id' => $survey_id,
            'session_id' => $session_id,
            'total_price' => isset($pricing['total_price']) ? (float) $pricing['total_price'] : 0,
            'lead_score' => isset($pricing['lead_score']) ? (int) $pricing['lead_score'] : 0,
            'answers_json' => wp_json_encode($answers),
            'pricing_json' => wp_json_encode($pricing),
        );

        // Check if passive tracking and consent are allowed
        $tracking_enabled = false;
        $consent_json = '';

        $settings = mc_leads_engine_get_settings();
        if (empty($settings['cookie_banner_enable'])) {
            $tracking_enabled = true;
            $consent_json = wp_json_encode(array('analytics' => true, 'marketing' => true));
        } elseif (!empty($_COOKIE['mc_leads_consent'])) {
            $consent_json = wp_unslash($_COOKIE['mc_leads_consent']);
            $consent = json_decode($consent_json, true);
            if (is_array($consent) && (!empty($consent['analytics']) || !empty($consent['marketing']))) {
                $tracking_enabled = true;
            }
        }

        if ($tracking_enabled) {
            $payload['utm_source']   = sanitize_text_field(wp_unslash($_COOKIE['mc_leads_utm_source'] ?? ''));
            $payload['utm_medium']   = sanitize_text_field(wp_unslash($_COOKIE['mc_leads_utm_medium'] ?? ''));
            $payload['utm_campaign'] = sanitize_text_field(wp_unslash($_COOKIE['mc_leads_utm_campaign'] ?? ''));
            $payload['utm_term']     = sanitize_text_field(wp_unslash($_COOKIE['mc_leads_utm_term'] ?? ''));
            $payload['utm_content']  = sanitize_text_field(wp_unslash($_COOKIE['mc_leads_utm_content'] ?? ''));
            $payload['referrer']     = esc_url_raw(wp_unslash($_COOKIE['mc_leads_referrer'] ?? ''));
            $payload['ip_address']   = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
            $payload['user_agent']   = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
            $payload['ga_client_id'] = sanitize_text_field(wp_unslash($_COOKIE['mc_leads_ga_client_id'] ?? ''));
            $payload['consent_state'] = sanitize_textarea_field($consent_json);
        } else {
            $payload['utm_source']   = null;
            $payload['utm_medium']   = null;
            $payload['utm_campaign'] = null;
            $payload['utm_term']     = null;
            $payload['utm_content']  = null;
            $payload['referrer']     = null;
            $payload['ip_address']   = null;
            $payload['user_agent']   = null;
            $payload['ga_client_id'] = null;
            $payload['consent_state'] = sanitize_textarea_field($consent_json);
        }

        $payload['created_at'] = current_time('mysql');

        $is_new = !$existing;

        $format_array = array(
            '%d', // survey_id
            '%s', // session_id
            '%f', // total_price
            '%d', // lead_score
            '%s', // answers_json
            '%s', // pricing_json
            '%s', // utm_source
            '%s', // utm_medium
            '%s', // utm_campaign
            '%s', // utm_term
            '%s', // utm_content
            '%s', // referrer
            '%s', // ip_address
            '%s', // user_agent
            '%s', // ga_client_id
            '%s', // consent_state
            '%s', // created_at
        );

        if ($existing) {
            $lead_id = (int) $existing['id'];
            $wpdb->update(
                mc_leads_engine_table('leads'),
                $payload,
                array('id' => $lead_id),
                $format_array,
                array('%d')
            );
            $wpdb->delete(mc_leads_engine_table('lead_answers'), array('lead_id' => $lead_id), array('%d'));
        } else {
            $wpdb->insert(
                mc_leads_engine_table('leads'),
                $payload,
                $format_array
            );
            $lead_id = (int) $wpdb->insert_id;
        }

        $this->save_answers($lead_id, $answers);
        if (!empty($cf7_data)) {
            $this->save_cf7_data($lead_id, absint($cf7_data['cf7_form_id'] ?? 0), $cf7_data);
        }

        if ($is_new) {
            $this->record_lead_metrics($survey_id, $payload['total_price'], $payload['lead_score']);
            // Invalidate cached analytics transients so new data shows immediately
            delete_transient('mc_leads_answer_freq_0');
            delete_transient('mc_leads_answer_freq_' . $survey_id);
            delete_transient('mc_leads_utm_attr_30');
            delete_transient('mc_leads_utm_attr_0');
        }

        // Only trigger notifications here for non-booking leads.
        // Booking leads call send_submission_notifications() themselves AFTER
        // save_booking() has written the booking row — otherwise is_booking is
        // always false and the wrong (survey estimate) email gets sent.
        if (!$skip_notifications) {
            $this->send_submission_notifications($lead_id);
        }

        return $lead_id;
    }

    public function find_lead_by_session($session_id, $survey_id) {
        global $wpdb;

        $session_id = sanitize_text_field($session_id);
        $survey_id = absint($survey_id);
        if (!$session_id || !$survey_id) {
            return null;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . mc_leads_engine_table('leads') . " WHERE session_id = %s AND survey_id = %d ORDER BY id DESC LIMIT 1",
                $session_id,
                $survey_id
            ),
            ARRAY_A
        );
    }

    /**
     * Update the pipeline status of a lead.
     *
     * @param int    $lead_id
     * @param string $status  One of: new, contacted, qualified, proposal_sent, won, lost
     * @param string $notes   Optional internal note about the status change
     * @return bool
     */
    public function update_lead_status($lead_id, $status, $notes = '') {
        global $wpdb;

        $lead_id  = absint($lead_id);
        $allowed  = mc_leads_get_statuses();
        $status   = in_array($status, $allowed, true) ? $status : 'new';
        $notes    = sanitize_textarea_field($notes);

        if (!$lead_id) {
            return false;
        }

        $result = (bool) $wpdb->update(
            mc_leads_engine_table('leads'),
            array(
                'status'            => $status,
                'status_notes'      => $notes,
                'status_updated_at' => current_time('mysql'),
            ),
            array('id' => $lead_id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        if ($result) {
            // Log to activity trail
            $label = mc_leads_status_label($status);
            $body  = sprintf(__('Status changed to: %s', 'mc-leads-engine'), $label);
            if ($notes) {
                $body .= ' — ' . $notes;
            }
            MC_Leads_Activity::log($lead_id, 'status_change', $body, get_current_user_id());
        }

        return $result;
    }

    public function get_leads($args = array()) {
        global $wpdb;

        $defaults = array(
            'survey_id' => 0,
            'status'    => '',
            'min_score' => 0,
            'search'    => '',
            'limit'     => 50,
            'offset'    => 0,
            'orderby'   => 'created_at',
            'order'     => 'DESC',
        );
        $args = wp_parse_args($args, $defaults);

        $where  = '1=1';
        $params = array();

        if (!empty($args['survey_id'])) {
            $where   .= ' AND survey_id = %d';
            $params[] = absint($args['survey_id']);
        }

        if (!empty($args['status']) && $args['status'] !== 'all') {
            $where   .= ' AND status = %s';
            $params[] = sanitize_key($args['status']);
        }

        if (!empty($args['min_score'])) {
            $where   .= ' AND lead_score >= %d';
            $params[] = absint($args['min_score']);
        }

        if (!empty($args['search'])) {
            $s = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
            // Search inside CF7 data_json (stored in lead_cf7_data table)
            $cf7_table = mc_leads_engine_table('lead_cf7_data');
            $where .= " AND (EXISTS (
                SELECT 1 FROM {$cf7_table} cf7
                WHERE cf7.lead_id = id AND cf7.data_json LIKE %s
            ))";
            $params[] = $s;
        }

        $orderby = in_array($args['orderby'], array('created_at', 'lead_score', 'total_price', 'id'), true) ? $args['orderby'] : 'created_at';
        $order   = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $limit   = absint($args['limit']);
        $offset  = absint($args['offset']);

        $sql = "SELECT * FROM " . mc_leads_engine_table('leads') . " WHERE {$where} ORDER BY {$orderby} {$order} LIMIT {$limit} OFFSET {$offset}";
        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }

        return $wpdb->get_results($sql, ARRAY_A) ?: array();
    }

    /**
     * Count leads matching the same criteria as get_leads() (without limit/offset/order).
     * Used for pagination.
     *
     * @param array $args  Same keys as get_leads() minus limit/offset/orderby/order
     * @return int
     */
    public function count_leads($args = array()) {
        global $wpdb;

        $defaults = array(
            'survey_id' => 0,
            'status'    => '',
            'min_score' => 0,
            'search'    => '',
        );
        $args = wp_parse_args($args, $defaults);

        $where  = '1=1';
        $params = array();

        if (!empty($args['survey_id'])) {
            $where   .= ' AND survey_id = %d';
            $params[] = absint($args['survey_id']);
        }

        if (!empty($args['status']) && $args['status'] !== 'all') {
            $where   .= ' AND status = %s';
            $params[] = sanitize_key($args['status']);
        }

        if (!empty($args['min_score'])) {
            $where   .= ' AND lead_score >= %d';
            $params[] = absint($args['min_score']);
        }

        if (!empty($args['search'])) {
            $s = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
            $cf7_table = mc_leads_engine_table('lead_cf7_data');
            $where .= " AND (EXISTS (
                SELECT 1 FROM {$cf7_table} cf7
                WHERE cf7.lead_id = id AND cf7.data_json LIKE %s
            ))";
            $params[] = $s;
        }

        $sql = "SELECT COUNT(*) FROM " . mc_leads_engine_table('leads') . " WHERE {$where}";
        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }

        return (int) $wpdb->get_var($sql);
    }

    public function get_lead($lead_id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . mc_leads_engine_table('leads') . " WHERE id = %d",
                absint($lead_id)
            ),
            ARRAY_A
        );
    }

    public function get_lead_answers($lead_id) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . mc_leads_engine_table('lead_answers') . " WHERE lead_id = %d ORDER BY id ASC",
                absint($lead_id)
            ),
            ARRAY_A
        );
    }

    public function get_cf7_data($lead_id) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . mc_leads_engine_table('lead_cf7_data') . " WHERE lead_id = %d ORDER BY id DESC",
                absint($lead_id)
            ),
            ARRAY_A
        );
    }

    public function save_cf7_data($lead_id, $cf7_form_id, $data) {
        global $wpdb;

        $lead_id = absint($lead_id);
        $cf7_form_id = absint($cf7_form_id);
        if (!$lead_id || !$cf7_form_id) {
            return false;
        }

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM " . mc_leads_engine_table('lead_cf7_data') . " WHERE lead_id = %d AND cf7_form_id = %d",
                $lead_id,
                $cf7_form_id
            )
        );

        $payload = array(
            'lead_id' => $lead_id,
            'cf7_form_id' => $cf7_form_id,
            'data_json' => wp_json_encode($data),
        );

        if ($existing) {
            $wpdb->update(
                mc_leads_engine_table('lead_cf7_data'),
                $payload,
                array('id' => absint($existing)),
                array('%d', '%d', '%s'),
                array('%d')
            );
            return true;
        }

        $wpdb->insert(
            mc_leads_engine_table('lead_cf7_data'),
            $payload,
            array('%d', '%d', '%s')
        );

        return (bool) $wpdb->insert_id;
    }

    public function get_dashboard_metrics() {
        global $wpdb;

        $total_leads = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . mc_leads_engine_table('leads'));
        $revenue     = (float) $wpdb->get_var("SELECT COALESCE(SUM(total_price), 0) FROM " . mc_leads_engine_table('leads'));
        $avg_value   = $total_leads ? round($revenue / $total_leads, 2) : 0;

        // Count survey starts from the new step_events table
        $starts = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . mc_leads_engine_table('step_events') . " WHERE event_type = 'start'"
        );

        $conversion_rate = $starts ? round(($total_leads / max(1, $starts)) * 100, 2) : 0;

        // Keep legacy analytics option for record_lead_metrics compatibility
        $analytics = get_option('mc_leads_engine_analytics', array());

        return array(
            'total_leads'     => $total_leads,
            'revenue'         => $revenue,
            'avg_value'       => $avg_value,
            'conversion_rate' => $conversion_rate,
            'survey_starts'   => $starts,
            'analytics'       => $analytics,
        );
    }

    /**
     * Returns a count of leads per pipeline status in one query.
     * Result is cached for 5 minutes — cheap enough for a dashboard.
     *
     * @return array  e.g. ['new' => 12, 'contacted' => 5, 'won' => 3, ...]
     */
    public function get_pipeline_counts() {
        $cached = get_transient('mc_leads_pipeline_counts');
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT COALESCE(status, 'new') AS status, COUNT(*) AS cnt
             FROM " . mc_leads_engine_table('leads') . "
             GROUP BY status",
            ARRAY_A
        );

        $counts = array(
            'new'           => 0,
            'contacted'     => 0,
            'qualified'     => 0,
            'proposal_sent' => 0,
            'won'           => 0,
            'lost'          => 0,
        );
        foreach ((array) $rows as $row) {
            $s = sanitize_key($row['status'] ?? 'new');
            if (isset($counts[$s])) {
                $counts[$s] = (int) $row['cnt'];
            }
        }

        set_transient('mc_leads_pipeline_counts', $counts, 5 * MINUTE_IN_SECONDS);
        return $counts;
    }

    public function record_survey_start($survey_id) {
        global $wpdb;

        $session = mc_leads_engine_session();
        $session_id = $session->get_session_id();
        $survey_id  = absint($survey_id);

        // Avoid duplicate start events for the same session+survey
        $existing = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM " . mc_leads_engine_table('step_events') .
                " WHERE event_type = 'start' AND survey_id = %d AND session_id = %s",
                $survey_id,
                $session_id
            )
        );

        if ($existing) {
            return;
        }

        $wpdb->insert(
            mc_leads_engine_table('step_events'),
            array(
                'survey_id'  => $survey_id,
                'event_type' => 'start',
                'step'       => 1,
                'session_id' => $session_id,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%d', '%s', '%s')
        );
    }

    public function record_step_progress($survey_id, $step) {
        global $wpdb;

        $session    = mc_leads_engine_session();
        $session_id = $session->get_session_id();
        $survey_id  = absint($survey_id);
        $step       = max(1, absint($step));

        $wpdb->insert(
            mc_leads_engine_table('step_events'),
            array(
                'survey_id'  => $survey_id,
                'event_type' => 'step',
                'step'       => $step,
                'session_id' => $session_id,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%d', '%s', '%s')
        );
    }

    public function get_step_dropoff($survey_id = 0, $days = 0) {
        global $wpdb;

        $where  = "WHERE event_type = 'step'";
        $params = array();

        if ($survey_id) {
            $where   .= ' AND survey_id = %d';
            $params[] = absint($survey_id);
        }

        if ($days > 0) {
            $where   .= ' AND created_at >= %s';
            $params[] = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
        }

        $sql = "SELECT survey_id, step, COUNT(*) as cnt
                FROM " . mc_leads_engine_table('step_events') . "
                {$where}
                GROUP BY survey_id, step
                ORDER BY survey_id ASC, step ASC";

        if ($params) {
            $sql = $wpdb->prepare($sql, $params); // phpcs:ignore
        }

        $rows   = $wpdb->get_results($sql, ARRAY_A);
        $result = array();

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $sid  = (int) $row['survey_id'];
                $stp  = (int) $row['step'];
                $cnt  = (int) $row['cnt'];
                $result[$sid][$stp] = $cnt;
            }
        }

        return $result;
    }

    public function record_lead_metrics($survey_id, $price, $score) {
        $analytics = get_option('mc_leads_engine_analytics', array());
        if (!isset($analytics['survey_completions']) || !is_array($analytics['survey_completions'])) {
            $analytics['survey_completions'] = array();
        }
        if (!isset($analytics['revenue_by_survey']) || !is_array($analytics['revenue_by_survey'])) {
            $analytics['revenue_by_survey'] = array();
        }
        if (!isset($analytics['score_by_survey']) || !is_array($analytics['score_by_survey'])) {
            $analytics['score_by_survey'] = array();
        }

        $survey_id = absint($survey_id);
        $analytics['survey_completions'][$survey_id] = isset($analytics['survey_completions'][$survey_id]) ? (int) $analytics['survey_completions'][$survey_id] + 1 : 1;
        $analytics['revenue_by_survey'][$survey_id] = isset($analytics['revenue_by_survey'][$survey_id]) ? (float) $analytics['revenue_by_survey'][$survey_id] + (float) $price : (float) $price;
        $analytics['score_by_survey'][$survey_id] = isset($analytics['score_by_survey'][$survey_id]) ? (int) $analytics['score_by_survey'][$survey_id] + (int) $score : (int) $score;

        update_option('mc_leads_engine_analytics', $analytics);
    }

    public function export_rows($args = array()) {
        $leads = $this->get_leads($args);
        $rows = array();

        foreach ($leads as $lead) {
            $lead['answers'] = $this->get_lead_answers($lead['id']);
            $lead['cf7'] = $this->get_cf7_data($lead['id']);
            $rows[] = $lead;
        }

        return $rows;
    }

    /**
     * Build a flat list of answer summary items for display or export.
     * Replaces the duplicated parsing code that was in admin-analytics.php
     * and admin-leads.php.
     *
     * @param array $lead         Full lead row (must include 'answers_json' and 'id').
     * @param array $questions_map Map of question_id => question_text.
     * @param bool  $is_html      If true, wraps items in <strong> for HTML output.
     * @return array  Flat array of formatted strings like "Question: Answer"
     */
    public function build_answers_summary($lead, $questions_map = array(), $is_html = false) {
        $list_items = array();

        // 1. Survey answers from answers_json
        $answers = json_decode($lead['answers_json'] ?? '[]', true);
        if (is_array($answers)) {
            foreach ($answers as $q_id => $val) {
                $q_text  = $questions_map[(int) $q_id] ?? sprintf(__('Question #%d', 'mc-leads-engine'), $q_id);
                $val_str = is_array($val) ? implode(', ', $val) : (string) $val;
                if ($val_str !== '') {
                    $list_items[] = $is_html
                        ? '<strong>' . esc_html($q_text) . ':</strong> ' . esc_html($val_str)
                        : esc_html($q_text) . ': ' . esc_html($val_str);
                }
            }
        }

        // 2. CF7 non-contact fields
        $cf7_rows = isset($lead['cf7']) ? $lead['cf7'] : $this->get_cf7_data($lead['id']);
        if (!empty($cf7_rows)) {
            $cf7_data = json_decode($cf7_rows[0]['data_json'] ?? '{}', true);
            if (is_array($cf7_data)) {
                $skip_keys = array('cf7_form_id', 'mc_session_id', 'mc_survey_id', 'survey_data', 'pricing');
                foreach ($cf7_data as $key => $val) {
                    if (empty($val) || in_array($key, $skip_keys, true)) {
                        continue;
                    }
                    if ($key === 'mc_leads_session_id' || $key === 'mc_session_id') {
                        continue;
                    }
                    $val_str = is_array($val) ? implode(', ', $val) : (string) $val;
                    $list_items[] = $is_html
                        ? '<strong>' . esc_html($key) . ':</strong> ' . esc_html($val_str)
                        : esc_html($key) . ': ' . esc_html($val_str);
                }
            }
        }

        return $list_items;
    }

    public function get_daily_lead_stats($days = 14) {
        global $wpdb;

        $days = max(7, absint($days));
        $start_timestamp = current_time('timestamp') - (DAY_IN_SECONDS * ($days - 1));
        $start_date = wp_date('Y-m-d 00:00:00', $start_timestamp);
        $leads_table = mc_leads_engine_table('leads');

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(created_at) AS day, COUNT(*) AS lead_count, COALESCE(SUM(total_price), 0) AS revenue
                 FROM {$leads_table}
                 WHERE created_at >= %s
                 GROUP BY DATE(created_at)
                 ORDER BY day ASC",
                $start_date
            ),
            ARRAY_A
        );

        $series = array();
        for ($i = 0; $i < $days; $i++) {
            $timestamp = $start_timestamp + (DAY_IN_SECONDS * $i);
            $day_key = wp_date('Y-m-d', $timestamp);
            $series[$day_key] = array(
                'day'        => $day_key,
                'label'      => wp_date('M j', $timestamp),
                'lead_count' => 0,
                'revenue'    => 0,
            );
        }

        if (is_array($results)) {
            foreach ($results as $row) {
                $day_key = $row['day'];
                if (!isset($series[$day_key])) {
                    continue;
                }

                $series[$day_key]['lead_count'] = (int) $row['lead_count'];
                $series[$day_key]['revenue'] = (float) $row['revenue'];
            }
        }

        return array_values($series);
    }

    /**
     * Get the frequency of each answer option per question for a survey.
     * Results are cached for 1 hour via transients.
     *
     * @param int $survey_id  0 = all surveys
     * @return array  Keyed by question_id, each value is array of ['answer_value', 'cnt', 'question_text']
     */
    public function get_answer_frequency($survey_id = 0) {
        global $wpdb;

        $survey_id  = absint($survey_id);
        $cache_key  = 'mc_leads_answer_freq_' . $survey_id;
        $cached     = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $where  = '';
        $params = array();

        if ($survey_id) {
            $where    = 'WHERE s.survey_id = %d';
            $params[] = $survey_id;
        }

        $sql = "SELECT la.question_id, la.answer_value,
                       COUNT(*) AS cnt,
                       q.question_text
                FROM " . mc_leads_engine_table('lead_answers') . " la
                JOIN " . mc_leads_engine_table('survey_questions') . " q  ON q.id  = la.question_id
                JOIN " . mc_leads_engine_table('survey_sections')  . " s  ON s.id  = q.section_id
                {$where}
                GROUP BY la.question_id, la.answer_value
                ORDER BY la.question_id ASC, cnt DESC";

        if ($params) {
            $sql = $wpdb->prepare($sql, $params); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $rows   = $wpdb->get_results($sql, ARRAY_A);
        $result = array();

        foreach ($rows as $row) {
            $qid = (int) $row['question_id'];
            if (!isset($result[$qid])) {
                $result[$qid] = array(
                    'question_text' => $row['question_text'],
                    'options'       => array(),
                );
            }
            $result[$qid]['options'][] = array(
                'label' => $row['answer_value'],
                'count' => (int) $row['cnt'],
            );
        }

        set_transient($cache_key, $result, HOUR_IN_SECONDS);

        return $result;
    }

    /**
     * Get lead counts grouped by UTM source/medium/campaign.
     * Results are cached for 30 minutes.
     *
     * @param int $days  Look-back window in days (0 = all time)
     * @return array
     */
    public function get_utm_attribution($days = 30) {
        global $wpdb;

        $days      = absint($days);
        $cache_key = 'mc_leads_utm_attr_' . $days;
        $cached    = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $where  = "WHERE utm_source IS NOT NULL AND utm_source != ''";
        $params = array();

        if ($days > 0) {
            $where   .= ' AND created_at >= %s';
            $params[] = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
        }

        $sql = "SELECT utm_source, utm_medium, utm_campaign,
                       COUNT(*) AS lead_count,
                       ROUND(AVG(lead_score), 1) AS avg_score,
                       ROUND(AVG(total_price), 2) AS avg_value
                FROM " . mc_leads_engine_table('leads') . "
                {$where}
                GROUP BY utm_source, utm_medium, utm_campaign
                ORDER BY lead_count DESC
                LIMIT 20";

        if ($params) {
            $sql = $wpdb->prepare($sql, $params); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $result = $wpdb->get_results($sql, ARRAY_A) ?: array();

        set_transient($cache_key, $result, 30 * MINUTE_IN_SECONDS);

        return $result;
    }

    protected function save_answers($lead_id, $answers) {
        global $wpdb;

        $lead_id = absint($lead_id);
        if (!$lead_id || !is_array($answers)) {
            return false;
        }

        foreach ($answers as $question_id => $answer_value) {
            $wpdb->insert(
                mc_leads_engine_table('lead_answers'),
                array(
                    'lead_id' => $lead_id,
                    'question_id' => absint($question_id),
                    'answer_value' => is_array($answer_value) ? wp_json_encode($answer_value) : (string) $answer_value,
                ),
                array('%d', '%d', '%s')
            );
        }

        return true;
    }

    protected function increment_analytics_counter($bucket, $survey_id, $step) {
        $analytics = get_option('mc_leads_engine_analytics', array());
        if (!isset($analytics[$bucket]) || !is_array($analytics[$bucket])) {
            $analytics[$bucket] = array();
        }
        if (!isset($analytics[$bucket][$survey_id]) || !is_array($analytics[$bucket][$survey_id])) {
            $analytics[$bucket][$survey_id] = array();
        }
        if (!isset($analytics[$bucket][$survey_id][$step])) {
            $analytics[$bucket][$survey_id][$step] = 0;
        }

        $analytics[$bucket][$survey_id][$step]++;
        update_option('mc_leads_engine_analytics', $analytics);
    }

    protected function sum_nested_counter($values) {
        $total = 0;

        foreach ($values as $value) {
            if (is_array($value)) {
                $total += $this->sum_nested_counter($value);
            } else {
                $total += (int) $value;
            }
        }

        return $total;
    }

    public function send_submission_notifications($lead_id) {
        $settings = mc_leads_engine_get_settings();
        $headers = array('Content-Type: text/html; charset=UTF-8');

        // Check if there is an associated booking to determine if this is a booking lead
        global $wpdb;
        $is_booking = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . mc_leads_engine_table('bookings') . " WHERE lead_id = %d",
            absint($lead_id)
        ));

        // 1. User Email notification
        $client_email = $this->find_client_email($lead_id);
        if (!empty($client_email) && is_email($client_email)) {
            $subject_key = $is_booking ? 'booking_user_email_subject' : 'user_email_subject';
            $body_key = $is_booking ? 'booking_user_email_body' : 'user_email_body';

            $user_subject = $this->parse_message_placeholders($settings[$subject_key] ?? '', $lead_id, false);
            $user_body = $this->parse_message_placeholders($settings[$body_key] ?? '', $lead_id, true);
            
            if (!empty($user_subject) && !empty($user_body)) {
                $sent = wp_mail($client_email, $user_subject, $user_body, $headers);
                if ($sent) {
                    MC_Leads_Activity::log(
                        $lead_id,
                        'email_sent',
                        sprintf(__('Confirmation email sent to client: %s', 'mc-leads-engine'), $client_email)
                    );
                }
            }
        }

        // 2. Admin Email notification
        $admin_recipient = !empty($settings['notification_email']) ? $settings['notification_email'] : get_option('admin_email');
        if (is_email($admin_recipient)) {
            $subject_key = $is_booking ? 'booking_admin_email_subject' : 'admin_email_subject';
            $body_key = $is_booking ? 'booking_admin_email_body' : 'admin_email_body';

            $admin_subject = $this->parse_message_placeholders($settings[$subject_key] ?? '', $lead_id, false);
            $admin_body = $this->parse_message_placeholders($settings[$body_key] ?? '', $lead_id, true);
            
            if (!empty($admin_subject) && !empty($admin_body)) {
                $sent = wp_mail($admin_recipient, $admin_subject, $admin_body, $headers);
                if ($sent) {
                    MC_Leads_Activity::log(
                        $lead_id,
                        'email_sent',
                        sprintf(__('Admin notification email sent to: %s', 'mc-leads-engine'), $admin_recipient)
                    );
                }
            }
        }

        // 3. Admin WhatsApp notification
        $admin_phone = $settings['admin_whatsapp_phone'] ?? '';
        $whatsapp_body_key = $is_booking ? 'booking_admin_whatsapp_body' : 'admin_whatsapp_body';
        $whatsapp_body = $this->parse_message_placeholders($settings[$whatsapp_body_key] ?? '', $lead_id, false);
        
        if (!empty($admin_phone) && !empty($whatsapp_body)) {
            $sent = $this->send_whatsapp_notification($admin_phone, $whatsapp_body);
            if ($sent) {
                MC_Leads_Activity::log(
                    $lead_id,
                    'whatsapp_sent',
                    sprintf(__('WhatsApp notification sent to admin: %s', 'mc-leads-engine'), $admin_phone)
                );
            }
        }

        // 4. User WhatsApp notification
        $client_phone = $this->find_client_phone($lead_id);
        $user_whatsapp_body_key = $is_booking ? 'booking_user_whatsapp_body' : 'user_whatsapp_body';
        $user_whatsapp_body = $this->parse_message_placeholders($settings[$user_whatsapp_body_key] ?? '', $lead_id, false);
        
        if (!empty($client_phone) && !empty($user_whatsapp_body)) {
            $sent = $this->send_whatsapp_notification($client_phone, $user_whatsapp_body);
            if ($sent) {
                MC_Leads_Activity::log(
                    $lead_id,
                    'whatsapp_sent',
                    sprintf(__('WhatsApp confirmation sent to client: %s', 'mc-leads-engine'), $client_phone)
                );
            }
        }
    }

    public function find_client_email($lead_id) {
        // 1. Check CF7 data first
        $cf7_rows = $this->get_cf7_data($lead_id);
        if (is_array($cf7_rows) && !empty($cf7_rows)) {
            $cf7_data = json_decode($cf7_rows[0]['data_json'] ?? '{}', true);
            if (is_array($cf7_data)) {
                // First pass: look for keys containing the word 'email'
                foreach ($cf7_data as $key => $val) {
                    if (is_array($val)) {
                        continue;
                    }
                    if (strpos(strtolower($key), 'email') !== false && is_email(trim((string)$val))) {
                        return trim((string)$val);
                    }
                }
            }
        }

        // 2. Check standard answers joined with questions
        global $wpdb;
        $answers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.answer_value, q.question_text
                 FROM " . mc_leads_engine_table('lead_answers') . " a
                 LEFT JOIN " . mc_leads_engine_table('survey_questions') . " q ON a.question_id = q.id
                 WHERE a.lead_id = %d",
                absint($lead_id)
            ),
            ARRAY_A
        );

        if (is_array($answers)) {
            // First pass: look for answers where the question text contains 'email'
            foreach ($answers as $row) {
                $q_text = strtolower($row['question_text'] ?? '');
                $val = trim((string)($row['answer_value'] ?? ''));
                if (strpos($q_text, 'email') !== false && is_email($val)) {
                    return $val;
                }
            }

            // Second pass: check if any answer contains a valid email address as fallback
            foreach ($answers as $row) {
                $val = trim((string)($row['answer_value'] ?? ''));
                if (is_email($val)) {
                    return $val;
                }
            }
        }

        // 3. Last fallback: check CF7 data again for any valid email (if key didn't contain 'email')
        if (is_array($cf7_rows) && !empty($cf7_rows)) {
            $cf7_data = json_decode($cf7_rows[0]['data_json'] ?? '{}', true);
            if (is_array($cf7_data)) {
                foreach ($cf7_data as $key => $val) {
                    if (is_array($val)) {
                        continue;
                    }
                    if (is_email(trim((string)$val))) {
                        return trim((string)$val);
                    }
                }
            }
        }

        return '';
    }

    public function find_client_name($lead_id) {
        // 1. Check CF7 data first
        $cf7_rows = $this->get_cf7_data($lead_id);
        if (is_array($cf7_rows) && !empty($cf7_rows)) {
            $cf7_data = json_decode($cf7_rows[0]['data_json'] ?? '{}', true);
            if (is_array($cf7_data)) {
                // Look for keys containing 'name'
                foreach ($cf7_data as $key => $val) {
                    if (is_array($val)) {
                        continue;
                    }
                    $lkey = strtolower($key);
                    if (strpos($lkey, 'name') !== false && !empty($val)) {
                        return trim((string)$val);
                    }
                }
            }
        }

        // 2. Check standard answers joined with questions
        global $wpdb;
        $answers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.answer_value, q.question_text
                 FROM " . mc_leads_engine_table('lead_answers') . " a
                 LEFT JOIN " . mc_leads_engine_table('survey_questions') . " q ON a.question_id = q.id
                 WHERE a.lead_id = %d",
                absint($lead_id)
            ),
            ARRAY_A
        );

        if (is_array($answers)) {
            // First pass: look for answers where the question text contains 'name'
            foreach ($answers as $row) {
                $q_text = strtolower($row['question_text'] ?? '');
                $val = trim((string)($row['answer_value'] ?? ''));
                if (strpos($q_text, 'name') !== false && !empty($val)) {
                    return $val;
                }
            }
        }

        return '';
    }

    public function find_client_phone($lead_id) {
        // 1. Check CF7 data first
        $cf7_rows = $this->get_cf7_data($lead_id);
        if (is_array($cf7_rows) && !empty($cf7_rows)) {
            $cf7_data = json_decode($cf7_rows[0]['data_json'] ?? '{}', true);
            if (is_array($cf7_data)) {
                // First pass: look for keys containing 'phone', 'tel', 'whatsapp', 'mobile' or 'contact'
                foreach ($cf7_data as $key => $val) {
                    if (is_array($val)) {
                        continue;
                    }
                    $lkey = strtolower($key);
                    if ((strpos($lkey, 'phone') !== false || strpos($lkey, 'tel') !== false || strpos($lkey, 'whatsapp') !== false || strpos($lkey, 'mobile') !== false || strpos($lkey, 'contact') !== false) && !empty($val)) {
                        $clean = preg_replace('/[^0-9]/', '', (string)$val);
                        if (strlen($clean) >= 9 && strlen($clean) <= 15) {
                            return trim((string)$val);
                        }
                    }
                }
            }
        }

        // 2. Check standard answers joined with questions
        global $wpdb;
        $answers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.answer_value, q.question_text
                 FROM " . mc_leads_engine_table('lead_answers') . " a
                 LEFT JOIN " . mc_leads_engine_table('survey_questions') . " q ON a.question_id = q.id
                 WHERE a.lead_id = %d",
                absint($lead_id)
            ),
            ARRAY_A
        );

        if (is_array($answers)) {
            // First pass: look for answers where the question text contains 'phone', 'tel', 'whatsapp', 'mobile', or 'contact'
            foreach ($answers as $row) {
                $q_text = strtolower($row['question_text'] ?? '');
                $val = trim((string)($row['answer_value'] ?? ''));
                if ((strpos($q_text, 'phone') !== false || strpos($q_text, 'tel') !== false || strpos($q_text, 'whatsapp') !== false || strpos($q_text, 'mobile') !== false || strpos($q_text, 'contact') !== false) && !empty($val)) {
                    $clean = preg_replace('/[^0-9]/', '', $val);
                    if (strlen($clean) >= 9 && strlen($clean) <= 15) {
                        return $val;
                    }
                }
            }

            // Second pass: check if any answer contains a valid phone format fallback (9-15 digits)
            foreach ($answers as $row) {
                $val = trim((string)($row['answer_value'] ?? ''));
                $clean = preg_replace('/[^0-9]/', '', $val);
                if (strlen($clean) >= 9 && strlen($clean) <= 15) {
                    return $val;
                }
            }
        }

        // 3. Last fallback: check CF7 data again for any valid phone (9-15 digits)
        if (is_array($cf7_rows) && !empty($cf7_rows)) {
            $cf7_data = json_decode($cf7_rows[0]['data_json'] ?? '{}', true);
            if (is_array($cf7_data)) {
                foreach ($cf7_data as $key => $val) {
                    if (is_array($val)) {
                        continue;
                    }
                    $clean = preg_replace('/[^0-9]/', '', (string)$val);
                    if (strlen($clean) >= 9 && strlen($clean) <= 15) {
                        return trim((string)$val);
                    }
                }
            }
        }

        return '';
    }

    public function parse_message_placeholders($message, $lead_id, $is_html = true) {
        if (empty($message)) {
            return '';
        }

        $lead = $this->get_lead($lead_id);
        if (!$lead) {
            return $message;
        }

        // Get survey title
        $survey_id = absint($lead['survey_id']);
        $survey = mc_leads_engine_survey_repository()->get_survey($survey_id);
        $survey_title = $survey ? ($survey['title'] ?? 'Survey') : 'Survey';

        // Prepare replacements map
        $replacements = array(
            '[lead_id]'      => $lead['id'],
            '[session_id]'   => $lead['session_id'],
            '[total_price]'  => number_format((float) $lead['total_price'], 2, '.', ''),
            '[lead_score]'   => $lead['lead_score'],
            '[created_at]'   => $lead['created_at'],
            '[survey_id]'    => $lead['survey_id'],
            '[survey_title]' => $survey_title,
            // Phase 3B: attribution & status placeholders
            '[utm_source]'   => $lead['utm_source']   ?? '',
            '[utm_medium]'   => $lead['utm_medium']   ?? '',
            '[utm_campaign]' => $lead['utm_campaign'] ?? '',
            '[lead_status]'  => mc_leads_status_label($lead['status'] ?? 'new'),
        );

        // Fetch booking details if available
        global $wpdb;
        $booking = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . mc_leads_engine_table('bookings') . " WHERE lead_id = %d",
                absint($lead_id)
            ),
            ARRAY_A
        );

        if ($booking) {
            $type_labels = array(
                'online' => __('Online Call', 'mc-leads-engine'),
                'coffee' => __('Coffee Meeting', 'mc-leads-engine'),
                'office' => __('Office Visit', 'mc-leads-engine'),
                'host'   => __('Our Studio', 'mc-leads-engine'),
            );
            $replacements['[booking_type]'] = $type_labels[$booking['meeting_type']] ?? $booking['meeting_type'];
            $replacements['[booking_date]'] = $booking['meeting_date'];
            $replacements['[booking_time]'] = $booking['meeting_time'];
            $replacements['[booking_location]'] = $booking['location_name'] . ($booking['location_address'] ? ' (' . $booking['location_address'] . ')' : '');
        } else {
            $replacements['[booking_type]'] = '';
            $replacements['[booking_date]'] = '';
            $replacements['[booking_time]'] = '';
            $replacements['[booking_location]'] = '';
        }

        // 1. Get standard answers joined with questions
        global $wpdb;
        $answers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.question_id, q.question_text, q.type, a.answer_value 
                 FROM " . mc_leads_engine_table('lead_answers') . " a
                 LEFT JOIN " . mc_leads_engine_table('survey_questions') . " q ON a.question_id = q.id
                 WHERE a.lead_id = %d",
                absint($lead_id)
            ),
            ARRAY_A
        );

        $answers_list = array();

        if (is_array($answers)) {
            foreach ($answers as $row) {
                $q_text = $row['question_text'] ?? '';
                $val = $row['answer_value'] ?? '';
                
                // Decode array answers (like checkboxes)
                $decoded = json_decode($val, true);
                if (is_array($decoded)) {
                    $val = implode(', ', $decoded);
                }

                if (!empty($q_text)) {
                    $answers_list[$q_text] = $val;
                    
                    // Slugify the question text to create a bracket like [what-is-your-business-name]
                    $slug = $this->slugify($q_text);
                    $replacements['[' . $slug . ']'] = $val;
                }

                // Support [q_ID]
                $replacements['[q_' . $row['question_id'] . ']'] = $val;
            }
        }

        // 2. Get CF7 integration data
        $cf7_rows = $this->get_cf7_data($lead_id);
        if (is_array($cf7_rows) && !empty($cf7_rows)) {
            $cf7_data = json_decode($cf7_rows[0]['data_json'] ?? '{}', true);
            if (is_array($cf7_data)) {
                foreach ($cf7_data as $key => $val) {
                    // Skip metadata
                    if (in_array($key, array('cf7_form_id', 'survey_data', 'pricing'), true)) {
                        continue;
                    }
                    
                    if (is_array($val)) {
                        $val = implode(', ', $val);
                    }

                    $replacements['[' . $key . ']'] = $val;
                    
                    // Also list in answers list if not already present
                    $label = ucwords(str_replace(array('-', '_'), ' ', $key));
                    if (!isset($answers_list[$label])) {
                        $answers_list[$label] = $val;
                    }
                }
            }
        }

        // 3. Build [all_answers] placeholder
        $all_answers_content = '';
        if (!empty($answers_list)) {
            if ($is_html) {
                $all_answers_content = '<table style="width: 100%; border-collapse: collapse; margin: 10px 0;">';
                $all_answers_content .= '<tbody>';
                $bg = '#ffffff';
                foreach ($answers_list as $q => $a) {
                    $bg = ($bg === '#ffffff') ? '#f8fafc' : '#ffffff';
                    $all_answers_content .= sprintf(
                        '<tr style="background-color: %s; border-bottom: 1px solid #e2e8f0;">' .
                        '<td style="padding: 10px; font-weight: bold; width: 40%%; color: #475569; font-size: 13px;">%s</td>' .
                        '<td style="padding: 10px; color: #0f172a; font-size: 13px;">%s</td>' .
                        '</tr>',
                        $bg,
                        esc_html($q),
                        esc_html($a)
                    );
                }
                $all_answers_content .= '</tbody></table>';
            } else {
                foreach ($answers_list as $q => $a) {
                    $all_answers_content .= esc_html($q) . ": " . esc_html($a) . "\n";
                }
                $all_answers_content = rtrim($all_answers_content);
            }
        } else {
            $all_answers_content = $is_html ? '<p style="color: #64748b; font-style: italic;">No answers submitted.</p>' : 'No answers submitted.';
        }

        $replacements['[all_answers]'] = $all_answers_content;

        // Perform search and replace
        $search = array_keys($replacements);
        $replace = array_values($replacements);

        return str_replace($search, $replace, $message);
    }

    public function send_whatsapp_notification($to_phone, $message) {
        $settings = mc_leads_engine_get_settings();
        $api_key = $settings['whatsapp_api_key'] ?? '';
        $gateway = $settings['whatsapp_gateway'] ?? 'ultramsg';
        $instance_id = $settings['whatsapp_instance_id'] ?? '';
        $sender = $settings['whatsapp_sender'] ?? '';

        if (empty($api_key)) {
            error_log('MC Leads Engine: WhatsApp send failed - API Key / Token is empty.');
            return false;
        }

        $clean_phone = preg_replace('/[^0-9]/', '', $to_phone);
        if (empty($clean_phone)) {
            error_log('MC Leads Engine: WhatsApp send failed - phone number is invalid.');
            return false;
        }

        $response = null;

        switch ($gateway) {
            case 'ultramsg':
                if (empty($instance_id)) {
                    error_log('MC Leads Engine: WhatsApp send failed - UltraMsg Instance ID is empty.');
                    return false;
                }
                $url = 'https://api.ultramsg.com/' . trim($instance_id) . '/messages/chat';
                $response = wp_remote_post($url, array(
                    'body' => array(
                        'token' => trim($api_key),
                        'to'    => $clean_phone,
                        'body'  => $message,
                    )
                ));
                break;

            case 'twilio':
                if (empty($instance_id) || empty($sender)) {
                    error_log('MC Leads Engine: WhatsApp send failed - Twilio Account SID or Sender Number is empty.');
                    return false;
                }
                $account_sid = trim($instance_id);
                $auth_token = trim($api_key);
                $url = "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/Messages.json";
                
                $twilio_to = 'whatsapp:+' . preg_replace('/^\+/', '', $clean_phone);
                $twilio_from = 'whatsapp:+' . preg_replace('/^\+/', '', preg_replace('/[^0-9]/', '', $sender));
                
                $response = wp_remote_post($url, array(
                    'headers' => array(
                        'Authorization' => 'Basic ' . base64_encode("{$account_sid}:{$auth_token}"),
                    ),
                    'body' => array(
                        'To'   => $twilio_to,
                        'From' => $twilio_from,
                        'Body' => $message,
                    )
                ));
                break;

            case 'cloud_api':
                if (empty($instance_id)) {
                    error_log('MC Leads Engine: WhatsApp send failed - Meta Phone Number ID is empty.');
                    return false;
                }
                $phone_number_id = trim($instance_id);
                $access_token = trim($api_key);
                $url = "https://graph.facebook.com/v17.0/{$phone_number_id}/messages";
                
                $response = wp_remote_post($url, array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $access_token,
                        'Content-Type'  => 'application/json',
                    ),
                    'body' => wp_json_encode(array(
                        'messaging_product' => 'whatsapp',
                        'recipient_type'    => 'individual',
                        'to'                => $clean_phone,
                        'type'              => 'text',
                        'text'              => array(
                            'preview_url' => false,
                            'body'        => $message,
                        )
                    ))
                ));
                break;

            case 'custom':
                if (empty($instance_id) || !filter_var($instance_id, FILTER_VALIDATE_URL)) {
                    error_log('MC Leads Engine: WhatsApp send failed - Custom Webhook Gateway URL is empty or invalid.');
                    return false;
                }
                $webhook_url = trim($instance_id);
                $response = wp_remote_post($webhook_url, array(
                    'headers' => array(
                        'Content-Type' => 'application/json',
                    ),
                    'body' => wp_json_encode(array(
                        'to'      => $clean_phone,
                        'message' => $message,
                        'api_key' => $api_key,
                    ))
                ));
                break;

            default:
                error_log('MC Leads Engine: WhatsApp send failed - unknown gateway selected.');
                return false;
        }

        if (is_wp_error($response)) {
            error_log('MC Leads Engine: WhatsApp send HTTP error - ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            error_log('MC Leads Engine: WhatsApp send failed with HTTP code ' . $code . ' - Response: ' . wp_remote_retrieve_body($response));
            return false;
        }

        return true;
    }

    protected function slugify($text) {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = preg_replace('~-+~', '-', $text);
        $text = strtolower($text);
        if (empty($text)) {
            return 'n-a';
        }
        return $text;
    }
}
