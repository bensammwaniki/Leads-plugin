<?php

if (!defined('ABSPATH')) {
    exit;
}

function mc_leads_engine_render_metric_chart($series, $metric_key, $title, $accent = '#2563eb', $formatter = null) {
    $series = is_array($series) ? $series : array();
    $formatter = is_callable($formatter) ? $formatter : null;
    $max_value = 0;

    foreach ($series as $row) {
        $max_value = max($max_value, (float) ($row[$metric_key] ?? 0));
    }

    if ($max_value <= 0) {
        $max_value = 1;
    }

    ob_start();
    ?>
    <div class="mc-panel mc-metric-panel-<?php echo esc_attr($metric_key); ?>">
        <h2><?php echo esc_html($title); ?></h2>
        <div class="mc-chart">
            <?php foreach ($series as $row) : 
                $value = (float) ($row[$metric_key] ?? 0);
                $height = max(6, round(($value / $max_value) * 100));
                $display_value = $formatter ? call_user_func($formatter, $value) : number_format_i18n($value, is_float($value) ? 2 : 0);
            ?>
                <div class="mc-chart-bar">
                    <div class="mc-chart-bar-track">
                        <div class="mc-chart-bar-fill" style="height: <?php echo esc_attr($height); ?>%; background: <?php echo esc_attr($accent); ?>;"></div>
                    </div>
                    <div class="mc-chart-meta">
                        <strong><?php echo esc_html($display_value); ?></strong>
                        <span class="mc-chart-label"><?php echo esc_html($row['label'] ?? ''); ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function mc_leads_engine_render_analytics_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'mc-leads-engine'));
    }

    $metrics = mc_leads_engine_leads_repository()->get_dashboard_metrics();
    $analytics = $metrics['analytics'];
    $survey_id = absint($_GET['survey_id'] ?? 0);
    $orderby = sanitize_key($_GET['orderby'] ?? 'created_at');
    $order = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

    $allowed_orderby = array('id', 'created_at', 'lead_score', 'total_price');
    if (!in_array($orderby, $allowed_orderby, true)) {
        $orderby = 'created_at';
    }

    // Handle Excel Export
    if (!empty($_GET['export_analytics'])) {
        check_admin_referer('mc_leads_engine_export_analytics');
        
        $leads = mc_leads_engine_leads_repository()->export_rows(array(
            'survey_id' => $survey_id,
            'orderby' => $orderby,
            'order' => $order,
            'limit' => 10000,
        ));

        // Get questions map
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
        foreach ($leads as $row) {
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
            $survey_title = $is_booking ? __('Bookings', 'mc-leads-engine') : ($survey_row['title'] ?? $row['survey_id']);
            
            $name = mc_leads_engine_leads_repository()->find_client_name($row['id']);
            $email = mc_leads_engine_leads_repository()->find_client_email($row['id']);
            $phone = mc_leads_engine_leads_repository()->find_client_phone($row['id']);

            // Parse answers JSON
            $answers = json_decode($row['answers_json'] ?? '[]', true);
            $answers_summary_parts = array();
            if (is_array($answers)) {
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
                            $lkey = strtolower($key);
                            if (!$is_booking_key && strpos($lkey, 'name') === false && strpos($lkey, 'email') === false && strpos($lkey, 'phone') === false && strpos($lkey, 'tel') === false && strpos($lkey, 'whatsapp') === false) {
                                $val_str = is_array($val) ? implode(', ', $val) : (string)$val;
                                $answers_summary_parts[] = $key . ': ' . $val_str;
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

        $writer = new MC_Leads_Engine_XLSX_Writer('Analytics');
        $writer->set_headers($headers);
        $writer->set_rows($export_data);
        $writer->set_col_types($col_types);
        $writer->set_col_alignments($col_alignments);
        $writer->write_to_output('mc-leads-engine-analytics-export.xlsx');
    }

    $all_leads = mc_leads_engine_leads_repository()->get_leads(array(
        'survey_id' => $survey_id,
        'orderby'   => $orderby,
        'order'     => $order,
        'limit'     => 1000,
    ));
    $daily_stats = mc_leads_engine_leads_repository()->get_daily_lead_stats(14);
    ?>
    <div class="wrap mc-leads-engine-admin">
        <h1><?php esc_html_e('Analytics', 'mc-leads-engine'); ?></h1>
        <div class="mc-leads-engine-cards">
            <div class="mc-card"><strong><?php echo esc_html(number_format_i18n($metrics['total_leads'])); ?></strong><span><?php esc_html_e('Total Submissions', 'mc-leads-engine'); ?></span></div>
            <div class="mc-card"><strong><?php echo esc_html(number_format_i18n($metrics['survey_starts'])); ?></strong><span><?php esc_html_e('Survey Starts', 'mc-leads-engine'); ?></span></div>
            <div class="mc-card"><strong><?php echo esc_html(number_format_i18n($metrics['revenue'], 2)); ?></strong><span><?php esc_html_e('Revenue Estimate', 'mc-leads-engine'); ?></span></div>
            <div class="mc-card"><strong><?php echo esc_html(number_format_i18n($metrics['conversion_rate'], 2)); ?>%</strong><span><?php esc_html_e('Conversion Rate', 'mc-leads-engine'); ?></span></div>
        </div>

        <div class="mc-analytics-flex-row">
            <?php echo mc_leads_engine_render_metric_chart($daily_stats, 'lead_count', __('Leads Per Day', 'mc-leads-engine'), '#2563eb', 'intval'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo mc_leads_engine_render_metric_chart($daily_stats, 'revenue', __('Revenue Per Day', 'mc-leads-engine'), '#22c55e', function ($value) { return number_format_i18n((float) $value, 2); }); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <div class="mc-panel mc-step-dropoff-panel">
                <h2><?php esc_html_e('Step Drop-off', 'mc-leads-engine'); ?></h2>
                <?php
                $step_progress = mc_leads_engine_leads_repository()->get_step_dropoff($survey_id);
                foreach ($step_progress as $sid => $steps) :
                    $peak = $steps ? max($steps) : 1;
                ?>
                    <h3><?php echo esc_html(sprintf(__('Survey #%d', 'mc-leads-engine'), $sid)); ?></h3>
                    <?php foreach ($steps as $step => $count) : ?>
                        <div class="mc-bar">
                            <span><?php echo esc_html(sprintf(__('Step %d', 'mc-leads-engine'), $step)); ?></span>
                            <strong><?php echo esc_html(number_format_i18n((int) $count)); ?></strong>
                            <div class="mc-bar-track"><div class="mc-bar-fill" style="width: <?php echo esc_attr($peak ? round(($count / $peak) * 100) : 0); ?>%"></div></div>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mc-panel">
            <h2><?php esc_html_e('All Submitted Data', 'mc-leads-engine'); ?></h2>
            
            <form method="get" class="mc-analytics-filter-form">
                <input type="hidden" name="page" value="mc-leads-engine-analytics">
                
                <label>
                    <?php esc_html_e('Survey:', 'mc-leads-engine'); ?>
                    <select name="survey_id">
                        <option value="0"><?php esc_html_e('All Surveys', 'mc-leads-engine'); ?></option>
                        <?php 
                        $surveys = mc_leads_engine_survey_repository()->get_surveys(array('limit' => 100));
                        foreach ($surveys as $survey) : 
                        ?>
                            <option value="<?php echo esc_attr($survey['id']); ?>" <?php selected($survey_id, $survey['id']); ?>>
                                <?php echo esc_html($survey['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <?php esc_html_e('Sort By:', 'mc-leads-engine'); ?>
                    <select name="orderby">
                        <option value="created_at" <?php selected($orderby, 'created_at'); ?>><?php esc_html_e('Date Created', 'mc-leads-engine'); ?></option>
                        <option value="total_price" <?php selected($orderby, 'total_price'); ?>><?php esc_html_e('Estimated Price', 'mc-leads-engine'); ?></option>
                        <option value="lead_score" <?php selected($orderby, 'lead_score'); ?>><?php esc_html_e('Lead Score', 'mc-leads-engine'); ?></option>
                        <option value="id" <?php selected($orderby, 'id'); ?>><?php esc_html_e('Lead ID', 'mc-leads-engine'); ?></option>
                    </select>
                </label>

                <label>
                    <?php esc_html_e('Order:', 'mc-leads-engine'); ?>
                    <select name="order">
                        <option value="DESC" <?php selected($order, 'DESC'); ?>><?php esc_html_e('Descending', 'mc-leads-engine'); ?></option>
                        <option value="ASC" <?php selected($order, 'ASC'); ?>><?php esc_html_e('Ascending', 'mc-leads-engine'); ?></option>
                    </select>
                </label>

                <button class="button button-primary" type="submit"><?php esc_html_e('Apply', 'mc-leads-engine'); ?></button>
                
                <?php 
                $export_url = add_query_arg(array(
                    'page' => 'mc-leads-engine-analytics',
                    'survey_id' => $survey_id,
                    'orderby' => $orderby,
                    'order' => $order,
                    'export_analytics' => 1,
                    '_wpnonce' => wp_create_nonce('mc_leads_engine_export_analytics')
                ), admin_url('admin.php'));
                ?>
                <a class="button button-secondary" href="<?php echo esc_url($export_url); ?>">
                    <?php esc_html_e('Export to Excel', 'mc-leads-engine'); ?>
                </a>
            </form>

            <?php
            $sort_id_url = add_query_arg(array(
                'orderby' => 'id',
                'order'   => ($orderby === 'id' && $order === 'DESC') ? 'ASC' : 'DESC',
            ));
            $sort_date_url = add_query_arg(array(
                'orderby' => 'created_at',
                'order'   => ($orderby === 'created_at' && $order === 'DESC') ? 'ASC' : 'DESC',
            ));
            $sort_price_url = add_query_arg(array(
                'orderby' => 'total_price',
                'order'   => ($orderby === 'total_price' && $order === 'DESC') ? 'ASC' : 'DESC',
            ));
            $sort_score_url = add_query_arg(array(
                'orderby' => 'lead_score',
                'order'   => ($orderby === 'lead_score' && $order === 'DESC') ? 'ASC' : 'DESC',
            ));
            ?>
            <div style="overflow-x: auto;">
                <table class="widefat striped mc-analytics-leads-table">
                    <thead>
                        <tr>
                            <th>
                                <a href="<?php echo esc_url($sort_id_url); ?>" style="text-decoration:none;">
                                    <?php esc_html_e('Lead ID', 'mc-leads-engine'); ?>
                                    <?php if ($orderby === 'id') : ?>
                                        <span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:16px; width:16px; height:16px; vertical-align:middle;"></span>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?php echo esc_url($sort_date_url); ?>" style="text-decoration:none;">
                                    <?php esc_html_e('Date', 'mc-leads-engine'); ?>
                                    <?php if ($orderby === 'created_at') : ?>
                                        <span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:16px; width:16px; height:16px; vertical-align:middle;"></span>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th><?php esc_html_e('Survey', 'mc-leads-engine'); ?></th>
                            <th><?php esc_html_e('Client Details', 'mc-leads-engine'); ?></th>
                            <th><?php esc_html_e('Submitted Answers', 'mc-leads-engine'); ?></th>
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
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
                    global $wpdb;
                    $questions_map = array();
                    $questions_rows = $wpdb->get_results("SELECT id, question_text FROM " . mc_leads_engine_table('survey_questions'), ARRAY_A);
                    if (is_array($questions_rows)) {
                        foreach ($questions_rows as $q) {
                            $questions_map[(int)$q['id']] = $q['question_text'];
                        }
                    }

                    foreach ($all_leads as $lead) : 
                        $survey_row = mc_leads_engine_survey_repository()->get_survey($lead['survey_id']);
                        $is_booking = (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . mc_leads_engine_table('bookings') . " WHERE lead_id = %d", $lead['id']));
                        if (!$is_booking) {
                            $cf7_rows = mc_leads_engine_leads_repository()->get_cf7_data($lead['id']);
                            if (!empty($cf7_rows)) {
                                $cf7_data = json_decode($cf7_rows[0]['data_json'] ?? '{}', true);
                                if (is_array($cf7_data) && isset($cf7_data['mc_booking_date'])) {
                                    $is_booking = true;
                                }
                            }
                        }
                        $name = mc_leads_engine_leads_repository()->find_client_name($lead['id']);
                        $email = mc_leads_engine_leads_repository()->find_client_email($lead['id']);
                        $phone = mc_leads_engine_leads_repository()->find_client_phone($lead['id']);
                        $answers = json_decode($lead['answers_json'] ?? '[]', true);
                    ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg(array('page' => 'mc-leads-engine-leads', 'lead_id' => $lead['id']), admin_url('admin.php'))); ?>">
                                    #<?php echo esc_html($lead['id']); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html($lead['created_at']); ?></td>
                            <td><?php echo $is_booking ? esc_html__('Bookings', 'mc-leads-engine') : esc_html($survey_row['title'] ?? $lead['survey_id']); ?></td>
                            <td>
                                <?php if ($name) : ?><strong><?php esc_html_e('Name:', 'mc-leads-engine'); ?></strong> <?php echo esc_html($name); ?><br><?php endif; ?>
                                <?php if ($email) : ?><strong><?php esc_html_e('Email:', 'mc-leads-engine'); ?></strong> <?php echo esc_html($email); ?><br><?php endif; ?>
                                <?php if ($phone) : ?><strong><?php esc_html_e('Phone:', 'mc-leads-engine'); ?></strong> <?php echo esc_html($phone); ?><?php endif; ?>
                                <?php if (!$name && !$email && !$phone) : ?>
                                    <span class="description">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                $cf7_rows = mc_leads_engine_leads_repository()->get_cf7_data($lead['id']);
                                $list_items = array();
                                
                                if (is_array($answers)) {
                                    foreach ($answers as $q_id => $val) { 
                                        $q_text = $questions_map[(int)$q_id] ?? sprintf(__('Question #%d', 'mc-leads-engine'), $q_id);
                                        $val_str = is_array($val) ? implode(', ', $val) : (string)$val;
                                        if ($val_str !== '') {
                                            $list_items[] = '<strong>' . esc_html($q_text) . ':</strong> ' . esc_html($val_str);
                                        }
                                    }
                                }
                                
                                if (!empty($cf7_rows)) {
                                    $cf7_data = json_decode($cf7_rows[0]['data_json'] ?? '{}', true);
                                    if (is_array($cf7_data)) {
                                        foreach ($cf7_data as $key => $val) {
                                            if (!empty($val) && !in_array($key, array('cf7_form_id', 'mc_session_id', 'mc_survey_id', 'survey_data', 'pricing'), true)) {
                                                $is_booking_key = (strpos($key, 'mc_booking_') === 0 || $key === 'mc_leads_session_id');
                                                $lkey = strtolower($key);
                                                if (!$is_booking_key && strpos($lkey, 'name') === false && strpos($lkey, 'email') === false && strpos($lkey, 'phone') === false && strpos($lkey, 'tel') === false && strpos($lkey, 'whatsapp') === false) {
                                                    $val_str = is_array($val) ? implode(', ', $val) : (string)$val;
                                                    $list_items[] = '<strong>' . esc_html($key) . ':</strong> ' . esc_html($val_str);
                                                }
                                            }
                                        }
                                    }
                                }

                                if (!empty($list_items)) :
                                ?>
                                    <ul class="mc-analytics-answers-list">
                                        <?php foreach ($list_items as $item) : ?>
                                            <li><?php echo $item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else : ?>
                                    <span class="description">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(number_format_i18n((float) $lead['total_price'], 2)); ?></td>
                            <td><?php echo esc_html($lead['lead_score']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
}
