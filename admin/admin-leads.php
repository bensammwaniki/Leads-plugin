<?php

if (!defined('ABSPATH')) {
    exit;
}

function mc_leads_engine_render_leads_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'mc-leads-engine'));
    }

    $survey_id = absint($_GET['survey_id'] ?? 0);
    $min_score = absint($_GET['min_score'] ?? 0);
    $lead_id = absint($_GET['lead_id'] ?? 0);
    $orderby = sanitize_key($_GET['orderby'] ?? 'created_at');
    $order = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

    $allowed_orderby = array('id', 'created_at', 'lead_score', 'total_price');
    if (!in_array($orderby, $allowed_orderby, true)) {
        $orderby = 'created_at';
    }

    if (!empty($_GET['export'])) {
        check_admin_referer('mc_leads_engine_export_leads');
        $rows = mc_leads_engine_leads_repository()->export_rows(array(
            'survey_id' => $survey_id,
            'min_score' => $min_score,
            'limit' => 10000,
            'orderby' => $orderby,
            'order' => $order,
        ));

        global $wpdb;
        $questions_rows = $wpdb->get_results("SELECT id, question_text FROM " . mc_leads_engine_table('survey_questions'), ARRAY_A);
        $questions_map = array();
        if (is_array($questions_rows)) {
            foreach ($questions_rows as $q) {
                $questions_map[(int)$q['id']] = $q['question_text'];
            }
        }

        $headers = array(
            __('Lead ID', 'mc-leads-engine'),
            __('Created Date', 'mc-leads-engine'),
            __('Survey Title', 'mc-leads-engine'),
            __('Client Name', 'mc-leads-engine'),
            __('Client Email', 'mc-leads-engine'),
            __('Client Phone', 'mc-leads-engine'),
            __('Submitted Answers', 'mc-leads-engine'),
            __('Estimated Price', 'mc-leads-engine'),
            __('Lead Score', 'mc-leads-engine'),
        );

        $col_types = array('text', 'text', 'text', 'text', 'text', 'text', 'text', 'price', 'score');
        $col_alignments = array('center', 'left', 'left', 'left', 'left', 'left', 'left', 'right', 'center');

        $export_data = array();
        foreach ($rows as $row) {
            $survey_row = mc_leads_engine_survey_repository()->get_survey($row['survey_id']);
            $is_booking = (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . mc_leads_engine_table('bookings') . " WHERE lead_id = %d", $row['id']));
            $survey_title = $is_booking ? __('Bookings', 'mc-leads-engine') : ($survey_row['title'] ?? $row['survey_id']);

            $name = mc_leads_engine_leads_repository()->find_client_name($row['id']);
            $email = mc_leads_engine_leads_repository()->find_client_email($row['id']);
            $phone = mc_leads_engine_leads_repository()->find_client_phone($row['id']);

            // Parse answers JSON
            $answers = json_decode($row['answers_json'] ?? '[]', true);
            $answers_summary_parts = array();
            if (!$is_booking && is_array($answers)) {
                foreach ($answers as $q_id => $val) {
                    $q_text = $questions_map[(int)$q_id] ?? sprintf('Question #%d', $q_id);
                    $val_str = is_array($val) ? implode(', ', $val) : (string)$val;
                    if ($val_str !== '') {
                        $answers_summary_parts[] = $q_text . ': ' . $val_str;
                    }
                }
            }

            // Parse CF7 non-contact fields
            $cf7_rows = $row['cf7'] ?? array();
            if (!empty($cf7_rows)) {
                $cf7_data = json_decode($cf7_rows[0]['data_json'] ?? '{}', true);
                if (is_array($cf7_data)) {
                    foreach ($cf7_data as $key => $val) {
                        if (!empty($val) && !in_array($key, array('cf7_form_id', 'mc_session_id', 'mc_survey_id', 'survey_data', 'pricing'), true)) {
                            $is_booking_key = (strpos($key, 'mc_booking_') === 0 || $key === 'mc_leads_session_id');

                            if ($is_booking) {
                                if ($is_booking_key) {
                                    $val_str = is_array($val) ? implode(', ', $val) : (string)$val;
                                    $answers_summary_parts[] = $key . ': ' . $val_str;
                                }
                            } else {
                                $lkey = strtolower($key);
                                if (!$is_booking_key && strpos($lkey, 'name') === false && strpos($lkey, 'email') === false && strpos($lkey, 'phone') === false && strpos($lkey, 'tel') === false && strpos($lkey, 'whatsapp') === false) {
                                    $val_str = is_array($val) ? implode(', ', $val) : (string)$val;
                                    $answers_summary_parts[] = $key . ': ' . $val_str;
                                }
                            }
                        }
                    }
                }
            }

            $answers_summary = implode("\n", $answers_summary_parts);

            $export_data[] = array(
                $row['id'],
                $row['created_at'],
                $survey_title,
                $name,
                $email,
                $phone,
                $answers_summary,
                (float)$row['total_price'],
                (int)$row['lead_score'],
            );
        }

        $writer = new MC_Leads_Engine_XLSX_Writer('Leads');
        $writer->set_headers($headers);
        $writer->set_rows($export_data);
        $writer->set_col_types($col_types);
        $writer->set_col_alignments($col_alignments);
        $writer->write_to_output('mc-leads-engine-leads.xlsx');
    }

    $leads = mc_leads_engine_leads_repository()->get_leads(array(
        'survey_id' => $survey_id,
        'min_score' => $min_score,
        'limit' => 100,
        'orderby' => $orderby,
        'order' => $order,
    ));
    $lead = $lead_id ? mc_leads_engine_leads_repository()->get_lead($lead_id) : null;
    $lead_answers = $lead_id ? mc_leads_engine_leads_repository()->get_lead_answers($lead_id) : array();
    $lead_cf7 = $lead_id ? mc_leads_engine_leads_repository()->get_cf7_data($lead_id) : array();
    $surveys = mc_leads_engine_survey_repository()->get_surveys(array('limit' => 100));
    ?>
    <div class="wrap mc-leads-engine-admin">
        <h1><?php esc_html_e('Leads', 'mc-leads-engine'); ?></h1>

        <?php
        $sort_id_url = add_query_arg(array(
            'orderby' => 'id',
            'order'   => ($orderby === 'id' && $order === 'DESC') ? 'ASC' : 'DESC',
        ));
        $sort_price_url = add_query_arg(array(
            'orderby' => 'total_price',
            'order'   => ($orderby === 'total_price' && $order === 'DESC') ? 'ASC' : 'DESC',
        ));
        $sort_score_url = add_query_arg(array(
            'orderby' => 'lead_score',
            'order'   => ($orderby === 'lead_score' && $order === 'DESC') ? 'ASC' : 'DESC',
        ));
        $sort_date_url = add_query_arg(array(
            'orderby' => 'created_at',
            'order'   => ($orderby === 'created_at' && $order === 'DESC') ? 'ASC' : 'DESC',
        ));
        ?>
        <form method="get" class="mc-inline-form">
            <input type="hidden" name="page" value="mc-leads-engine-leads">
            <input type="hidden" name="orderby" value="<?php echo esc_attr($orderby); ?>">
            <input type="hidden" name="order" value="<?php echo esc_attr($order); ?>">
            <label><?php esc_html_e('Survey', 'mc-leads-engine'); ?>
                <select name="survey_id">
                    <option value="0"><?php esc_html_e('All surveys', 'mc-leads-engine'); ?></option>
                    <?php foreach ($surveys as $survey) : ?>
                        <option value="<?php echo esc_attr($survey['id']); ?>" <?php selected($survey_id, $survey['id']); ?>><?php echo esc_html($survey['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php esc_html_e('Min Score', 'mc-leads-engine'); ?><input type="number" name="min_score" value="<?php echo esc_attr($min_score); ?>"></label>
            <button class="button" type="submit"><?php esc_html_e('Filter', 'mc-leads-engine'); ?></button>
            <?php wp_nonce_field('mc_leads_engine_export_leads'); ?>
            <a class="button button-secondary" href="<?php echo esc_url(add_query_arg(array('page' => 'mc-leads-engine-leads', 'survey_id' => $survey_id, 'min_score' => $min_score, 'orderby' => $orderby, 'order' => $order, 'export' => 1, '_wpnonce' => wp_create_nonce('mc_leads_engine_export_leads')), admin_url('admin.php'))); ?>"><?php esc_html_e('Export to Excel', 'mc-leads-engine'); ?></a>
        </form>

        <?php if ($lead) : ?>
            <div class="mc-panel">
                <h2><?php echo esc_html(sprintf(__('Lead #%d', 'mc-leads-engine'), $lead['id'])); ?></h2>
                <p><?php echo esc_html(sprintf(__('Survey ID: %d | Session: %s | Price: %s | Score: %d', 'mc-leads-engine'), $lead['survey_id'], $lead['session_id'], number_format_i18n((float) $lead['total_price'], 2), $lead['lead_score'])); ?></p>
                <h3><?php esc_html_e('Answers', 'mc-leads-engine'); ?></h3>
                <pre><?php echo esc_html(wp_json_encode($lead_answers, JSON_PRETTY_PRINT)); ?></pre>
                <h3><?php esc_html_e('CF7 Data', 'mc-leads-engine'); ?></h3>
                <pre><?php echo esc_html(wp_json_encode($lead_cf7, JSON_PRETTY_PRINT)); ?></pre>
            </div>
        <?php endif; ?>

        <div class="mc-panel">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>
                            <a href="<?php echo esc_url($sort_id_url); ?>" style="text-decoration:none;">
                                <?php esc_html_e('ID', 'mc-leads-engine'); ?>
                                <?php if ($orderby === 'id') : ?>
                                    <span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:16px; width:16px; height:16px; vertical-align:middle;"></span>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th><?php esc_html_e('Survey', 'mc-leads-engine'); ?></th>
                        <th><?php esc_html_e('Session', 'mc-leads-engine'); ?></th>
                        <th>
                            <a href="<?php echo esc_url($sort_price_url); ?>" style="text-decoration:none;">
                                <?php esc_html_e('Price', 'mc-leads-engine'); ?>
                                <?php if ($orderby === 'total_price') : ?>
                                    <span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:16px; width:16px; height:16px; vertical-align:middle;"></span>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th>
                            <a href="<?php echo esc_url($sort_score_url); ?>" style="text-decoration:none;">
                                <?php esc_html_e('Score', 'mc-leads-engine'); ?>
                                <?php if ($orderby === 'lead_score') : ?>
                                    <span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:16px; width:16px; height:16px; vertical-align:middle;"></span>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th>
                            <a href="<?php echo esc_url($sort_date_url); ?>" style="text-decoration:none;">
                                <?php esc_html_e('Created', 'mc-leads-engine'); ?>
                                <?php if ($orderby === 'created_at') : ?>
                                    <span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:16px; width:16px; height:16px; vertical-align:middle;"></span>
                                <?php endif; ?>
                            </a>
                        </th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($leads as $row) : ?>
                    <?php 
                    global $wpdb;
                    $survey_row = mc_leads_engine_survey_repository()->get_survey($row['survey_id']);
                    $is_booking = (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . mc_leads_engine_table('bookings') . " WHERE lead_id = %d", $row['id']));
                    if (!$is_booking) {
                        $cf7_rows = mc_leads_engine_leads_repository()->get_cf7_data($row['id']);
                        if (!empty($cf7_rows)) {
                            $cf7_data = json_decode($cf7_rows[0]['data_json'] ?? '{}', true);
                            if (is_array($cf7_data) && isset($cf7_data['mc_booking_date'])) {
                                $is_booking = true;
                            }
                        }
                    }
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url(add_query_arg(array('page' => 'mc-leads-engine-leads', 'lead_id' => $row['id'], 'survey_id' => $survey_id, 'min_score' => $min_score, 'orderby' => $orderby, 'order' => $order), admin_url('admin.php'))); ?>"><?php echo esc_html($row['id']); ?></a></td>
                        <td><?php echo $is_booking ? esc_html__('Bookings', 'mc-leads-engine') : esc_html($survey_row['title'] ?? $row['survey_id']); ?></td>
                        <td><?php echo esc_html($row['session_id']); ?></td>
                        <td><?php echo esc_html(number_format_i18n((float) $row['total_price'], 2)); ?></td>
                        <td><?php echo esc_html($row['lead_score']); ?></td>
                        <td><?php echo esc_html($row['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
