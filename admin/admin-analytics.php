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
    $settings  = mc_leads_engine_get_settings();
    $survey_id = absint($_GET['survey_id'] ?? 0);
    $status    = sanitize_key($_GET['status'] ?? 'all');
    $min_score = isset($_GET['min_score']) ? absint($_GET['min_score']) : 0;
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

    global $wpdb;
    $questions_rows = $wpdb->get_results("SELECT id, question_text FROM " . mc_leads_engine_table('survey_questions'), ARRAY_A);
    $questions_map  = array();
    if (is_array($questions_rows)) {
        foreach ($questions_rows as $q) {
            $questions_map[(int) $q['id']] = $q['question_text'];
        }
    }

    // Handle Excel Export
    if (!empty($_GET['export_analytics'])) {
        check_admin_referer('mc_leads_engine_export_analytics');
        
        $export_leads = mc_leads_engine_leads_repository()->export_rows(array(
            'survey_id' => $survey_id,
            'status'    => $status,
            'min_score' => $min_score,
            'orderby'   => $orderby,
            'order'     => $order,
            'limit'     => 10000,
        ));

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
        'status'    => $status,
        'min_score' => $min_score,
        'orderby'   => $orderby,
        'order'     => $order,
        'limit'     => $per_page,
        'offset'    => $offset,
    ));
    $total_leads  = mc_leads_engine_leads_repository()->count_leads(array(
        'survey_id' => $survey_id,
        'status'    => $status,
        'min_score' => $min_score,
    ));
    $total_pages  = (int) ceil($total_leads / $per_page);
    $daily_stats  = mc_leads_engine_leads_repository()->get_daily_lead_stats($days);
    $utm_data     = mc_leads_engine_leads_repository()->get_utm_attribution($days);
    $answer_freq  = $survey_id ? mc_leads_engine_leads_repository()->get_answer_frequency($survey_id) : array();
    ?>
    <div class="wrap mc-leads-engine-admin">
        <!-- Topbar Toolbar -->
        <div class="topbar" style="margin-bottom:20px; border-radius:10px; border:1px solid var(--line); box-shadow:var(--shadow-sm); padding:18px 28px; background:var(--surface); display:flex; justify-content:space-between; align-items:center;">
            <div>
                <div class="topbar-title" style="font-size:19px; font-weight:800; letter-spacing:-.3px; color:var(--text);"><?php esc_html_e('Analytics & Leads', 'mc-leads-engine'); ?></div>
                <div class="topbar-sub" style="font-size:12px; color:var(--muted); margin-top:3px;">
                    <?php if ($status !== 'all' || $min_score > 0) : ?>
                        <span style="color:var(--coral); font-weight:700;"><?php esc_html_e('Filters active', 'mc-leads-engine'); ?></span> · 
                        <a href="<?php echo esc_url(add_query_arg(array('page' => 'mc-leads-engine-analytics'), admin_url('admin.php'))); ?>" style="color:var(--muted); text-decoration:underline;"><?php esc_html_e('Clear all filters', 'mc-leads-engine'); ?></a>
                    <?php else : ?>
                        <?php esc_html_e('Performance, conversion rates and recent lead entries', 'mc-leads-engine'); ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php
            $export_url = add_query_arg(array(
                'page'             => 'mc-leads-engine-analytics',
                'survey_id'        => $survey_id,
                'status'           => $status,
                'min_score'        => $min_score,
                'orderby'          => $orderby,
                'order'            => $order,
                'export_analytics' => 1,
                '_wpnonce'         => wp_create_nonce('mc_leads_engine_export_analytics'),
            ), admin_url('admin.php'));
            ?>
            <a class="view-link" href="<?php echo esc_url($export_url); ?>" style="height:32px; padding:0 14px;">
                <?php esc_html_e('Export to Excel', 'mc-leads-engine'); ?>
            </a>
        </div>

        <!-- KPI Grid -->
        <div class="kpi-grid">
            <div class="kpi-card accent">
                <span class="kicon">📊</span>
                <div class="kpi-value"><?php echo esc_html(number_format_i18n($metrics['total_leads'])); ?></div>
                <div class="kpi-label"><?php esc_html_e('Total submissions', 'mc-leads-engine'); ?></div>
            </div>
            <div class="kpi-card">
                <span class="kicon">⚡</span>
                <div class="kpi-value"><?php echo esc_html(number_format_i18n($metrics['survey_starts'])); ?></div>
                <div class="kpi-label"><?php esc_html_e('Survey starts', 'mc-leads-engine'); ?></div>
            </div>
            <div class="kpi-card money">
                <span class="kicon">💰</span>
                <div class="kpi-value">KES <?php echo esc_html(number_format_i18n($metrics['revenue'], 0)); ?></div>
                <div class="kpi-label"><?php esc_html_e('Revenue estimate', 'mc-leads-engine'); ?></div>
            </div>
            <div class="kpi-card">
                <span class="kicon">🎯</span>
                <div class="kpi-value"><?php echo esc_html(number_format_i18n($metrics['conversion_rate'], 1)); ?>%</div>
                <div class="kpi-label"><?php esc_html_e('Conversion rate', 'mc-leads-engine'); ?></div>
            </div>
        </div>

        <!-- Filter bar -->
        <form method="get" class="filter-bar">
            <input type="hidden" name="page" value="mc-leads-engine-analytics">
            <input type="hidden" name="paged" value="1">

            <?php if ($min_score) : ?>
                <input type="hidden" name="min_score" value="<?php echo esc_attr($min_score); ?>">
            <?php endif; ?>

            <div class="filter-field">
                <label><?php esc_html_e('Survey', 'mc-leads-engine'); ?></label>
                <select class="filter-select" name="survey_id">
                    <option value="0"><?php esc_html_e('All surveys', 'mc-leads-engine'); ?></option>
                    <?php
                    $surveys = mc_leads_engine_survey_repository()->get_surveys(array('limit' => 100));
                    foreach ($surveys as $survey) :
                    ?>
                        <option value="<?php echo esc_attr($survey['id']); ?>" <?php selected($survey_id, $survey['id']); ?>>
                            <?php echo esc_html($survey['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label><?php esc_html_e('Status', 'mc-leads-engine'); ?></label>
                <select class="filter-select" name="status">
                    <option value="all" <?php selected($status, 'all'); ?>><?php esc_html_e('All statuses', 'mc-leads-engine'); ?></option>
                    <option value="new" <?php selected($status, 'new'); ?>><?php esc_html_e('New', 'mc-leads-engine'); ?></option>
                    <option value="contacted" <?php selected($status, 'contacted'); ?>><?php esc_html_e('Contacted', 'mc-leads-engine'); ?></option>
                    <option value="qualified" <?php selected($status, 'qualified'); ?>><?php esc_html_e('Qualified', 'mc-leads-engine'); ?></option>
                    <option value="proposal_sent" <?php selected($status, 'proposal_sent'); ?>><?php esc_html_e('Proposal Sent', 'mc-leads-engine'); ?></option>
                    <option value="won" <?php selected($status, 'won'); ?>><?php esc_html_e('Won', 'mc-leads-engine'); ?></option>
                    <option value="lost" <?php selected($status, 'lost'); ?>><?php esc_html_e('Lost', 'mc-leads-engine'); ?></option>
                </select>
            </div>

            <div class="filter-field">
                <label><?php esc_html_e('Sort by', 'mc-leads-engine'); ?></label>
                <select class="filter-select" name="orderby">
                    <option value="created_at" <?php selected($orderby, 'created_at'); ?>><?php esc_html_e('Date Created', 'mc-leads-engine'); ?></option>
                    <option value="total_price" <?php selected($orderby, 'total_price'); ?>><?php esc_html_e('Estimated Price', 'mc-leads-engine'); ?></option>
                    <option value="lead_score" <?php selected($orderby, 'lead_score'); ?>><?php esc_html_e('Lead Score', 'mc-leads-engine'); ?></option>
                    <option value="id" <?php selected($orderby, 'id'); ?>><?php esc_html_e('Lead ID', 'mc-leads-engine'); ?></option>
                </select>
            </div>

            <div class="filter-field">
                <label><?php esc_html_e('Order', 'mc-leads-engine'); ?></label>
                <select class="filter-select" name="order">
                    <option value="DESC" <?php selected($order, 'DESC'); ?>><?php esc_html_e('Descending', 'mc-leads-engine'); ?></option>
                    <option value="ASC"  <?php selected($order, 'ASC');  ?>><?php esc_html_e('Ascending', 'mc-leads-engine'); ?></option>
                </select>
            </div>

            <div class="filter-field">
                <label><?php esc_html_e('Date range', 'mc-leads-engine'); ?></label>
                <select class="filter-select" name="days">
                    <?php foreach (array(7 => 'Last 7 days', 14 => 'Last 14 days', 30 => 'Last 30 days', 90 => 'Last 90 days') as $d => $label) : ?>
                        <option value="<?php echo esc_attr($d); ?>" <?php selected($days, $d); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button class="view-link" type="submit" style="height:34px; margin-top:14px; font-size:12px; font-weight:700; border:none; cursor:pointer;"><?php esc_html_e('Apply filters', 'mc-leads-engine'); ?></button>
        </form>

        <!-- Drop-off Funnel Chart -->
        <?php
        $step_progress = mc_leads_engine_leads_repository()->get_step_dropoff($survey_id);
        if (!empty($step_progress)) :
            foreach ($step_progress as $sid => $steps) :
                $survey_row = mc_leads_engine_survey_repository()->get_survey($sid);
                $survey_title = $survey_row['title'] ?? sprintf(__('Survey #%d', 'mc-leads-engine'), $sid);
                $peak = $steps ? max($steps) : 1;
                if ($peak <= 0) {
                    $peak = 1;
                }
        ?>
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title"><?php echo esc_html(sprintf(__('Drop-off funnel — %s', 'mc-leads-engine'), $survey_title)); ?></h3>
                    <div class="panel-sub"><?php esc_html_e('Step-by-step progress conversion', 'mc-leads-engine'); ?></div>
                </div>
                <div class="funnel-wrap">
                    <?php 
                    $step_idx = 0;
                    $step_labels = array(
                        1 => __('Started', 'mc-leads-engine'),
                        2 => __('Step 2', 'mc-leads-engine'),
                        3 => __('Step 3', 'mc-leads-engine'),
                        4 => __('Step 4', 'mc-leads-engine'),
                        5 => __('Step 5', 'mc-leads-engine'),
                        6 => __('Completed', 'mc-leads-engine'),
                    );
                    foreach ($steps as $step => $count) :
                        $step_idx++;
                        $pct = round(($count / $peak) * 100);
                        $lbl = $step_labels[$step] ?? sprintf(__('Step %d', 'mc-leads-engine'), $step);
                        if ($step_idx > 1) {
                            echo '<div class="funnel-arrow">→</div>';
                        }
                    ?>
                        <div class="funnel-step">
                            <div class="funnel-bar-col">
                                <div class="funnel-bar" style="height: <?php echo esc_attr($pct); ?>%;"></div>
                            </div>
                            <div class="funnel-count"><?php echo esc_html(number_format_i18n((int) $count)); ?></div>
                            <div class="funnel-label"><?php echo esc_html($lbl); ?></div>
                            <div class="funnel-pct"><?php echo esc_html($pct); ?>%</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php 
            endforeach;
        endif; 
        ?>

        <!-- Traffic Sources Panel -->
        <?php if (!empty($utm_data)) : ?>
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title"><?php esc_html_e('Traffic Sources', 'mc-leads-engine'); ?></h3>
                <div class="panel-sub"><?php esc_html_e('UTM parameter attribution metrics', 'mc-leads-engine'); ?></div>
            </div>
            <div class="table-wrap">
                <table class="leads-table">
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
                            <td style="font-weight:700;"><?php echo esc_html($utm_row['utm_source']); ?></td>
                            <td><?php echo esc_html($utm_row['utm_medium'] ?: '—'); ?></td>
                            <td><?php echo esc_html($utm_row['utm_campaign'] ?: '—'); ?></td>
                            <td><?php echo esc_html(number_format_i18n((int) $utm_row['lead_count'])); ?></td>
                            <td>
                                <?php 
                                $avg_score = (int) $utm_row['avg_score'];
                                $score_class = 'score-cold';
                                $flame_chars = '';
                                if ($avg_score >= ($settings['score_hot_threshold'] ?? 80)) {
                                    $score_class = 'score-hot';
                                    $flame_chars = '<span class="flame">🔥</span> ';
                                } elseif ($avg_score >= ($settings['score_warm_threshold'] ?? 50)) {
                                    $score_class = 'score-warm';
                                    $flame_chars = '<span class="flame">⚡</span> ';
                                }
                                ?>
                                <span class="score-badge <?php echo esc_attr($score_class); ?>">
                                    <?php echo $flame_chars; ?><?php echo esc_html($avg_score); ?>
                                </span>
                            </td>
                            <td class="cell-price">KES <?php echo esc_html(number_format_i18n((float) $utm_row['avg_value'], 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Answer Insights Panel -->
        <?php if ($survey_id && !empty($answer_freq)) : ?>
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title"><?php esc_html_e('Answer Insights', 'mc-leads-engine'); ?></h3>
                <div class="panel-sub"><?php esc_html_e('Question response frequencies', 'mc-leads-engine'); ?></div>
            </div>
            <div style="padding: 0 18px 18px;">
                <?php foreach ($answer_freq as $qid => $qdata) :
                    $q_max = max(array_column($qdata['options'], 'count')) ?: 1;
                    if ($q_max <= 0) $q_max = 1;
                ?>
                    <div style="margin-bottom: 22px; border-bottom: 1px solid var(--line-soft); padding-bottom: 18px;">
                        <h4 style="font-size: 13px; font-weight: 700; color: var(--text); margin: 0 0 12px;"><?php echo esc_html($qdata['question_text']); ?></h4>
                        <?php foreach ($qdata['options'] as $opt) :
                            $pct = round(($opt['count'] / $q_max) * 100);
                        ?>
                            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px; font-size: 11.5px;">
                                <span style="min-width: 180px; font-weight: 600; color: var(--muted);"><?php echo esc_html($opt['label']); ?></span>
                                <strong style="width: 32px; font-family: var(--mono); color: var(--text);"><?php echo esc_html($opt['count']); ?></strong>
                                <div style="flex: 1; height: 8px; background: var(--line-soft); border-radius: 4px; overflow: hidden; position: relative;">
                                    <div style="height: 100%; border-radius: 4px; background: var(--coral); width: <?php echo esc_attr($pct); ?>%;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Entries Panel -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title"><?php esc_html_e('Recent leads', 'mc-leads-engine'); ?></h3>
                <div class="panel-sub">
                    <?php 
                    $start_entry = $offset + 1;
                    $end_entry   = min($offset + $per_page, $total_leads);
                    if ($total_leads > 0) {
                        printf(esc_html__('Showing %d–%d of %d entries', 'mc-leads-engine'), $start_entry, $end_entry, $total_leads);
                    } else {
                        esc_html_e('No entries found', 'mc-leads-engine');
                    }
                    ?>
                </div>
            </div>

            <?php
            $sort_id_url    = add_query_arg(array('orderby' => 'id',         'order' => ($orderby === 'id'         && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
            $sort_date_url  = add_query_arg(array('orderby' => 'created_at', 'order' => ($orderby === 'created_at'  && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
            $sort_price_url = add_query_arg(array('orderby' => 'total_price','order' => ($orderby === 'total_price' && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
            $sort_score_url = add_query_arg(array('orderby' => 'lead_score', 'order' => ($orderby === 'lead_score'  && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
            ?>

            <div class="table-wrap">
                <table class="leads-table">
                    <thead>
                        <tr>
                            <th style="width: 80px;"><a href="<?php echo esc_url($sort_id_url); ?>">
                                <?php esc_html_e('ID', 'mc-leads-engine'); ?>
                                <?php if ($orderby === 'id') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:12px;width:12px;height:12px;vertical-align:middle;"></span><?php endif; ?>
                            </a></th>
                            <th style="width: 140px;"><a href="<?php echo esc_url($sort_date_url); ?>">
                                <?php esc_html_e('Created date', 'mc-leads-engine'); ?>
                                <?php if ($orderby === 'created_at') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:12px;width:12px;height:12px;vertical-align:middle;"></span><?php endif; ?>
                            </a></th>
                            <th><?php esc_html_e('Survey', 'mc-leads-engine'); ?></th>
                            <th><?php esc_html_e('Status', 'mc-leads-engine'); ?></th>
                            <th><?php esc_html_e('Client details', 'mc-leads-engine'); ?></th>
                            <th><?php esc_html_e('Submitted answers', 'mc-leads-engine'); ?></th>
                            <th><a href="<?php echo esc_url($sort_price_url); ?>">
                                <?php esc_html_e('Est. price', 'mc-leads-engine'); ?>
                                <?php if ($orderby === 'total_price') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:12px;width:12px;height:12px;vertical-align:middle;"></span><?php endif; ?>
                            </a></th>
                            <th><a href="<?php echo esc_url($sort_score_url); ?>">
                                <?php esc_html_e('Score', 'mc-leads-engine'); ?>
                                <?php if ($orderby === 'lead_score') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:12px;width:12px;height:12px;vertical-align:middle;"></span><?php endif; ?>
                            </a></th>
                            <th style="width: 60px;"></th>
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
                            <td><a href="<?php echo esc_url($view_url); ?>" class="lead-id">#<?php echo esc_html($lead['id']); ?></a></td>
                            <td class="cell-date"><?php echo esc_html($lead['created_at']); ?></td>
                            <td class="cell-survey"><?php echo $is_booking ? esc_html__('Bookings', 'mc-leads-engine') : esc_html($survey_row['title'] ?? $lead['survey_id']); ?></td>
                            <?php 
                            $status = $lead['status'] ?? 'new';
                            $status_class = ($status === 'new') ? 'status-new' : 'status-contacted';
                            ?>
                            <td><span class="status-pill <?php echo esc_attr($status_class); ?>"><?php echo esc_html(mc_leads_status_label($status)); ?></span></td>
                            <td>
                                <?php if ($l_name)  : ?><div class="client-name"><?php echo esc_html($l_name); ?></div><?php endif; ?>
                                <?php if ($l_email) : ?><div class="client-line"><?php echo esc_html($l_email); ?></div><?php endif; ?>
                                <?php if ($l_phone) : ?><div class="client-line"><?php echo esc_html($l_phone); ?></div><?php endif; ?>
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
                            <td class="cell-price">KES <?php echo esc_html(number_format_i18n((float) $lead['total_price'], 2)); ?></td>
                            <td>
                                <?php 
                                $l_score = (int) $lead['lead_score'];
                                $badge_class = 'score-cold';
                                $flame = '';
                                if ($l_score >= ($settings['score_hot_threshold'] ?? 80)) {
                                    $badge_class = 'score-hot';
                                    $flame = '<span class="flame">🔥</span> ';
                                } elseif ($l_score >= ($settings['score_warm_threshold'] ?? 50)) {
                                    $badge_class = 'score-warm';
                                    $flame = '<span class="flame">⚡</span> ';
                                }
                                ?>
                                <span class="score-badge <?php echo esc_attr($badge_class); ?>">
                                    <?php echo $flame; ?><?php echo esc_html($l_score); ?>
                                </span>
                            </td>
                            <td><a href="<?php echo esc_url($view_url); ?>" class="view-link"><?php esc_html_e('View', 'mc-leads-engine'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Footer Pagination -->
            <div class="panel-foot">
                <div>
                    <?php 
                    $start_entry = $offset + 1;
                    $end_entry   = min($offset + $per_page, $total_leads);
                    if ($total_leads > 0) {
                        printf(esc_html__('Showing %d–%d of %d entries', 'mc-leads-engine'), $start_entry, $end_entry, $total_leads);
                    } else {
                        esc_html_e('No entries found', 'mc-leads-engine');
                    }
                    ?>
                </div>
                <?php if ($total_pages > 1) : ?>
                <div class="mc-pagination" style="display:flex; align-items:center; gap:8px;">
                    <?php if ($paged > 1) : ?>
                        <a class="view-link" href="<?php echo esc_url(add_query_arg('paged', $paged - 1)); ?>" style="background:var(--paper); color:var(--text); text-decoration:none;">&laquo; <?php esc_html_e('Prev', 'mc-leads-engine'); ?></a>
                    <?php endif; ?>
                    <span style="font-family:var(--mono); font-size:11.5px;"><?php echo esc_html(sprintf(__('Page %d of %d', 'mc-leads-engine'), $paged, $total_pages)); ?></span>
                    <?php if ($paged < $total_pages) : ?>
                        <a class="view-link" href="<?php echo esc_url(add_query_arg('paged', $paged + 1)); ?>" style="background:var(--paper); color:var(--text); text-decoration:none;"><?php esc_html_e('Next', 'mc-leads-engine'); ?> &raquo;</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}
