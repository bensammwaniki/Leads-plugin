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
        <h1><?php esc_html_e('Analytics', 'mc-leads-engine'); ?></h1>

        <!-- KPI Cards -->
        <div class="mc-leads-engine-cards">
            <div class="mc-card"><strong><?php echo esc_html(number_format_i18n($metrics['total_leads'])); ?></strong><span><?php esc_html_e('Total Submissions', 'mc-leads-engine'); ?></span></div>
            <div class="mc-card"><strong><?php echo esc_html(number_format_i18n($metrics['survey_starts'])); ?></strong><span><?php esc_html_e('Survey Starts', 'mc-leads-engine'); ?></span></div>
            <div class="mc-card"><strong><?php echo esc_html(number_format_i18n($metrics['revenue'], 2)); ?></strong><span><?php esc_html_e('Revenue Estimate', 'mc-leads-engine'); ?></span></div>
            <div class="mc-card"><strong><?php echo esc_html(number_format_i18n($metrics['conversion_rate'], 2)); ?>%</strong><span><?php esc_html_e('Conversion Rate', 'mc-leads-engine'); ?></span></div>
        </div>

        <!-- Filter form -->
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

            <button class="button button-primary" type="submit"><?php esc_html_e('Apply', 'mc-leads-engine'); ?></button>

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
                <?php esc_html_e('Export to Excel', 'mc-leads-engine'); ?>
            </a>
        </form>

        <!-- Charts row -->
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

        <!-- UTM Attribution Table -->
        <?php if (!empty($utm_data)) : ?>
        <div class="mc-panel">
            <h2><?php esc_html_e('Traffic Sources', 'mc-leads-engine'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
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
                        <td><?php echo esc_html($utm_row['utm_source']); ?></td>
                        <td><?php echo esc_html($utm_row['utm_medium'] ?: '—'); ?></td>
                        <td><?php echo esc_html($utm_row['utm_campaign'] ?: '—'); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) $utm_row['lead_count'])); ?></td>
                        <td><?php echo mc_leads_score_badge((int) $utm_row['avg_score']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                        <td>KES <?php echo esc_html(number_format_i18n((float) $utm_row['avg_value'], 2)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Answer Insights Panel (only when a survey is selected) -->
        <?php if ($survey_id && !empty($answer_freq)) : ?>
        <div class="mc-panel">
            <h2><?php esc_html_e('Answer Insights', 'mc-leads-engine'); ?></h2>
            <?php foreach ($answer_freq as $qid => $qdata) :
                $q_max = max(array_column($qdata['options'], 'count')) ?: 1;
            ?>
                <div class="mc-answer-insight">
                    <h4><?php echo esc_html($qdata['question_text']); ?></h4>
                    <?php foreach ($qdata['options'] as $opt) :
                        $pct = round(($opt['count'] / $q_max) * 100);
                    ?>
                        <div class="mc-bar" style="margin-bottom:4px">
                            <span style="min-width:180px;display:inline-block"><?php echo esc_html($opt['label']); ?></span>
                            <strong><?php echo esc_html($opt['count']); ?></strong>
                            <div class="mc-bar-track" style="flex:1"><div class="mc-bar-fill" style="width:<?php echo esc_attr($pct); ?>%;background:#6366f1"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- All Submitted Data Table -->
        <div class="mc-panel">
            <h2><?php esc_html_e('All Submitted Data', 'mc-leads-engine'); ?></h2>

            <?php
            $sort_id_url    = add_query_arg(array('orderby' => 'id',         'order' => ($orderby === 'id'         && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
            $sort_date_url  = add_query_arg(array('orderby' => 'created_at', 'order' => ($orderby === 'created_at'  && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
            $sort_price_url = add_query_arg(array('orderby' => 'total_price','order' => ($orderby === 'total_price' && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
            $sort_score_url = add_query_arg(array('orderby' => 'lead_score', 'order' => ($orderby === 'lead_score'  && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
            ?>

            <div style="overflow-x:auto">
                <table class="widefat striped mc-analytics-leads-table">
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
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($all_leads as $lead) :
                        $is_booking   = mc_leads_is_booking($lead);
                        $survey_row   = mc_leads_engine_survey_repository()->get_survey($lead['survey_id']);
                        $l_name  = mc_leads_engine_leads_repository()->find_client_name($lead['id']);
                        $l_email = mc_leads_engine_leads_repository()->find_client_email($lead['id']);
                        $l_phone = mc_leads_engine_leads_repository()->find_client_phone($lead['id']);
                        $items   = mc_leads_engine_leads_repository()->build_answers_summary($lead, $questions_map, true);
                    ?>
                        <tr>
                            <td><a href="<?php echo esc_url(add_query_arg(array('page' => 'mc-leads-engine-leads', 'lead_id' => $lead['id']), admin_url('admin.php'))); ?>">#<?php echo esc_html($lead['id']); ?></a></td>
                            <td><?php echo esc_html($lead['created_at']); ?></td>
                            <td><?php echo $is_booking ? esc_html__('Bookings', 'mc-leads-engine') : esc_html($survey_row['title'] ?? $lead['survey_id']); ?></td>
                            <td><span class="mc-status-pill mc-status-<?php echo esc_attr($lead['status'] ?? 'new'); ?>"><?php echo esc_html(mc_leads_status_label($lead['status'] ?? 'new')); ?></span></td>
                            <td>
                                <?php if ($l_name)  : ?><strong><?php esc_html_e('Name:', 'mc-leads-engine'); ?></strong> <?php echo esc_html($l_name); ?><br><?php endif; ?>
                                <?php if ($l_email) : ?><strong><?php esc_html_e('Email:', 'mc-leads-engine'); ?></strong> <?php echo esc_html($l_email); ?><br><?php endif; ?>
                                <?php if ($l_phone) : ?><strong><?php esc_html_e('Phone:', 'mc-leads-engine'); ?></strong> <?php echo esc_html($l_phone); ?><?php endif; ?>
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
                            <td><?php echo esc_html(number_format_i18n((float) $lead['total_price'], 2)); ?></td>
                            <td><?php echo mc_leads_score_badge($lead['lead_score']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
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
