<?php

if (!defined('ABSPATH')) {
    exit;
}

function mc_leads_engine_pct_class($value, $min = 0) {
    $pct = max((int) $min, min(100, (int) round((float) $value)));
    $pct = (int) (round($pct / 10) * 10);

    return 'mcle-pct-' . max(0, min(100, $pct));
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
                $height = max(10, round(($value / $max_value) * 100));
                $display_value = $formatter ? call_user_func($formatter, $value) : number_format_i18n($value, is_float($value) ? 2 : 0);
            ?>
                <div class="mc-chart-bar">
                    <div class="mc-chart-bar-track">
                        <div class="mc-chart-bar-fill <?php echo esc_attr(mc_leads_engine_pct_class($height, 10)); ?>"></div>
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

    // Enable error reporting inside the page to capture/display any execution issues
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    try {
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
            
            $export_leads = mc_leads_engine_leads_repository()->export_leads(array(
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

        $is_showing_demo = false;
        if (empty($all_leads) && $survey_id == 0 && $status == 'all' && $min_score == 0) {
            $is_showing_demo = true;
            $dummy_leads = array(
                array(
                    'id' => 39,
                    'created_at' => '2026-07-02 10:15:00',
                    'survey_id' => 1,
                    'status' => 'new',
                    'lead_score' => 0,
                    'total_price' => 10000,
                    'answers_json' => json_encode(array('source' => 'estimate')),
                    'client_name' => 'bensam',
                    'client_email' => 'bensammwaniki@gmail.com',
                    'client_phone' => '743491012',
                    'is_demo' => true
                ),
                array(
                    'id' => 38,
                    'created_at' => '2026-07-01 16:24:00',
                    'survey_id' => 1,
                    'status' => 'new',
                    'lead_score' => 80,
                    'total_price' => 62500,
                    'answers_json' => json_encode(array('org' => 'school', 'goal' => 'branding', 'size' => 'medium')),
                    'client_name' => 'bensammwaniki',
                    'client_email' => 'bensammwaniki@gmail.com',
                    'client_phone' => '',
                    'is_demo' => true
                ),
                array(
                    'id' => 37,
                    'created_at' => '2026-06-29 11:33:00',
                    'survey_id' => 1,
                    'status' => 'contacted',
                    'lead_score' => 105,
                    'total_price' => 49000,
                    'answers_json' => json_encode(array('org' => 'Small Business', 'goal' => 'leads, branding, payments')),
                    'client_name' => 'bensam',
                    'client_email' => 'bensammwaniki@gmail.com',
                    'client_phone' => '743491012',
                    'is_demo' => true
                ),
                array(
                    'id' => 36,
                    'created_at' => '2026-06-25 20:29:00',
                    'survey_id' => 1,
                    'status' => 'new',
                    'lead_score' => 0,
                    'total_price' => 10000,
                    'answers_json' => json_encode(array('source' => 'estimate')),
                    'client_name' => 'bensam',
                    'client_email' => 'bnm@gma.com',
                    'client_phone' => '',
                    'is_demo' => true
                ),
                array(
                    'id' => 35,
                    'created_at' => '2026-06-25 20:27:00',
                    'survey_id' => 1,
                    'status' => 'new',
                    'lead_score' => 75,
                    'total_price' => 26000,
                    'answers_json' => json_encode(array('org' => 'Startup', 'goal' => 'branding')),
                    'client_name' => 'bensam',
                    'client_email' => 'bensammwaniki@gmail.com',
                    'client_phone' => '743491012',
                    'is_demo' => true
                ),
                array(
                    'id' => 34,
                    'created_at' => '2026-06-25 20:14:00',
                    'survey_id' => 1,
                    'status' => 'new',
                    'lead_score' => 120,
                    'total_price' => 65000,
                    'answers_json' => json_encode(array('org' => 'corporate', 'goal' => 'payments')),
                    'client_name' => 'bensammwaniki',
                    'client_email' => 'bensammwaniki@gmail.com',
                    'client_phone' => '743491012',
                    'is_demo' => true
                ),
                array(
                    'id' => 33,
                    'created_at' => '2026-06-25 19:59:00',
                    'survey_id' => 1,
                    'status' => 'new',
                    'lead_score' => 185,
                    'total_price' => 102000,
                    'answers_json' => json_encode(array('org' => 'school, ngo', 'goal' => 'leads, branding, payments…')),
                    'client_name' => 'bensam',
                    'client_email' => 'bensammwaniki@gmail.com',
                    'client_phone' => '743491012',
                    'is_demo' => true
                ),
                array(
                    'id' => 32,
                    'created_at' => '2026-06-25 18:38:00',
                    'survey_id' => 2,
                    'status' => 'new',
                    'lead_score' => 260,
                    'total_price' => 119500,
                    'answers_json' => json_encode(array('org' => 'school, SB, ngo', 'msg' => 'my special tool')),
                    'client_name' => 'bensam',
                    'client_email' => 'bensammwaniki@gmail.com',
                    'client_phone' => '743491012',
                    'is_demo' => true
                ),
                array(
                    'id' => 31,
                    'created_at' => '2026-06-25 18:11:00',
                    'survey_id' => 2,
                    'status' => 'new',
                    'lead_score' => 10,
                    'total_price' => 10000,
                    'answers_json' => json_encode(array()),
                    'client_name' => 'Bensam',
                    'client_email' => 'bensammwaniki@gmail.com',
                    'client_phone' => '743491012',
                    'is_demo' => true
                ),
                array(
                    'id' => 30,
                    'created_at' => '2026-06-25 17:38:00',
                    'survey_id' => 2,
                    'status' => 'new',
                    'lead_score' => 10,
                    'total_price' => 10000,
                    'answers_json' => json_encode(array('msg' => 'Would love to meet')),
                    'client_name' => 'Bensam',
                    'client_email' => 'bensammwaniki@gmail.com',
                    'client_phone' => '743491012',
                    'is_demo' => true
                ),
            );
            $all_leads   = $dummy_leads;
            $total_leads = 10;
            $total_pages = 1;
            
            $metrics = array(
                'total_leads'     => 10,
                'survey_starts'   => 97,
                'revenue'         => 464000,
                'conversion_rate' => 10.3,
            );
        }

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



            <?php if ($is_showing_demo) : ?>
                <div style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e3a8a; padding:12px 16px; margin-bottom:20px; border-radius:var(--radius); font-size:12.5px; display:flex; align-items:center; gap:8px;">
                    <span style="font-size:16px;">💡</span>
                    <span><?php esc_html_e('Showing demo leads since no real data has been collected yet.', 'mc-leads-engine'); ?></span>
                </div>
            <?php endif; ?>

            <!-- Drop-off Funnel Chart -->
            <?php
            $step_progress = mc_leads_engine_leads_repository()->get_step_dropoff($survey_id);
            // If showing demo and step progress is empty, populate with mockup step funnel data
            if (empty($step_progress) && $is_showing_demo) {
                $step_progress = array(
                    1 => array(
                        1 => 73, // Opened
                        2 => 24, // Section 1
                        3 => 17, // Section 2
                        4 => 16, // Section 3
                        5 => 24, // Section 4
                        6 => 192, // Submitted
                    )
                );
            }

            if (!empty($step_progress)) :
                foreach ($step_progress as $sid => $steps) :
                    $survey_row = mc_leads_engine_survey_repository()->get_survey($sid);
                    $survey_title = $survey_row['title'] ?? ($is_showing_demo ? 'Web Project Estimate' : sprintf(__('Survey #%d', 'mc-leads-engine'), $sid));
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
                            1 => __('Opened', 'mc-leads-engine'),
                            2 => __('Section 1', 'mc-leads-engine'),
                            3 => __('Section 2', 'mc-leads-engine'),
                            4 => __('Section 3', 'mc-leads-engine'),
                            5 => __('Section 4', 'mc-leads-engine'),
                            6 => __('Submitted', 'mc-leads-engine'),
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
                                    <div class="funnel-bar <?php echo esc_attr(mc_leads_engine_pct_class($pct, 10)); ?>"></div>
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
                    <table class="dtable">
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
                                <td class="strong-cell"><?php echo esc_html($utm_row['utm_source']); ?></td>
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
                                <td class="mono-val"><?php echo esc_html(number_format_i18n(round((float) $utm_row['avg_value']))); ?></td>
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
                <div class="answer-insights">
                    <?php foreach ($answer_freq as $qid => $qdata) :
                        $q_max = max(array_column($qdata['options'], 'count')) ?: 1;
                        if ($q_max <= 0) $q_max = 1;
                    ?>
                        <div class="answer-insight-group">
                            <h4 class="answer-insight-title"><?php echo esc_html($qdata['question_text']); ?></h4>
                            <?php foreach ($qdata['options'] as $opt) :
                                $pct = round(($opt['count'] / $q_max) * 100);
                            ?>
                                <div class="answer-insight-row">
                                    <span class="answer-insight-label"><?php echo esc_html($opt['label']); ?></span>
                                    <strong class="answer-insight-count"><?php echo esc_html($opt['count']); ?></strong>
                                    <div class="answer-insight-track">
                                        <div class="answer-insight-fill <?php echo esc_attr(mc_leads_engine_pct_class($pct)); ?>"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- All Submitted Data Panel -->
            <div class="panel">
                <div class="panel-header" style="padding-bottom:14px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
                    <div>
                        <h3 class="panel-title"><?php esc_html_e('All submitted data', 'mc-leads-engine'); ?></h3>
                        <div class="panel-sub"><?php echo esc_html(sprintf(_n('%d lead', '%d leads', $total_leads, 'mc-leads-engine'), $total_leads)); ?></div>
                    </div>

                    <form method="get" class="filter-bar" style="margin:0; padding:0; background:transparent; border:none; box-shadow:none; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                        <input type="hidden" name="page" value="mc-leads-engine-analytics">
                        <input type="hidden" name="paged" value="1">

                        <?php if ($min_score) : ?>
                            <input type="hidden" name="min_score" value="<?php echo esc_attr($min_score); ?>">
                        <?php endif; ?>

                        <div class="filter-field">
                            <select class="filter-select" name="survey_id" style="height:32px; min-width:120px; font-size:12px;">
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
                            <select class="filter-select" name="status" style="height:32px; min-width:110px; font-size:12px;">
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
                            <select class="filter-select" name="orderby" style="height:32px; min-width:110px; font-size:12px;">
                                <option value="created_at" <?php selected($orderby, 'created_at'); ?>><?php esc_html_e('Date Created', 'mc-leads-engine'); ?></option>
                                <option value="total_price" <?php selected($orderby, 'total_price'); ?>><?php esc_html_e('Estimated Price', 'mc-leads-engine'); ?></option>
                                <option value="lead_score" <?php selected($orderby, 'lead_score'); ?>><?php esc_html_e('Lead Score', 'mc-leads-engine'); ?></option>
                                <option value="id" <?php selected($orderby, 'id'); ?>><?php esc_html_e('Lead ID', 'mc-leads-engine'); ?></option>
                            </select>
                        </div>

                        <div class="filter-field">
                            <select class="filter-select" name="order" style="height:32px; min-width:100px; font-size:12px;">
                                <option value="DESC" <?php selected($order, 'DESC'); ?>><?php esc_html_e('Descending', 'mc-leads-engine'); ?></option>
                                <option value="ASC"  <?php selected($order, 'ASC');  ?>><?php esc_html_e('Ascending', 'mc-leads-engine'); ?></option>
                            </select>
                        </div>

                        <div class="filter-field">
                            <select class="filter-select" name="days" style="height:32px; min-width:110px; font-size:12px;">
                                <?php foreach (array(7 => 'Last 7 days', 14 => 'Last 14 days', 30 => 'Last 30 days', 90 => 'Last 90 days') as $d => $label) : ?>
                                    <option value="<?php echo esc_attr($d); ?>" <?php selected($days, $d); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button class="btn primary" type="submit" style="height:32px; line-height:30px; font-size:12px; padding:0 12px;"><?php esc_html_e('Apply filters', 'mc-leads-engine'); ?></button>
                    </form>
                </div>

                <?php
                $sort_id_url    = add_query_arg(array('orderby' => 'id',         'order' => ($orderby === 'id'         && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
                $sort_date_url  = add_query_arg(array('orderby' => 'created_at', 'order' => ($orderby === 'created_at'  && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
                $sort_price_url = add_query_arg(array('orderby' => 'total_price','order' => ($orderby === 'total_price' && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
                $sort_score_url = add_query_arg(array('orderby' => 'lead_score', 'order' => ($orderby === 'lead_score'  && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
                ?>

                <div class="table-wrap">
                    <table class="dtable">
                        <thead>
                            <tr>
                                <th><a href="<?php echo esc_url($sort_id_url); ?>">
                                    <?php esc_html_e('Lead ID', 'mc-leads-engine'); ?>
                                    <?php if ($orderby === 'id') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:12px;width:12px;height:12px;vertical-align:middle;"></span><?php endif; ?>
                                </a></th>
                                <th><a href="<?php echo esc_url($sort_date_url); ?>">
                                    <?php esc_html_e('Date', 'mc-leads-engine'); ?>
                                    <?php if ($orderby === 'created_at') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:12px;width:12px;height:12px;vertical-align:middle;"></span><?php endif; ?>
                                </a></th>
                                <th><?php esc_html_e('Survey', 'mc-leads-engine'); ?></th>
                                <th><?php esc_html_e('Status', 'mc-leads-engine'); ?></th>
                                <th><?php esc_html_e('Client', 'mc-leads-engine'); ?></th>
                                <th><?php esc_html_e('Answers', 'mc-leads-engine'); ?></th>
                                <th><a href="<?php echo esc_url($sort_price_url); ?>">
                                    <?php esc_html_e('Price', 'mc-leads-engine'); ?>
                                    <?php if ($orderby === 'total_price') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:12px;width:12px;height:12px;vertical-align:middle;"></span><?php endif; ?>
                                </a></th>
                                <th><a href="<?php echo esc_url($sort_score_url); ?>">
                                    <?php esc_html_e('Score', 'mc-leads-engine'); ?>
                                    <?php if ($orderby === 'lead_score') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:12px;width:12px;height:12px;vertical-align:middle;"></span><?php endif; ?>
                                </a></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($all_leads)) : ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 24px; color: var(--muted);">
                                    <?php esc_html_e('No leads match the selected criteria.', 'mc-leads-engine'); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($all_leads as $lead) :
                            $is_booking   = mc_leads_is_booking($lead);
                            $survey_row   = mc_leads_engine_survey_repository()->get_survey($lead['survey_id']);
                            $survey_title = $is_booking ? __('Bookings', 'mc-leads-engine') : ($survey_row['title'] ?? ($is_showing_demo ? 'Web Project Estimate' : $lead['survey_id']));
                            $l_name       = !empty($lead['is_demo']) ? $lead['client_name']  : mc_leads_engine_leads_repository()->find_client_name($lead['id']);
                            $l_email      = !empty($lead['is_demo']) ? $lead['client_email'] : mc_leads_engine_leads_repository()->find_client_email($lead['id']);
                            $l_phone      = !empty($lead['is_demo']) ? $lead['client_phone'] : mc_leads_engine_leads_repository()->find_client_phone($lead['id']);
                            $view_url     = add_query_arg(array('page' => 'mc-leads-engine-leads', 'lead_id' => $lead['id']), admin_url('admin.php'));

                            $display_name = $l_name ?: ($l_email ?: sprintf(__('Lead #%d', 'mc-leads-engine'), $lead['id']));
                            $created_ts   = strtotime($lead['created_at']);
                            $status_key   = $lead['status'] ?? 'new';
                            $l_score      = (int) $lead['lead_score'];
                            $band         = mc_leads_score_band($l_score);
                            $band_label   = mc_leads_score_band_label($band);

                            // Build answer chips from answers_json and CF7 data
                            $chips = array();
                            $answers = json_decode($lead['answers_json'] ?? '[]', true);
                            if (is_array($answers)) {
                                foreach ($answers as $q_id => $val) {
                                    $q_text  = $questions_map[(int) $q_id] ?? sprintf(__('Q#%d', 'mc-leads-engine'), $q_id);
                                    $label   = (mb_strlen($q_text) > 12) ? mb_substr($q_text, 0, 10) . '…' : $q_text;
                                    $val_str = is_array($val) ? implode(', ', $val) : (string) $val;
                                    if ($val_str !== '') {
                                        $chips[] = array(
                                            'label' => $label,
                                            'value' => $val_str,
                                            'full_label' => $q_text
                                        );
                                    }
                                }
                            }

                            $cf7_rows = mc_leads_engine_leads_repository()->get_cf7_data($lead['id']);
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
                                        $chips[] = array(
                                            'label' => $key,
                                            'value' => $val_str,
                                            'full_label' => $key
                                        );
                                    }
                                }
                            }

                            $max_chips = 3;
                            $count_chips = count($chips);
                            $visible_chips = array_slice($chips, 0, $max_chips);
                            $answer_tooltip_parts = array();
                            foreach ($chips as $c) {
                                $answer_tooltip_parts[] = $c['full_label'] . ': ' . $c['value'];
                            }
                            $answer_title = implode("\n", $answer_tooltip_parts);
                        ?>
                            <tr>
                                <td class="mono-id"><a href="<?php echo esc_url($view_url); ?>" class="lead-id">#<?php echo esc_html($lead['id']); ?></a></td>
                                <td class="cell-date">
                                    <?php if ($created_ts) : ?>
                                        <?php echo esc_html(wp_date('Y-m-d', $created_ts)); ?><br>
                                        <?php echo esc_html(wp_date('H:i', $created_ts)); ?>
                                    <?php else : ?>
                                        <?php echo esc_html($lead['created_at']); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="cell-survey"><?php echo esc_html($survey_title); ?></td>
                                <td><span class="status-pill status-<?php echo esc_attr($status_key); ?>"><?php echo esc_html(mc_leads_status_label($status_key)); ?></span></td>
                                <td>
                                    <div class="client-name"><?php echo esc_html($display_name); ?></div>
                                    <?php if ($l_email) : ?><div class="client-line"><?php echo esc_html($l_email); ?></div><?php endif; ?>
                                    <?php if ($l_phone) : ?><div class="client-line"><?php echo esc_html($l_phone); ?></div><?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $max_answers = 6;
                                    $count_answers = count($chips);
                                    $visible_answers = array_slice($chips, 0, $max_answers);
                                    ?>
                                    <?php if (empty($chips)) : ?>
                                        <span class="answers-empty" style="color: var(--muted); font-style: italic; font-size: 11.5px;"><?php esc_html_e('No answers recorded', 'mc-leads-engine'); ?></span>
                                    <?php else : ?>
                                        <ul class="mc-answers-list" style="margin: 0; padding-left: 14px; list-style-type: disc; color: var(--text); font-size: 12.5px; line-height: 1.6;">
                                            <?php foreach ($visible_answers as $chip) : ?>
                                                <li style="margin-bottom: 4px;">
                                                    <strong><?php echo esc_html($chip['full_label']); ?>:</strong>
                                                    <span style="color: #52525b; margin-left: 4px;"><?php echo esc_html($chip['value']); ?></span>
                                                </li>
                                            <?php endforeach; ?>
                                            <?php if ($count_answers > $max_answers) : ?>
                                                <li style="margin-bottom: 4px; color: var(--muted-2);">.......</li>
                                            <?php endif; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                                <td class="cell-price"><?php echo esc_html(number_format_i18n(round((float) $lead['total_price']))); ?></td>
                                <td>
                                    <?php 
                                    $badge_class = $band === 'hot' ? 'score-hot' : ($band === 'warm' ? 'score-warm' : 'score-cold');
                                    $flame = $band === 'hot' ? '<span class="flame">🔥</span>' : '';
                                    ?>
                                    <span class="score-badge <?php echo esc_attr($badge_class); ?>">
                                        <?php echo $flame; ?><?php echo esc_html($band_label); ?> &middot; <?php echo esc_html($l_score); ?>
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
                            printf(esc_html__('Showing %d of %d leads', 'mc-leads-engine'), $end_entry, $total_leads);
                        } else {
                            esc_html_e('No entries found', 'mc-leads-engine');
                        }
                        ?>
                    </div>
                    <?php if ($total_pages > 1) : ?>
                    <div class="mc-pagination">
                        <?php if ($paged > 1) : ?>
                            <a class="btn" href="<?php echo esc_url(add_query_arg('paged', $paged - 1)); ?>">&laquo; <?php esc_html_e('Prev', 'mc-leads-engine'); ?></a>
                        <?php endif; ?>
                        <span class="pagination-count" style="font-weight: 600; font-size: 12px; color: var(--muted); margin: 0 10px;"><?php echo esc_html(sprintf(__('Page %d of %d', 'mc-leads-engine'), $paged, $total_pages)); ?></span>
                        <?php if ($paged < $total_pages) : ?>
                            <a class="btn" href="<?php echo esc_url(add_query_arg('paged', $paged + 1)); ?>"><?php esc_html_e('Next', 'mc-leads-engine'); ?> &raquo;</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    } catch (Throwable $e) {
        echo '<div style="background:#fee2e2; border:1px solid #fca5a5; color:#991b1b; padding:20px; margin:20px; border-radius:8px; font-family: sans-serif;">';
        echo '<h3>PHP Fatal Error in Analytics Page:</h3>';
        echo '<p><strong>Message:</strong> ' . esc_html($e->getMessage()) . '</p>';
        echo '<p><strong>File:</strong> ' . esc_html($e->getFile()) . ' on line ' . esc_html($e->getLine()) . '</p>';
        echo '<pre style="background: #fff; padding: 10px; border-radius: 4px; overflow-x: auto;">' . esc_html($e->getTraceAsString()) . '</pre>';
        echo '</div>';
    }
}
