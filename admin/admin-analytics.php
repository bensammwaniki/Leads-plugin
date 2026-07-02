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

    $metrics   = mc_leads_engine_leads_repository()->get_dashboard_metrics();
    $survey_id = absint($_GET['survey_id'] ?? 0);
    $orderby   = sanitize_key($_GET['orderby'] ?? 'created_at');
    $order     = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
    $paged     = max(1, absint($_GET['paged'] ?? 1));
    $per_page  = 50;
    $offset    = ($paged - 1) * $per_page;
    $days_allowed = array(7, 14, 30, 90);
    $days_raw  = isset($_GET['days']) ? (int) $_GET['days'] : 14;
    $days      = in_array($days_raw, $days_allowed, true) ? $days_raw : 14;

    $allowed_orderby = array('id', 'created_at', 'lead_score', 'total_price');
    if (!in_array($orderby, $allowed_orderby, true)) {
        $orderby = 'created_at';
    }

    // Handle Excel Export
    if (!empty($_GET['export_analytics'])) {
        check_admin_referer('mc_leads_engine_export_analytics');
        
        $export_leads = mc_leads_engine_leads_repository()->export_rows(array(
            'survey_id' => $survey_id,
            'orderby'   => $orderby,
            'order'     => $order,
            'limit'     => 10000,
        ));

        global $wpdb;
        $questions_rows = $wpdb->get_results("SELECT id, question_text FROM " . mc_leads_engine_table('survey_questions'), ARRAY_A);
        $questions_map  = array();
        if (is_array($questions_rows)) {
            foreach ($questions_rows as $q) {
                $questions_map[(int) $q['id']] = $q['question_text'];
            }
        }

        $headers = array(
            __('Lead ID', 'mc-leads-engine'),
            __('Created Date', 'mc-leads-engine'),
            __('Status', 'mc-leads-engine'),
            __('Survey Title', 'mc-leads-engine'),
            __('Client Name', 'mc-leads-engine'),
            __('Client Email', 'mc-leads-engine'),
            __('Client Phone', 'mc-leads-engine'),
            __('Submitted Answers', 'mc-leads-engine'),
            __('Estimated Price', 'mc-leads-engine'),
            __('Lead Score', 'mc-leads-engine'),
        );

        $col_types      = array('text', 'text', 'text', 'text', 'text', 'text', 'text', 'text', 'price', 'score');
        $col_alignments = array('center', 'left', 'left', 'left', 'left', 'left', 'left', 'left', 'right', 'center');

        $export_data = array();
        foreach ($export_leads as $row) {
            $survey_row   = mc_leads_engine_survey_repository()->get_survey($row['survey_id']);
            $is_booking   = mc_leads_is_booking($row);
            $survey_title = $is_booking ? __('Bookings', 'mc-leads-engine') : ($survey_row['title'] ?? $row['survey_id']);

            $name  = mc_leads_engine_leads_repository()->find_client_name($row['id']);
            $email = mc_leads_engine_leads_repository()->find_client_email($row['id']);
            $phone = mc_leads_engine_leads_repository()->find_client_phone($row['id']);

            $items           = mc_leads_engine_leads_repository()->build_answers_summary($row, $questions_map);
            $answers_summary = implode("\n", $items);

            $export_data[] = array(
                $row['id'],
                $row['created_at'],
                mc_leads_status_label($row['status'] ?? 'new'),
                $survey_title,
                $name,
                $email,
                $phone,
                $answers_summary,
                (float) $row['total_price'],
                (int) $row['lead_score'],
            );
        }

        $writer = new MC_Leads_Engine_XLSX_Writer('Analytics');
        $writer->set_headers($headers);
        $writer->set_rows($export_data);
        $writer->set_col_types($col_types);
        $writer->set_col_alignments($col_alignments);
        $writer->write_to_output('mc-leads-engine-analytics-export.xlsx');
    }

    $all_leads   = mc_leads_engine_leads_repository()->get_leads(array(
        'survey_id' => $survey_id,
        'orderby'   => $orderby,
        'order'     => $order,
        'limit'     => $per_page,
        'offset'    => $offset,
    ));
    $total_leads  = mc_leads_engine_leads_repository()->count_leads(array('survey_id' => $survey_id));
    $total_pages  = (int) ceil($total_leads / $per_page);
    $daily_stats  = mc_leads_engine_leads_repository()->get_daily_lead_stats($days);
    $utm_data     = mc_leads_engine_leads_repository()->get_utm_attribution($days);
    $answer_freq  = $survey_id ? mc_leads_engine_leads_repository()->get_answer_frequency($survey_id) : array();

    global $wpdb;
    $questions_rows = $wpdb->get_results("SELECT id, question_text FROM " . mc_leads_engine_table('survey_questions'), ARRAY_A);
    $questions_map  = array();
    if (is_array($questions_rows)) {
        foreach ($questions_rows as $q) {
            $questions_map[(int) $q['id']] = $q['question_text'];
        }
    }
    ?>
    <div class="wrap mc-leads-engine-admin">
        <h1 class="wp-heading-inline"><?php esc_html_e('Analytics', 'mc-leads-engine'); ?></h1>
        <hr class="wp-header-end">

        <!-- KPI Cards -->
        <div class="stat-grid" style="margin-bottom: 20px;">
            <div class="stat-card">
                <div class="stat-label"><span class="dashicons dashicons-groups"></span> <?php esc_html_e('Total Submissions', 'mc-leads-engine'); ?></div>
                <div class="stat-value"><?php echo esc_html(number_format_i18n($metrics['total_leads'])); ?></div>
                <div class="stat-delta"><?php esc_html_e('All time submissions', 'mc-leads-engine'); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Survey Starts', 'mc-leads-engine'); ?></div>
                <div class="stat-value"><?php echo esc_html(number_format_i18n($metrics['survey_starts'])); ?></div>
                <div class="stat-delta"><?php esc_html_e('Initiated surveys', 'mc-leads-engine'); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label"><span class="dashicons dashicons-money-alt"></span> <?php esc_html_e('Revenue Estimate', 'mc-leads-engine'); ?></div>
                <div class="stat-value">KES <?php echo esc_html(number_format_i18n($metrics['revenue'], 2)); ?></div>
                <div class="stat-delta"><?php esc_html_e('Estimated revenue', 'mc-leads-engine'); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label"><span class="dashicons dashicons-chart-line"></span> <?php esc_html_e('Conversion Rate', 'mc-leads-engine'); ?></div>
                <div class="stat-value"><?php echo esc_html(number_format_i18n($metrics['conversion_rate'], 2)); ?>%</div>
                <div class="stat-delta"><?php esc_html_e('Starts to completions', 'mc-leads-engine'); ?></div>
            </div>
        </div>

        <!-- Filter Form -->
        <form method="get" class="mc-analytics-filter-form">
            <input type="hidden" name="page" value="mc-leads-engine-analytics">
            <input type="hidden" name="paged" value="1">

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
                    <option value="ASC"  <?php selected($order, 'ASC');  ?>><?php esc_html_e('Ascending', 'mc-leads-engine'); ?></option>
                </select>
            </label>

            <label>
                <?php esc_html_e('Date Range:', 'mc-leads-engine'); ?>
                <select name="days">
                    <?php foreach (array(7 => '7 days', 14 => '14 days', 30 => '30 days', 90 => '90 days') as $d => $label) : ?>
                        <option value="<?php echo esc_attr($d); ?>" <?php selected($days, $d); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <button class="button button-primary" type="submit"><?php esc_html_e('Apply Filters', 'mc-leads-engine'); ?></button>

            <?php
            $export_url = add_query_arg(array(
                'page'             => 'mc-leads-engine-analytics',
                'survey_id'        => $survey_id,
                'orderby'          => $orderby,
                'order'            => $order,
                'export_analytics' => 1,
                '_wpnonce'         => wp_create_nonce('mc_leads_engine_export_analytics'),
            ), admin_url('admin.php'));
            ?>
            <a class="button button-secondary" href="<?php echo esc_url($export_url); ?>">
                <span class="dashicons dashicons-media-spreadsheet" style="vertical-align:middle; font-size:16px; margin-right:4px;"></span>
                <?php esc_html_e('Export to Excel', 'mc-leads-engine'); ?>
            </a>
        </form>

        <!-- Charts Flex Row (Lead Count, Revenue, Step Drop-off side-by-side) -->
        <div class="mc-analytics-flex-row">
            <!-- Leads Over Time Chart -->
            <?php echo mc_leads_engine_render_metric_chart($daily_stats, 'lead_count', __('Leads Over Time', 'mc-leads-engine'), 'var(--mc-brand)'); ?>

            <!-- Revenue Over Time Chart -->
            <?php echo mc_leads_engine_render_metric_chart($daily_stats, 'revenue', __('Revenue Over Time', 'mc-leads-engine'), 'var(--mc-green)', function($value) {
                return 'KES ' . number_format_i18n($value, 0);
            }); ?>

            <!-- Step Drop-off Funnel Panel -->
            <div class="mc-panel mc-step-dropoff-panel">
                <h2><?php esc_html_e('Step Drop-off Funnel', 'mc-leads-engine'); ?></h2>
                <div style="flex: 1; display: flex; flex-direction: column; justify-content: center; gap: 8px;">
                    <?php
                    $step_progress = mc_leads_engine_leads_repository()->get_step_dropoff($survey_id);
                    if (!empty($step_progress)) :
                        foreach ($step_progress as $sid => $steps) :
                            $peak = $steps ? max($steps) : 1;
                            $survey_data = mc_leads_engine_survey_repository()->get_survey($sid);
                            $survey_title = $survey_data['title'] ?? sprintf(__('Survey #%d', 'mc-leads-engine'), $sid);
                        ?>
                            <div style="margin-bottom: 12px;">
                                <h3 style="font-size: 11px; text-transform: uppercase; color: var(--mc-muted); margin: 0 0 6px 0;"><?php echo esc_html($survey_title); ?></h3>
                                <?php foreach ($steps as $step => $count) :
                                    $pct = $peak ? round(($count / $peak) * 100) : 0;
                                ?>
                                    <div class="mc-bar" style="margin: 6px 0;">
                                        <div style="display:flex; justify-content:space-between; font-size:11px; margin-bottom: 2px;">
                                            <span><?php echo esc_html(sprintf(__('Step %d', 'mc-leads-engine'), $step)); ?></span>
                                            <strong><?php echo esc_html(number_format_i18n((int) $count)); ?></strong>
                                        </div>
                                        <div class="mc-bar-track"><div class="mc-bar-fill" style="width: <?php echo esc_attr($pct); ?>%"></div></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div style="text-align: center; color: var(--mc-muted); padding: 20px;">
                            <span class="dashicons dashicons-chart-bar" style="font-size: 32px; width: 32px; height: 32px;"></span>
                            <p style="margin: 5px 0 0 0; font-size: 12px;"><?php esc_html_e('No step drop-off events recorded.', 'mc-leads-engine'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <!-- Traffic Sources (UTM Attribution) -->
            <?php if (!empty($utm_data)) : ?>
            <div class="mc-panel" style="margin-bottom: 0;">
                <h2 style="margin-top: 0; margin-bottom: 15px; font-size: 14px; font-weight: 700;"><?php esc_html_e('Traffic Sources (UTM)', 'mc-leads-engine'); ?></h2>
                <div style="overflow-x:auto;">
                    <table class="widefat striped" style="box-shadow: none; border: none; font-size: 12px;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th><?php esc_html_e('Source', 'mc-leads-engine'); ?></th>
                                <th><?php esc_html_e('Medium', 'mc-leads-engine'); ?></th>
                                <th><?php esc_html_e('Campaign', 'mc-leads-engine'); ?></th>
                                <th><?php esc_html_e('Leads', 'mc-leads-engine'); ?></th>
                                <th><?php esc_html_e('Avg Score', 'mc-leads-engine'); ?></th>
                                <th><?php esc_html_e('Avg Value', 'mc-leads-engine'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($utm_data as $utm_row) : ?>
                            <tr>
                                <td style="font-weight: 600;"><?php echo esc_html($utm_row['utm_source']); ?></td>
                                <td><?php echo esc_html($utm_row['utm_medium'] ?: '—'); ?></td>
                                <td><?php echo esc_html($utm_row['utm_campaign'] ?: '—'); ?></td>
                                <td><?php echo esc_html(number_format_i18n((int) $utm_row['lead_count'])); ?></td>
                                <td><?php echo mc_leads_score_badge((int) $utm_row['avg_score']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                                <td style="font-weight: 600; color: var(--mc-green);">KES <?php echo esc_html(number_format_i18n((float) $utm_row['avg_value'], 2)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else : ?>
            <div class="mc-panel" style="margin-bottom: 0; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; min-height: 200px;">
                <span class="dashicons dashicons-admin-site" style="font-size: 32px; width: 32px; height: 32px; color: var(--mc-muted);"></span>
                <h3 style="font-size: 13px; margin: 10px 0 5px 0;"><?php esc_html_e('No Traffic Sources', 'mc-leads-engine'); ?></h3>
                <p style="margin: 0; font-size: 11px; color: var(--mc-muted);"><?php esc_html_e('UTM parameters will appear once leads are tracked from campaign links.', 'mc-leads-engine'); ?></p>
            </div>
            <?php endif; ?>

            <!-- Answer Insights (only when a survey is selected) -->
            <?php if ($survey_id && !empty($answer_freq)) : ?>
            <div class="mc-panel" style="margin-bottom: 0;">
                <h2 style="margin-top: 0; margin-bottom: 15px; font-size: 14px; font-weight: 700;"><?php esc_html_e('Answer Insights', 'mc-leads-engine'); ?></h2>
                <div style="max-height: 320px; overflow-y: auto; padding-right: 5px;">
                    <?php foreach ($answer_freq as $qid => $qdata) :
                        $q_max = max(array_column($qdata['options'], 'count')) ?: 1;
                    ?>
                        <div class="mc-answer-insight" style="margin-bottom: 16px; border-bottom: 1px solid var(--mc-border); padding-bottom: 12px;">
                            <h4 style="margin: 0 0 8px 0; font-size: 12px; font-weight: 600; color: var(--mc-text);"><?php echo esc_html($qdata['question_text']); ?></h4>
                            <?php foreach ($qdata['options'] as $opt) :
                                $pct = round(($opt['count'] / $q_max) * 100);
                            ?>
                                <div class="mc-bar" style="margin: 4px 0; display: grid; grid-template-columns: 140px auto 1fr; align-items: center; gap: 8px;">
                                    <span style="font-size: 11px; color: var(--mc-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo esc_attr($opt['label']); ?>"><?php echo esc_html($opt['label']); ?></span>
                                    <strong style="font-size: 11px; color: var(--mc-text);"><?php echo esc_html($opt['count']); ?></strong>
                                    <div class="mc-bar-track" style="margin-top: 0;"><div class="mc-bar-fill" style="width:<?php echo esc_attr($pct); ?>%; background:#6366f1"></div></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else : ?>
            <div class="mc-panel" style="margin-bottom: 0; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; min-height: 200px;">
                <span class="dashicons dashicons-filter" style="font-size: 32px; width: 32px; height: 32px; color: var(--mc-muted);"></span>
                <h3 style="font-size: 13px; margin: 10px 0 5px 0;"><?php esc_html_e('Answer Insights', 'mc-leads-engine'); ?></h3>
                <p style="margin: 0; font-size: 11px; color: var(--mc-muted);"><?php esc_html_e('Select a specific survey from the filters above to unlock detailed answer frequency tracking.', 'mc-leads-engine'); ?></p>
            </div>
            <?php endif; ?>
        </div>

        <!-- All Submitted Data Table -->
        <div class="mc-panel">
            <h2 style="margin-top: 0; margin-bottom: 15px; font-size: 14px; font-weight: 700;"><?php esc_html_e('All Submitted Data', 'mc-leads-engine'); ?></h2>

            <?php
            $sort_id_url    = add_query_arg(array('orderby' => 'id',         'order' => ($orderby === 'id'         && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
            $sort_date_url  = add_query_arg(array('orderby' => 'created_at', 'order' => ($orderby === 'created_at'  && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
            $sort_price_url = add_query_arg(array('orderby' => 'total_price','order' => ($orderby === 'total_price' && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
            $sort_score_url = add_query_arg(array('orderby' => 'lead_score', 'order' => ($orderby === 'lead_score'  && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
            ?>

            <div style="overflow-x:auto">
                <table class="widefat striped mc-analytics-leads-table" style="box-shadow: none; border: 1px solid var(--mc-border);">
                    <thead>
                        <tr>
                            <th><a href="<?php echo esc_url($sort_id_url); ?>" style="text-decoration:none">
                                <?php esc_html_e('Lead ID', 'mc-leads-engine'); ?>
                                <?php if ($orderby === 'id') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:16px;width:16px;height:16px;vertical-align:middle"></span><?php endif; ?>
                            </a></th>
                            <th><a href="<?php echo esc_url($sort_date_url); ?>" style="text-decoration:none">
                                <?php esc_html_e('Date', 'mc-leads-engine'); ?>
                                <?php if ($orderby === 'created_at') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:16px;width:16px;height:16px;vertical-align:middle"></span><?php endif; ?>
                            </a></th>
                            <th><?php esc_html_e('Survey', 'mc-leads-engine'); ?></th>
                            <th><?php esc_html_e('Status', 'mc-leads-engine'); ?></th>
                            <th><?php esc_html_e('Client Details', 'mc-leads-engine'); ?></th>
                            <th><?php esc_html_e('Submitted Answers', 'mc-leads-engine'); ?></th>
                            <th><a href="<?php echo esc_url($sort_price_url); ?>" style="text-decoration:none">
                                <?php esc_html_e('Price', 'mc-leads-engine'); ?>
                                <?php if ($orderby === 'total_price') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:16px;width:16px;height:16px;vertical-align:middle"></span><?php endif; ?>
                            </a></th>
                            <th><a href="<?php echo esc_url($sort_score_url); ?>" style="text-decoration:none">
                                <?php esc_html_e('Score', 'mc-leads-engine'); ?>
                                <?php if ($orderby === 'lead_score') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:16px;width:16px;height:16px;vertical-align:middle"></span><?php endif; ?>
                            </a></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($all_leads)) : ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 24px; color: var(--mc-muted);">
                                <?php esc_html_e('No leads match the selected criteria.', 'mc-leads-engine'); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($all_leads as $lead) :
                        $is_booking   = mc_leads_is_booking($lead);
                        $survey_row   = mc_leads_engine_survey_repository()->get_survey($lead['survey_id']);
                        $l_name  = mc_leads_engine_leads_repository()->find_client_name($lead['id']);
                        $l_email = mc_leads_engine_leads_repository()->find_client_email($lead['id']);
                        $l_phone = mc_leads_engine_leads_repository()->find_client_phone($lead['id']);
                        $items   = mc_leads_engine_leads_repository()->build_answers_summary($lead, $questions_map, true);
                        $view_url = add_query_arg(array('page' => 'mc-leads-engine-leads', 'lead_id' => $lead['id']), admin_url('admin.php'));
                    ?>
                        <tr>
                            <td><a href="<?php echo esc_url($view_url); ?>" style="font-weight: 600;">#<?php echo esc_html($lead['id']); ?></a></td>
                            <td style="white-space: nowrap; color: var(--mc-muted);"><?php echo esc_html($lead['created_at']); ?></td>
                            <td>
                                <span class="dashicons <?php echo $is_booking ? 'dashicons-calendar-alt' : 'dashicons-media-document'; ?>" style="font-size:14px; width:14px; height:14px; vertical-align:middle; margin-right:4px; color:var(--mc-muted);"></span>
                                <?php echo $is_booking ? esc_html__('Bookings', 'mc-leads-engine') : esc_html($survey_row['title'] ?? $lead['survey_id']); ?>
                            </td>
                            <td><span class="mc-status-pill mc-status-<?php echo esc_attr($lead['status'] ?? 'new'); ?>"><?php echo esc_html(mc_leads_status_label($lead['status'] ?? 'new')); ?></span></td>
                            <td style="line-height: 1.3;">
                                <?php if ($l_name)  : ?><strong><?php echo esc_html($l_name); ?></strong><br><?php endif; ?>
                                <?php if ($l_email) : ?><span style="font-size: 10px; color: var(--mc-muted);"><?php echo esc_html($l_email); ?></span><br><?php endif; ?>
                                <?php if ($l_phone) : ?><span style="font-size: 10px; color: var(--mc-muted);"><?php echo esc_html($l_phone); ?></span><?php endif; ?>
                                <?php if (!$l_name && !$l_email && !$l_phone) : ?><span class="description">—</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($items)) : ?>
                                    <ul class="mc-analytics-answers-list">
                                        <?php foreach ($items as $item) : ?>
                                            <li><?php echo $item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else : ?>
                                    <span class="description">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight: 700; color: var(--mc-text);">KES <?php echo esc_html(number_format_i18n((float) $lead['total_price'], 2)); ?></td>
                            <td><?php echo mc_leads_score_badge($lead['lead_score']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td><a href="<?php echo esc_url($view_url); ?>" class="mc-db-view-btn"><?php esc_html_e('View Profile', 'mc-leads-engine'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1) : ?>
            <div class="mc-pagination">
                <?php if ($paged > 1) : ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg('paged', $paged - 1)); ?>">&laquo; <?php esc_html_e('Previous', 'mc-leads-engine'); ?></a>
                <?php endif; ?>
                <span><?php echo esc_html(sprintf(__('Page %d of %d', 'mc-leads-engine'), $paged, $total_pages)); ?></span>
                <?php if ($paged < $total_pages) : ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg('paged', $paged + 1)); ?>"><?php esc_html_e('Next', 'mc-leads-engine'); ?> &raquo;</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
