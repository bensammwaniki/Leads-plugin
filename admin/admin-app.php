<?php

if (!defined('ABSPATH')) {
    exit;
}

function mc_leads_engine_admin_allowed_panels() {
    return array('dashboard', 'surveys', 'analytics', 'settings');
}

function mc_leads_engine_admin_get_panel() {
    $panel = sanitize_key($_GET['mc_panel'] ?? 'dashboard');
    return in_array($panel, mc_leads_engine_admin_allowed_panels(), true) ? $panel : 'dashboard';
}

function mc_leads_engine_admin_panel_title($panel) {
    $titles = array(
        'dashboard' => __('Dashboard', 'mc-leads-engine'),
        'surveys' => __('Surveys', 'mc-leads-engine'),
        'analytics' => __('Analytics & Leads', 'mc-leads-engine'),
        'settings' => __('Settings', 'mc-leads-engine'),
    );

    return $titles[$panel] ?? __('Dashboard', 'mc-leads-engine');
}

function mc_leads_engine_admin_panel_icon($panel) {
    $icons = array(
        'dashboard' => 'dashicons-chart-area',
        'surveys' => 'dashicons-media-document',
        'analytics' => 'dashicons-chart-line',
        'settings' => 'dashicons-admin-settings',
    );

    return $icons[$panel] ?? 'dashicons-admin-generic';
}

function mc_leads_engine_admin_render_chart_bars($values, $class_name, $accent_class = '') {
    $values = array_values(array_map('floatval', is_array($values) ? $values : array()));
    $max_value = $values ? max($values) : 1;
    $html = '<div class="' . esc_attr($class_name) . '">';

    foreach ($values as $index => $value) {
        $height = $max_value > 0 ? round(($value / $max_value) * 100) : 0;
        $html .= '<div class="' . esc_attr(trim('mc-chart-bar ' . $accent_class . ($index === count($values) - 1 ? ' hi' : ''))) . '" style="height:' . esc_attr(max(6, $height)) . '%"></div>';
    }

    $html .= '</div>';

    return $html;
}

function mc_leads_engine_admin_get_survey_summary($survey, $analytics, $bundle = null) {
    $survey_id = absint($survey['id'] ?? 0);
    $sections = $bundle && !empty($bundle['sections']) ? $bundle['sections'] : array();
    $question_count = 0;
    foreach ($sections as $section) {
        $question_count += !empty($section['questions']) ? count($section['questions']) : 0;
    }

    $completions = (int) ($analytics['survey_completions'][$survey_id] ?? 0);
    $revenue = (float) ($analytics['revenue_by_survey'][$survey_id] ?? 0);
    $avg_price = $completions ? round($revenue / $completions, 2) : 0;

    return array(
        'id' => $survey_id,
        'title' => $survey['title'] ?? '',
        'status' => $survey['status'] ?? 'draft',
        'questions' => $question_count,
        'leads' => $completions,
        'avg_price' => $avg_price,
        'shortcode' => mc_leads_engine_get_survey_shortcode($survey_id),
        'bundle' => $bundle,
    );
}

function mc_leads_engine_admin_selected_url($panel, $survey_id = 0, $section_id = 0, $question_id = 0) {
    $page_map = array(
        'dashboard' => 'mc-leads-engine',
        'surveys' => 'mc-leads-engine-surveys',
        'analytics' => 'mc-leads-engine-analytics',
        'settings' => 'mc-leads-engine-settings',
    );

    $args = array(
        'page' => $page_map[$panel] ?? 'mc-leads-engine',
        'mc_panel' => $panel,
    );
    if ($survey_id) {
        $args['survey_id'] = absint($survey_id);
    }
    if ($section_id) {
        $args['section_id'] = absint($section_id);
    }
    if ($question_id) {
        $args['question_id'] = absint($question_id);
    }

    return add_query_arg($args, admin_url('admin.php'));
}

function mc_leads_engine_handle_admin_survey_actions() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'mc-leads-engine'));
    }

    $action = sanitize_key($_POST['mc_leads_engine_action'] ?? '');
    if (!$action) {
        return;
    }

    check_admin_referer('mc_leads_engine_admin_action', 'mc_leads_engine_nonce');

    $survey_repo = mc_leads_engine_survey_repository();
    $section_repo = new MC_Leads_Engine_Section();
    $question_repo = new MC_Leads_Engine_Question();
    $cf7_repo = mc_leads_engine_cf7_integration();

    switch ($action) {
        case 'save_survey':
            $survey_id = absint($_POST['survey_id'] ?? 0);
            $payload = array(
                'title' => $_POST['title'] ?? '',
                'description' => $_POST['description'] ?? '',
                'status' => $_POST['status'] ?? 'draft',
            );
            if ($survey_id) {
                $survey_repo->update_survey($survey_id, $payload);
            } else {
                $survey_id = $survey_repo->create_survey($payload);
            }

            if ($survey_id) {
                mc_leads_engine_update_survey_settings($survey_id, array(
                    'final_step_title'  => $_POST['final_step_title'] ?? '',
                    'show_final_price'  => isset($_POST['show_final_price']) ? 1 : 0,
                    'show_final_score'  => isset($_POST['show_final_score']) ? 1 : 0,
                    'final_button_text' => $_POST['final_button_text'] ?? '',
                    'final_message'     => $_POST['final_message'] ?? '',
                ));
            }

            $redirect_to = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';
            if ($redirect_to) {
                $redirect = mc_leads_engine_get_redirect_target($redirect_to);
            } else {
                $redirect = mc_leads_engine_get_redirect_target(add_query_arg(array('page' => 'mc-leads-engine-surveys', 'mc_panel' => 'surveys', 'survey_id' => $survey_id, 'updated' => 1), admin_url('admin.php')));
            }
            wp_safe_redirect($redirect);
            exit;

        case 'delete_survey':
            $survey_repo->delete_survey(absint($_POST['survey_id'] ?? 0));
            $redirect = mc_leads_engine_get_redirect_target(add_query_arg(array('page' => 'mc-leads-engine-surveys', 'mc_panel' => 'surveys', 'deleted' => 1), admin_url('admin.php')));
            wp_safe_redirect($redirect);
            exit;

        case 'save_section':
            $section_id = $section_repo->save_section(array(
                'id' => absint($_POST['section_id'] ?? 0),
                'survey_id' => absint($_POST['survey_id'] ?? 0),
                'title' => $_POST['section_title'] ?? '',
                'description' => $_POST['section_description'] ?? '',
                'order_index' => absint($_POST['order_index'] ?? 0),
            ));
            $redirect_to = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';
            if ($redirect_to) {
                $redirect = mc_leads_engine_get_redirect_target($redirect_to);
            } else {
                $redirect = mc_leads_engine_get_redirect_target(add_query_arg(array('page' => 'mc-leads-engine-surveys', 'mc_panel' => 'surveys', 'survey_id' => absint($_POST['survey_id'] ?? 0), 'section_id' => $section_id, 'updated' => 1), admin_url('admin.php')));
            }
            wp_safe_redirect($redirect);
            exit;

        case 'delete_section':
            $section_repo->delete_section(absint($_POST['section_id'] ?? 0));
            $redirect = mc_leads_engine_get_redirect_target(add_query_arg(array('page' => 'mc-leads-engine-surveys', 'mc_panel' => 'surveys', 'survey_id' => absint($_POST['survey_id'] ?? 0), 'deleted' => 1), admin_url('admin.php')));
            wp_safe_redirect($redirect);
            exit;

        case 'save_question':
            $question_id = $question_repo->save_question(array(
                'id' => absint($_POST['question_id'] ?? 0),
                'section_id' => absint($_POST['section_id'] ?? 0),
                'question_text' => $_POST['question_text'] ?? '',
                'type' => $_POST['type'] ?? 'text',
                'required' => !empty($_POST['required']) ? 1 : 0,
                'order_index' => absint($_POST['order_index'] ?? 0),
                'options' => isset($_POST['options']) && is_array($_POST['options']) ? $_POST['options'] : array(),
            ));
            $redirect_to = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';
            if ($redirect_to) {
                $redirect = mc_leads_engine_get_redirect_target($redirect_to);
            } else {
                $redirect = mc_leads_engine_get_redirect_target(add_query_arg(array('page' => 'mc-leads-engine-surveys', 'mc_panel' => 'surveys', 'survey_id' => absint($_POST['survey_id'] ?? 0), 'question_id' => $question_id, 'updated' => 1), admin_url('admin.php')));
            }
            wp_safe_redirect($redirect);
            exit;

        case 'delete_question':
            $question_repo->delete_question(absint($_POST['question_id'] ?? 0));
            $redirect = mc_leads_engine_get_redirect_target(add_query_arg(array('page' => 'mc-leads-engine-surveys', 'mc_panel' => 'surveys', 'survey_id' => absint($_POST['survey_id'] ?? 0), 'deleted' => 1), admin_url('admin.php')));
            wp_safe_redirect($redirect);
            exit;

        case 'save_cf7_integration':
            $rules = array();
            $rules_raw = trim((string) ($_POST['mapping_rules'] ?? ''));
            if ($rules_raw !== '') {
                $decoded = json_decode(wp_unslash($rules_raw), true);
                if (is_array($decoded)) {
                    $rules = $decoded;
                }
            }
            $cf7_repo->save_integration(absint($_POST['survey_id'] ?? 0), absint($_POST['cf7_form_id'] ?? 0), $rules);
            $redirect_to = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';
            if ($redirect_to) {
                $redirect = mc_leads_engine_get_redirect_target($redirect_to);
            } else {
                $redirect = mc_leads_engine_get_redirect_target(add_query_arg(array('page' => 'mc-leads-engine-surveys', 'mc_panel' => 'surveys', 'survey_id' => absint($_POST['survey_id'] ?? 0), 'updated' => 1), admin_url('admin.php')));
            }
            wp_safe_redirect($redirect);
            exit;
    }
}
add_action('admin_init', 'mc_leads_engine_handle_admin_survey_actions');

function mc_leads_engine_render_surveys_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'mc-leads-engine'));
    }

    mc_leads_engine_render_admin_app('surveys');
}

function mc_leads_engine_render_admin_app($forced_panel = null) {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'mc-leads-engine'));
    }

    $panel = $forced_panel && in_array($forced_panel, mc_leads_engine_admin_allowed_panels(), true)
        ? $forced_panel
        : mc_leads_engine_admin_get_panel();
    $settings = mc_leads_engine_get_settings();
    $analytics = mc_leads_engine_leads_repository()->get_dashboard_metrics();
    $analytics_data = $analytics['analytics'];
    $surveys = mc_leads_engine_survey_repository()->get_surveys(array('limit' => 100));
    $leads = mc_leads_engine_leads_repository()->get_leads(array('limit' => 100));
    $daily_stats     = mc_leads_engine_leads_repository()->get_daily_lead_stats(30);
    $pipeline_counts = mc_leads_engine_leads_repository()->get_pipeline_counts();
    $recent_leads    = mc_leads_engine_leads_repository()->get_leads(array('limit' => 5, 'orderby' => 'created_at', 'order' => 'DESC'));

    $selected_survey_id = isset($_GET['survey_id']) ? absint($_GET['survey_id']) : 0;
    if ($panel !== 'surveys' && !$selected_survey_id && !empty($surveys)) {
        $selected_survey_id = absint($surveys[0]['id']);
    }

    $selected_bundle = $selected_survey_id ? mc_leads_engine_survey_repository()->get_survey_bundle($selected_survey_id) : null;
    $selected_survey = $selected_bundle['survey'] ?? null;
    $sections = $selected_bundle['sections'] ?? array();
    $selected_section_id = absint($_GET['section_id'] ?? 0);
    $selected_question_id = absint($_GET['question_id'] ?? 0);

    if (!$selected_section_id && !empty($sections)) {
        $selected_section_id = absint($sections[0]['id']);
    }

    $selected_section = null;
    $selected_question = null;
    $selected_questions = array();
    foreach ($sections as $section) {
        if (absint($section['id']) === $selected_section_id) {
            $selected_section = $section;
            $selected_questions = $section['questions'] ?? array();
            break;
        }
    }

    $creating_new_question = !empty($_GET['new_question']);
    if (!$creating_new_question && !$selected_question_id && !empty($selected_questions)) {
        $selected_question_id = absint($selected_questions[0]['id']);
    }
    foreach ($selected_questions as $question) {
        if (absint($question['id']) === $selected_question_id) {
            $selected_question = $question;
            break;
        }
    }

    $survey_summaries = array();
    foreach ($surveys as $survey) {
        $bundle = mc_leads_engine_survey_repository()->get_survey_bundle($survey['id']);
        $survey_summaries[] = mc_leads_engine_admin_get_survey_summary($survey, $analytics_data, $bundle);
    }

    $pricing_rules = array();
    $pricing_rules_raw = trim((string) ($settings['default_pricing_rules_json'] ?? ''));
    if ($pricing_rules_raw !== '') {
        $decoded = json_decode($pricing_rules_raw, true);
        if (is_array($decoded)) {
            $pricing_rules = $decoded;
        }
    }

    $current_url = mc_leads_engine_admin_selected_url($panel, $selected_survey_id, $selected_section_id, $selected_question_id);
    $dashboard_url = mc_leads_engine_admin_selected_url('dashboard');
    $surveys_url = mc_leads_engine_admin_selected_url('surveys', $selected_survey_id);
    $add_question_url = add_query_arg(array('question_id' => 0, 'new_question' => 1), mc_leads_engine_admin_selected_url('surveys', $selected_survey_id, $selected_section_id, 0));
    $analytics_url = mc_leads_engine_admin_selected_url('analytics', $selected_survey_id);
    $settings_url = mc_leads_engine_admin_selected_url('settings');

    $lead_daily_values = array_map(function ($row) {
        return (float) ($row['lead_count'] ?? 0);
    }, $daily_stats);
    $revenue_daily_values = array_map(function ($row) {
        return (float) ($row['revenue'] ?? 0);
    }, $daily_stats);
    $survey_peak_leads = 1;
    foreach ($survey_summaries as $survey_summary) {
        $survey_peak_leads = max($survey_peak_leads, (int) ($survey_summary['leads'] ?? 0));
    }
    $step_progress = !empty($analytics_data['step_progress']) && is_array($analytics_data['step_progress']) ? $analytics_data['step_progress'] : array();

    ?>
    <div class="wrap mc-admin-shell-wrap">
        <div class="mc-admin-app mc-leads-engine-admin">
            <main class="mc-admin-main">
                <?php if ($panel !== 'surveys') : ?>
                <div class="mc-admin-topbar">
                    <div>
                        <div class="topbar-title" id="topbar-title"><?php echo esc_html(mc_leads_engine_admin_panel_title($panel)); ?></div>
                        <div class="topbar-sub"><?php echo esc_html(sprintf(__('Managing %d surveys · %d leads', 'mc-leads-engine'), count($surveys), count($leads))); ?></div>
                    </div>
                    <div class="topbar-actions">
                        <a class="btn" href="<?php echo esc_url($surveys_url); ?>"><span class="dashicons dashicons-search"></span></a>
                        <a class="btn primary" href="<?php echo esc_url($surveys_url); ?>"><span class="dashicons dashicons-plus"></span> <?php esc_html_e('New Survey', 'mc-leads-engine'); ?></a>
                    </div>
                </div>
                <?php endif; ?>

                <div class="mc-admin-content">
                    <section class="panel<?php echo $panel === 'dashboard' ? ' active' : ''; ?>" id="panel-dashboard" data-panel="dashboard" style="background: transparent; border: none; box-shadow: none; padding: 0;">

                        <?php
                        $hot_threshold  = (int) ($settings['score_hot_threshold']  ?? 80);
                        $warm_threshold = (int) ($settings['score_warm_threshold'] ?? 50);
                        $hot_count      = count(array_filter($leads, fn($l) => (int)($l['lead_score'] ?? 0) >= $hot_threshold));
                        $total_pipeline = $analytics['total_leads'];
                        $pipeline_statuses = mc_leads_get_statuses();
                        ?>

                        <!-- KPI Cards -->
                        <div class="kpi-grid">
                            <a href="<?php echo esc_url(add_query_arg('page', 'mc-leads-engine-analytics', admin_url('admin.php'))); ?>" style="text-decoration:none; color:inherit; display:block;">
                                <div class="kpi-card" style="cursor:pointer; height:100%;">
                                    <span class="kicon">☰</span>
                                    <div class="kpi-value"><?php echo esc_html(number_format_i18n($analytics['total_leads'])); ?></div>
                                    <div class="kpi-label"><?php esc_html_e('Total leads', 'mc-leads-engine'); ?></div>
                                    <div class="kpi-delta"><?php esc_html_e('All time', 'mc-leads-engine'); ?></div>
                                </div>
                            </a>
                            <a href="<?php echo esc_url(add_query_arg('page', 'mc-leads-engine-analytics', admin_url('admin.php'))); ?>" style="text-decoration:none; color:inherit; display:block;">
                                <div class="kpi-card money" style="cursor:pointer; height:100%;">
                                    <span class="kicon">KES</span>
                                    <div class="kpi-value"><?php echo esc_html(number_format_i18n($analytics['avg_value'], 2)); ?></div>
                                    <div class="kpi-label"><?php esc_html_e('Avg lead value', 'mc-leads-engine'); ?></div>
                                    <div class="kpi-delta"><?php esc_html_e('Estimated avg', 'mc-leads-engine'); ?></div>
                                </div>
                            </a>
                            <a href="<?php echo esc_url(add_query_arg(array('page' => 'mc-leads-engine-analytics', 'min_score' => $hot_threshold), admin_url('admin.php'))); ?>" style="text-decoration:none; color:inherit; display:block;">
                                <div class="kpi-card accent" style="cursor:pointer; height:100%;">
                                    <span class="kicon">🔥</span>
                                    <div class="kpi-value"><?php echo esc_html($hot_count); ?></div>
                                    <div class="kpi-label"><?php esc_html_e('Hot leads', 'mc-leads-engine'); ?></div>
                                    <div class="kpi-delta"><?php echo esc_html(sprintf(__('Score ≥ %d', 'mc-leads-engine'), $hot_threshold)); ?></div>
                                </div>
                            </a>
                            <a href="<?php echo esc_url(add_query_arg(array('page' => 'mc-leads-engine-analytics', 'status' => 'won'), admin_url('admin.php'))); ?>" style="text-decoration:none; color:inherit; display:block;">
                                <div class="kpi-card <?php echo $pipeline_counts['won'] === 0 ? 'zero' : ''; ?>" style="cursor:pointer; height:100%;">
                                    <span class="kicon">✓</span>
                                    <div class="kpi-value"><?php echo esc_html(number_format_i18n($pipeline_counts['won'])); ?></div>
                                    <div class="kpi-label"><?php esc_html_e('Won', 'mc-leads-engine'); ?></div>
                                    <div class="kpi-delta">
                                        <?php
                                        $win_rate = $total_pipeline ? round($pipeline_counts['won'] / $total_pipeline * 100) : 0;
                                        echo esc_html(sprintf(__('%d%% win rate', 'mc-leads-engine'), $win_rate));
                                        ?>
                                    </div>
                                </div>
                            </a>
                        </div>

                        <!-- Pipeline Strip -->
                        <div class="pipeline-strip">
                            <?php foreach ($pipeline_statuses as $slug => $label) :
                                $cnt = $pipeline_counts[$slug] ?? 0;
                                $cnt_class = $cnt === 0 ? 'zero' : '';
                                $stage_url = add_query_arg(array('page' => 'mc-leads-engine-analytics', 'status' => $slug), admin_url('admin.php'));
                            ?>
                            <a class="stage-link" href="<?php echo esc_url($stage_url); ?>">
                                <div class="stage-cell">
                                    <span class="stage-count <?php echo esc_attr($cnt_class); ?>"><?php echo esc_html(number_format_i18n($cnt)); ?></span>
                                    <span class="stage-label <?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></span>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>

                        <!-- Lower Row: chart + recent leads -->
                        <div class="lower-row">

                            <!-- Leads sparkline panel -->
                            <div class="panel">
                                <div class="panel-header">
                                    <div class="panel-title"><?php esc_html_e('Leads — last 30 days', 'mc-leads-engine'); ?></div>
                                </div>
                                <div class="chart-body">
                                    <div class="bars-row">
                                        <?php 
                                        $max_val = !empty($lead_daily_values) ? max($lead_daily_values) : 1;
                                        if ($max_val <= 0) $max_val = 1;
                                        foreach ($lead_daily_values as $val) :
                                            $h = max(6, round(($val / $max_val) * 100));
                                            $bar_class = '';
                                            if ($val === $max_val) {
                                                $bar_class = 'hi';
                                            } elseif ($val > 0) {
                                                $bar_class = 'mid';
                                            }
                                        ?>
                                            <div class="bar-col"><div class="bar <?php echo esc_attr($bar_class); ?>" style="height:<?php echo esc_attr($h); ?>%"></div></div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="chart-foot"><span><?php esc_html_e('30 days ago', 'mc-leads-engine'); ?></span><span><?php esc_html_e('Today', 'mc-leads-engine'); ?></span></div>
                                </div>
                            </div>

                            <!-- Recent leads panel -->
                            <div class="panel">
                                <div class="panel-header" style="display:flex; justify-content:space-between; align-items:baseline; padding: 14px 18px 2px;">
                                    <div class="panel-title"><?php esc_html_e('Recent leads', 'mc-leads-engine'); ?></div>
                                    <a href="<?php echo esc_url(add_query_arg(array('page' => 'mc-leads-engine-analytics'), admin_url('admin.php'))); ?>" style="font-size:11px; font-weight:700; color:var(--coral); text-decoration:none;"><?php esc_html_e('View all →', 'mc-leads-engine'); ?></a>
                                </div>
                                <div class="mini-table-wrap">
                                    <?php if (empty($recent_leads)) : ?>
                                        <p style="text-align: center; color: var(--mc-muted); padding: 24px 0; margin: 0; font-style: italic;"><?php esc_html_e('No leads yet.', 'mc-leads-engine'); ?></p>
                                    <?php else : ?>
                                    <table class="mini-leads">
                                        <thead>
                                            <tr>
                                                <th><?php esc_html_e('Lead', 'mc-leads-engine'); ?></th>
                                                <th><?php esc_html_e('Score', 'mc-leads-engine'); ?></th>
                                                <th><?php esc_html_e('Value', 'mc-leads-engine'); ?></th>
                                                <th><?php esc_html_e('Status', 'mc-leads-engine'); ?></th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_leads as $rl) :
                                                $rl_score = (int) ($rl['lead_score'] ?? 0);
                                                $rl_band  = mc_leads_score_band($rl_score);
                                                $rl_status = sanitize_key($rl['status'] ?? 'new');
                                                $rl_name  = trim(($rl['name'] ?? '') ?: ($rl['email'] ?? ''));
                                                if (!$rl_name) $rl_name = '#' . $rl['id'];
                                                $rl_url = add_query_arg(array('page' => 'mc-leads-engine-leads', 'lead_id' => $rl['id']), admin_url('admin.php'));
                                            ?>
                                            <tr>
                                                <td class="lead-id-mono" style="font-weight: 600;"><?php echo esc_html($rl_name); ?></td>
                                                <td><span class="score-chip <?php echo esc_attr($rl_band); ?>"><?php echo esc_html($rl_score); ?></span></td>
                                                <td class="val-mono">KES <?php echo esc_html(number_format_i18n((float)($rl['total_price'] ?? 0), 2)); ?></td>
                                                <td><span class="status-pill status-<?php echo esc_attr($rl_status); ?>"><?php echo esc_html(mc_leads_status_label($rl_status)); ?></span></td>
                                                <td><a class="mini-view" href="<?php echo esc_url($rl_url); ?>"><?php esc_html_e('View', 'mc-leads-engine'); ?></a></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <?php endif; ?>
                                </div>
                                <a class="panel-foot-link" href="<?php echo esc_url(add_query_arg(array('page' => 'mc-leads-engine-analytics'), admin_url('admin.php'))); ?>">☰ <?php esc_html_e('View all leads', 'mc-leads-engine'); ?></a>
                            </div>

                        </div>

                    </section>

                    <section class="panel<?php echo $panel === 'surveys' ? ' active' : ''; ?>" id="panel-surveys" data-panel="surveys">

                        <!-- ── Survey Top Bar ──────────────────────── -->
                        <div class="sv-toolbar">
                            <div class="sv-toolbar-left">
                                <select class="survey-select" onchange="window.location.href='<?php echo esc_url(admin_url('admin.php?page=mc-leads-engine-surveys&mc_panel=surveys&survey_id=')); ?>' + this.value">
                                    <option value="0"><?php esc_html_e('Select a survey…', 'mc-leads-engine'); ?></option>
                                    <?php foreach ($surveys as $survey) : ?>
                                        <option value="<?php echo esc_attr($survey['id']); ?>" <?php selected($selected_survey_id, $survey['id']); ?>><?php echo esc_html($survey['title']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($selected_survey_id) : ?>
                                    <button class="shortcode-chip" type="button"
                                        data-shortcode="<?php echo esc_attr(mc_leads_engine_get_survey_shortcode($selected_survey_id)); ?>"
                                        title="<?php esc_attr_e('Click to copy shortcode', 'mc-leads-engine'); ?>">
                                        <code><?php echo esc_html(mc_leads_engine_get_survey_shortcode($selected_survey_id)); ?></code>
                                        <span class="copy">⧉ <?php esc_html_e('Copy', 'mc-leads-engine'); ?></span>
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex; gap:8px;">
                                <a class="btn" href="<?php echo esc_url(add_query_arg(array('page' => 'mc-leads-engine-surveys', 'mc_panel' => 'surveys', 'survey_id' => 0), admin_url('admin.php'))); ?>">
                                    ＋ <?php esc_html_e('New survey', 'mc-leads-engine'); ?>
                                </a>
                                <?php if ($selected_survey_id) : ?>
                                    <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Delete this survey? This cannot be undone.', 'mc-leads-engine')); ?>');" style="margin:0;">
                                        <?php wp_nonce_field('mc_leads_engine_admin_action', 'mc_leads_engine_nonce'); ?>
                                        <input type="hidden" name="page" value="mc-leads-engine-surveys">
                                        <input type="hidden" name="mc_panel" value="surveys">
                                        <input type="hidden" name="mc_leads_engine_action" value="delete_survey">
                                        <input type="hidden" name="survey_id" value="<?php echo esc_attr($selected_survey_id); ?>">
                                        <button class="btn danger-text" type="submit">🗑 <?php esc_html_e('Delete', 'mc-leads-engine'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (isset($_GET['survey_id'])) : ?>

                        <!-- ── Tab pills ─────────────────────────── -->
                        <div class="sv-tabs">
                            <?php if ($selected_survey_id && $selected_survey) : ?>
                                <button class="sv-tab active" data-sv-tab="builder">▦ <?php esc_html_e('Builder', 'mc-leads-engine'); ?></button>
                            <?php endif; ?>
                            <button class="sv-tab<?php echo (!$selected_survey_id || !$selected_survey) ? ' active' : ''; ?>" data-sv-tab="settings">
                                ⚙ <?php echo ($selected_survey_id && $selected_survey) ? esc_html__('Survey settings', 'mc-leads-engine') : esc_html__('Create Survey', 'mc-leads-engine'); ?>
                                <?php if ($selected_survey_id && $selected_survey) : ?>
                                    <span class="status-dot" style="background:<?php echo $selected_survey['status'] === 'draft' ? '#f59e0b' : '#22c55e'; ?>;"></span>
                                <?php endif; ?>
                            </button>
                        </div>

                        <!-- ── BUILDER TAB ─────────────────────── -->
                        <?php if ($selected_survey_id && $selected_survey) : ?>
                        <div class="sv-tab-pane active" data-sv-pane="builder">
                            <div class="builder-grid">

                                <!-- Col 1: Sections -->
                                <div class="panel-col">
                                    <div class="panel-head">
                                        <span class="panel-head-label"><?php esc_html_e('Sections', 'mc-leads-engine'); ?></span>
                                    </div>
                                    <div class="panel-body">
                                        <form method="post" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="section-add-form sv-add-section-form">
                                            <?php wp_nonce_field('mc_leads_engine_admin_action', 'mc_leads_engine_nonce'); ?>
                                            <input type="hidden" name="page" value="mc-leads-engine-surveys">
                                            <input type="hidden" name="mc_panel" value="surveys">
                                            <input type="hidden" name="mc_leads_engine_action" value="save_section">
                                            <input type="hidden" name="survey_id" value="<?php echo esc_attr($selected_survey_id); ?>">
                                            <input type="hidden" name="section_id" value="0">
                                            <div class="add-row">
                                                <input class="add-input" type="text" name="section_title" placeholder="<?php esc_attr_e('New section…', 'mc-leads-engine'); ?>">
                                                <button class="add-btn" type="submit">＋</button>
                                            </div>
                                        </form>
                                        
                                        <?php if (empty($sections)) : ?>
                                            <div class="sv-empty-state">
                                                <span class="dashicons dashicons-category"></span>
                                                <p><?php esc_html_e('No sections yet.', 'mc-leads-engine'); ?></p>
                                            </div>
                                        <?php endif; ?>
                                        <?php foreach ($sections as $section) :
                                            $sec_q_count = count($section['questions'] ?? []);
                                        ?>
                                            <div class="section-item<?php echo absint($section['id']) === $selected_section_id ? ' active' : ''; ?>" data-section-id="<?php echo absint($section['id']); ?>">
                                                <div>
                                                    <a class="section-link" href="<?php echo esc_url(mc_leads_engine_admin_selected_url('surveys', $selected_survey_id, $section['id'], 0)); ?>" style="text-decoration:none;">
                                                        <div class="stitle section-title-text"><?php echo esc_html($section['title']); ?></div>
                                                    </a>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="section-title-form">
                                                        <?php wp_nonce_field('mc_leads_engine_admin_action', 'mc_leads_engine_nonce'); ?>
                                                        <input type="hidden" name="page" value="mc-leads-engine-surveys">
                                                        <input type="hidden" name="mc_panel" value="surveys">
                                                        <input type="hidden" name="mc_leads_engine_action" value="save_section">
                                                        <input type="hidden" name="survey_id" value="<?php echo esc_attr($selected_survey_id); ?>">
                                                        <input type="hidden" name="section_id" value="<?php echo esc_attr($section['id']); ?>">
                                                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr(mc_leads_engine_admin_selected_url('surveys', $selected_survey_id, $selected_section_id, $selected_question_id)); ?>">
                                                        <input class="field-input section-title-input" type="text" name="section_title" value="<?php echo esc_attr($section['title']); ?>" data-original-title="<?php echo esc_attr($section['title']); ?>">
                                                    </form>
                                                </div>
                                                <div class="sright">
                                                    <span class="qcount-badge"><?php echo esc_html($sec_q_count); ?></span>
                                                    <div class="row-actions">
                                                        <button type="button" class="icon-btn section-edit-trigger" aria-label="<?php esc_attr_e('Rename', 'mc-leads-engine'); ?>">✎</button>
                                                        <form method="post" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="section-delete-form" style="display:contents">
                                                            <?php wp_nonce_field('mc_leads_engine_admin_action', 'mc_leads_engine_nonce'); ?>
                                                            <input type="hidden" name="page" value="mc-leads-engine-surveys">
                                                            <input type="hidden" name="mc_panel" value="surveys">
                                                            <input type="hidden" name="mc_leads_engine_action" value="delete_section">
                                                            <input type="hidden" name="survey_id" value="<?php echo esc_attr($selected_survey_id); ?>">
                                                            <input type="hidden" name="section_id" value="<?php echo esc_attr($section['id']); ?>">
                                                            <button type="submit" class="icon-btn del section-delete" aria-label="<?php esc_attr_e('Delete section', 'mc-leads-engine'); ?>">🗑</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>

                                        <div class="pipeline-note">
                                            <b><?php echo esc_html(sprintf(__('%d sections', 'mc-leads-engine'), count($sections))); ?></b> <?php esc_html_e('run in order. Each answer can add to price and score — the totals carry through to the final estimate.', 'mc-leads-engine'); ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Col 2: Questions -->
                                <div class="panel-col">
                                    <div class="panel-head">
                                        <span class="panel-head-label">
                                            <?php echo $selected_section ? esc_html($selected_section['title']) : esc_html__('Questions', 'mc-leads-engine'); ?>
                                        </span>
                                        <?php if ($selected_section_id) : ?>
                                            <a class="btn ghost" href="<?php echo esc_url($add_question_url); ?>" style="height:26px;padding:0 8px;font-size:11.5px;">＋ <?php esc_html_e('Add', 'mc-leads-engine'); ?></a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="panel-body question-drag-container" data-survey-id="<?php echo esc_attr($selected_survey_id); ?>" data-section-id="<?php echo esc_attr($selected_section_id); ?>">
                                        <?php if (!$selected_section_id) : ?>
                                            <div class="sv-empty-state">
                                                <span class="dashicons dashicons-arrow-left-alt"></span>
                                                <p><?php esc_html_e('Pick a section first.', 'mc-leads-engine'); ?></p>
                                            </div>
                                        <?php elseif (empty($selected_questions)) : ?>
                                            <div class="sv-empty-state">
                                                <span class="dashicons dashicons-edit-large"></span>
                                                <p><?php esc_html_e('No questions yet.', 'mc-leads-engine'); ?></p>
                                                <a class="btn primary" href="<?php echo esc_url($add_question_url); ?>"><span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e('Add question', 'mc-leads-engine'); ?></a>
                                            </div>
                                        <?php endif; ?>

                                        <?php
                                        $q_type_labels = array(
                                            'radio'    => __('Single', 'mc-leads-engine'),
                                            'checkbox' => __('Multi', 'mc-leads-engine'),
                                            'number'   => __('Number', 'mc-leads-engine'),
                                            'text'     => __('Text', 'mc-leads-engine'),
                                            'cf7'      => __('CF7', 'mc-leads-engine'),
                                        );
                                        foreach ($selected_questions as $question) :
                                            $q_type     = $question['type'] ?? 'radio';
                                            $q_type_lbl = $q_type_labels[$q_type] ?? $q_type;
                                            $q_active   = absint($question['id']) === $selected_question_id;
                                        ?>
                                            <div class="q-card<?php echo $q_active ? ' active' : ''; ?>" data-question-id="<?php echo absint($question['id']); ?>">
                                                <a class="q-card-link" href="<?php echo esc_url(mc_leads_engine_admin_selected_url('surveys', $selected_survey_id, $selected_section_id, $question['id'])); ?>" style="text-decoration:none; color:inherit;">
                                                    <div class="q-card-top"><?php echo esc_html($question['question_text']); ?></div>
                                                    <div class="q-card-meta">
                                                        <span class="type-badge <?php echo esc_attr($q_type === 'radio' ? 'single' : ($q_type === 'checkbox' ? 'multi' : $q_type)); ?>"><?php echo esc_html($q_type_lbl); ?></span>
                                                        <?php if (!empty($question['required'])) : ?>
                                                            <span class="required-star">★ <?php esc_html_e('Required', 'mc-leads-engine'); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </a>
                                                <div class="q-card-foot">
                                                    <span class="drag-h">⠿ <?php esc_html_e('drag to reorder', 'mc-leads-engine'); ?></span>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="q-card-delete-form" style="margin:0;">
                                                        <?php wp_nonce_field('mc_leads_engine_admin_action', 'mc_leads_engine_nonce'); ?>
                                                        <input type="hidden" name="page" value="mc-leads-engine-surveys">
                                                        <input type="hidden" name="mc_panel" value="surveys">
                                                        <input type="hidden" name="mc_leads_engine_action" value="delete_question">
                                                        <input type="hidden" name="survey_id" value="<?php echo esc_attr($selected_survey_id); ?>">
                                                        <input type="hidden" name="section_id" value="<?php echo esc_attr($selected_section_id); ?>">
                                                        <input type="hidden" name="question_id" value="<?php echo esc_attr($question['id']); ?>">
                                                        <button type="submit" class="icon-btn del q-card-delete" aria-label="<?php esc_attr_e('Delete question', 'mc-leads-engine'); ?>">🗑</button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>

                                        <?php if ($selected_section_id) : ?>
                                            <a class="add-question-btn" href="<?php echo esc_url($add_question_url); ?>" style="text-decoration:none;">＋ <?php esc_html_e('Add question to this section', 'mc-leads-engine'); ?></a>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Col 3: Editor -->
                                <div class="panel-col editor-col">
                                    <div class="panel-head">
                                        <span class="panel-head-label"><?php echo $selected_question ? esc_html__('Edit Question', 'mc-leads-engine') : esc_html__('New Question', 'mc-leads-engine'); ?></span>
                                        <?php if ($selected_question && $selected_section) : ?>
                                            <span class="qcount-badge" style="background:#eaf1ff;border-color:#dbe6fb;color:#3457d5;"><?php echo esc_html($selected_section['title']); ?> · Q<?php echo esc_html($selected_question['order_index'] ?? 1); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="editor-body">
                                        <?php if (!$selected_section) : ?>
                                            <div class="sv-empty-state sv-editor-empty">
                                                <span class="dashicons dashicons-arrow-left-alt"></span>
                                                <p><?php esc_html_e('Select a section and question to start editing.', 'mc-leads-engine'); ?></p>
                                            </div>
                                        <?php else : ?>
                                            <form method="post" class="sv-question-form">
                                                <?php wp_nonce_field('mc_leads_engine_admin_action', 'mc_leads_engine_nonce'); ?>
                                                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($current_url); ?>">
                                                <input type="hidden" name="mc_leads_engine_action" value="save_question">
                                                <input type="hidden" name="survey_id" value="<?php echo esc_attr($selected_survey_id); ?>">
                                                <input type="hidden" name="section_id" value="<?php echo esc_attr($selected_section_id); ?>">
                                                <input type="hidden" name="question_id" value="<?php echo esc_attr($selected_question['id'] ?? 0); ?>">

                                                <div class="field-group">
                                                    <label class="field-label"><?php esc_html_e('Question text', 'mc-leads-engine'); ?></label>
                                                    <textarea class="field-input" rows="2" name="question_text" placeholder="<?php esc_attr_e('e.g. What type of project is this?', 'mc-leads-engine'); ?>"><?php echo esc_textarea($selected_question['question_text'] ?? ''); ?></textarea>
                                                    <div class="field-hint"><?php esc_html_e('Paste a Contact Form 7 shortcode here if answer type is set to CF7.', 'mc-leads-engine'); ?></div>
                                                </div>

                                                <div class="inline-row">
                                                    <div class="field-group" style="margin-bottom:0;">
                                                        <label class="field-label"><?php esc_html_e('Answer type', 'mc-leads-engine'); ?></label>
                                                        <select class="field-input" name="type">
                                                            <option value="radio"    <?php selected(($selected_question['type'] ?? ''), 'radio'); ?>><?php esc_html_e('Multiple choice (single)', 'mc-leads-engine'); ?></option>
                                                            <option value="checkbox" <?php selected(($selected_question['type'] ?? ''), 'checkbox'); ?>><?php esc_html_e('Multiple choice (multi)', 'mc-leads-engine'); ?></option>
                                                            <option value="number"   <?php selected(($selected_question['type'] ?? ''), 'number'); ?>><?php esc_html_e('Number input', 'mc-leads-engine'); ?></option>
                                                            <option value="text"     <?php selected(($selected_question['type'] ?? ''), 'text'); ?>><?php esc_html_e('Text input', 'mc-leads-engine'); ?></option>
                                                            <option value="cf7"      <?php selected(($selected_question['type'] ?? ''), 'cf7'); ?>><?php esc_html_e('Contact Form 7', 'mc-leads-engine'); ?></option>
                                                        </select>
                                                    </div>
                                                    <div class="field-group" style="margin-bottom:0;">
                                                        <label class="field-label"><?php esc_html_e('Order', 'mc-leads-engine'); ?></label>
                                                        <input class="field-input" type="number" name="order_index" value="<?php echo esc_attr($selected_question['order_index'] ?? 0); ?>">
                                                    </div>
                                                    <div class="field-group" style="margin-bottom:0;">
                                                        <label class="field-label"><?php esc_html_e('Required', 'mc-leads-engine'); ?></label>
                                                        <div class="toggle-field">
                                                            <span style="font-size:12px;color:var(--muted);"><?php esc_html_e('On', 'mc-leads-engine'); ?></span>
                                                            <label class="switch">
                                                                <input type="checkbox" name="required" value="1" <?php checked(!empty($selected_question['required'])); ?>>
                                                                <span class="track"></span>
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>

                                                <?php if (($selected_question['type'] ?? '') !== 'cf7') : ?>
                                                    <?php 
                                                    $existing_options = !empty($selected_question['id']) ? (new MC_Leads_Engine_Question())->get_options($selected_question['id']) : array(); 
                                                    $opt_count = count($existing_options);
                                                    $price_impacts = array_column($existing_options, 'price_impact');
                                                    $score_impacts = array_column($existing_options, 'score_impact');

                                                    $currency = $settings['currency_symbol'] ?? 'KES';
                                                    $min_price = $price_impacts ? min($price_impacts) : 0;
                                                    $max_price = $price_impacts ? max($price_impacts) : 0;
                                                    $min_score = $score_impacts ? min($score_impacts) : 0;
                                                    $max_score = $score_impacts ? max($score_impacts) : 0;

                                                    $q_type = $selected_question['type'] ?? 'radio';
                                                    $select_label = ($q_type === 'checkbox') ? __('multi-select', 'mc-leads-engine') : (($q_type === 'radio') ? __('single-select', 'mc-leads-engine') : '');
                                                    ?>
                                                    <div class="options-section">
                                                        <div class="options-head">
                                                            <label class="field-label" style="margin-bottom:0;"><?php esc_html_e('Answer options', 'mc-leads-engine'); ?></label>
                                                            <span class="field-hint" style="margin-top:0;"><?php esc_html_e('Each option can add to price & score', 'mc-leads-engine'); ?></span>
                                                        </div>
                                                        <div class="opt-table" data-option-builder data-next-index="<?php echo esc_attr(max(1, count($existing_options))); ?>">
                                                            <div class="opt-cols">
                                                                <span><?php esc_html_e('Label', 'mc-leads-engine'); ?></span>
                                                                <span class="num"><?php esc_html_e('+Price', 'mc-leads-engine'); ?></span>
                                                                <span class="num"><?php esc_html_e('+Score', 'mc-leads-engine'); ?></span>
                                                                <span class="num"><?php esc_html_e('Value', 'mc-leads-engine'); ?></span>
                                                                <span class="num"><?php esc_html_e('Order', 'mc-leads-engine'); ?></span>
                                                                <span></span>
                                                            </div>
                                                            <div class="opt-rows" data-option-list>
                                                                <?php for ($i = 0; $i < max(1, count($existing_options)); $i++) : $opt = $existing_options[$i] ?? array(); ?>
                                                                    <div class="opt-row" data-option-row>
                                                                        <input class="opt-input" type="text" name="options[<?php echo esc_attr($i); ?>][label]" placeholder="<?php esc_attr_e('Label', 'mc-leads-engine'); ?>" value="<?php echo esc_attr($opt['label'] ?? ''); ?>">
                                                                        <input class="opt-input num price" type="number" step="0.01" name="options[<?php echo esc_attr($i); ?>][price_impact]" placeholder="0" value="<?php echo esc_attr($opt['price_impact'] ?? 0); ?>">
                                                                        <input class="opt-input num score" type="number" name="options[<?php echo esc_attr($i); ?>][score_impact]" placeholder="0" value="<?php echo esc_attr($opt['score_impact'] ?? 0); ?>">
                                                                        <input class="opt-input num" type="text" name="options[<?php echo esc_attr($i); ?>][value]" placeholder="<?php esc_attr_e('value', 'mc-leads-engine'); ?>" value="<?php echo esc_attr($opt['value'] ?? ''); ?>" style="font-family:var(--mono);font-size:11.5px;color:var(--muted);text-align:right;">
                                                                        <input class="opt-input num" type="number" name="options[<?php echo esc_attr($i); ?>][order_index]" placeholder="0" value="<?php echo esc_attr($opt['order_index'] ?? $i); ?>">
                                                                        <input type="hidden" name="options[<?php echo esc_attr($i); ?>][description]" value="<?php echo esc_attr($opt['description'] ?? ''); ?>">
                                                                        <button type="button" class="opt-remove" data-remove-option-row>✕</button>
                                                                    </div>
                                                                <?php endfor; ?>
                                                            </div>
                                                            <template data-option-template>
                                                                <div class="opt-row" data-option-row>
                                                                    <input class="opt-input" type="text" name="options[__INDEX__][label]" placeholder="<?php esc_attr_e('Label', 'mc-leads-engine'); ?>" value="">
                                                                    <input class="opt-input num price" type="number" step="0.01" name="options[__INDEX__][price_impact]" placeholder="0" value="0">
                                                                    <input class="opt-input num score" type="number" name="options[__INDEX__][score_impact]" placeholder="0" value="0">
                                                                    <input class="opt-input num" type="text" name="options[__INDEX__][value]" placeholder="<?php esc_attr_e('value', 'mc-leads-engine'); ?>" value="" style="font-family:var(--mono);font-size:11.5px;color:var(--muted);text-align:right;">
                                                                    <input class="opt-input num" type="number" name="options[__INDEX__][order_index]" placeholder="0" value="0">
                                                                    <input type="hidden" name="options[__INDEX__][description]" value="">
                                                                    <button type="button" class="opt-remove" data-remove-option-row>✕</button>
                                                                </div>
                                                            </template>
                                                            <div class="add-option-row" data-add-option-row>＋ <?php esc_html_e('Add option', 'mc-leads-engine'); ?></div>
                                                        </div>

                                                        <div class="impact-strip">
                                                            <span>
                                                                <span class="lbl"><?php esc_html_e('Range adds', 'mc-leads-engine'); ?></span>
                                                                <span class="metric price">
                                                                    <?php 
                                                                    if ($min_price == $max_price) {
                                                                        echo esc_html($currency . ' ' . number_format_i18n($min_price));
                                                                    } else {
                                                                        echo esc_html($currency . ' ' . number_format_i18n($min_price) . '–' . number_format_i18n($max_price));
                                                                    }
                                                                    ?>
                                                                </span>
                                                            </span>
                                                            <div class="sep"></div>
                                                            <span>
                                                                <span class="lbl"><?php esc_html_e('Score adds', 'mc-leads-engine'); ?></span>
                                                                <span class="metric score">
                                                                    <?php 
                                                                    if ($min_score == $max_score) {
                                                                        echo esc_html(($min_score >= 0 ? '+' : '') . $min_score);
                                                                    } else {
                                                                        echo esc_html(($min_score >= 0 ? '+' : '') . $min_score . ' to ' . ($max_score >= 0 ? '+' : '') . $max_score);
                                                                    }
                                                                    ?>
                                                                </span>
                                                            </span>
                                                            <?php if ($select_label) : ?>
                                                                <div class="sep"></div>
                                                                <span style="color:#9a9aa1;"><?php echo esc_html(sprintf(__('%d options · %s', 'mc-leads-engine'), $opt_count, $select_label)); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>

                                                <div class="editor-actions">
                                                    <a class="btn ghost" href="<?php echo esc_url(mc_leads_engine_admin_selected_url('surveys', $selected_survey_id, $selected_section_id, 0)); ?>"><?php esc_html_e('Cancel', 'mc-leads-engine'); ?></a>
                                                    <button class="btn primary" type="submit"><?php esc_html_e('Save question', 'mc-leads-engine'); ?></button>
                                                </div>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>

                            </div>
                        </div><!-- /.sv-tab-pane[builder] -->
                        <?php endif; ?>

                        <!-- ── SETTINGS TAB ─────────────────────── -->
                        <div class="sv-tab-pane<?php echo (!$selected_survey_id || !$selected_survey) ? ' active' : ''; ?>" data-sv-pane="settings">
                            <?php $survey_settings = mc_leads_engine_get_survey_settings($selected_survey_id); ?>
                            <form method="post" class="sv-settings-form">
                                <?php wp_nonce_field('mc_leads_engine_admin_action', 'mc_leads_engine_nonce'); ?>
                                <input type="hidden" name="page" value="mc-leads-engine-surveys">
                                <input type="hidden" name="mc_panel" value="surveys">
                                <input type="hidden" name="mc_leads_engine_action" value="save_survey">
                                <input type="hidden" name="survey_id" value="<?php echo esc_attr($selected_survey_id); ?>">
                                <div class="sv-settings-grid">
                                    <div class="sv-settings-col">
                                        <div class="field-group">
                                            <label class="field-label"><?php esc_html_e('Survey title', 'mc-leads-engine'); ?></label>
                                            <div class="field-hint" style="margin-top:0; margin-bottom:6px;"><?php esc_html_e('The name of this survey used for internal organization.', 'mc-leads-engine'); ?></div>
                                            <input class="field-input" type="text" name="title" value="<?php echo esc_attr($selected_survey['title'] ?? ''); ?>">
                                        </div>
                                        <div class="field-group">
                                            <label class="field-label"><?php esc_html_e('Status', 'mc-leads-engine'); ?></label>
                                            <div class="field-hint" style="margin-top:0; margin-bottom:6px;"><?php esc_html_e('Draft surveys are only visible to admins. Published surveys are live.', 'mc-leads-engine'); ?></div>
                                            <select class="field-input" name="status">
                                                <option value="draft" <?php selected(($selected_survey['status'] ?? 'draft'), 'draft'); ?>><?php esc_html_e('Draft (not live)', 'mc-leads-engine'); ?></option>
                                                <option value="published" <?php selected(($selected_survey['status'] ?? ''), 'published'); ?>><?php esc_html_e('Published (live)', 'mc-leads-engine'); ?></option>
                                            </select>
                                        </div>
                                        <div class="field-group">
                                            <label class="field-label"><?php esc_html_e('Final step title', 'mc-leads-engine'); ?></label>
                                            <div class="field-hint" style="margin-top:0; margin-bottom:6px;"><?php esc_html_e('The headline shown on the last screen after submission.', 'mc-leads-engine'); ?></div>
                                            <input class="field-input" type="text" name="final_step_title" value="<?php echo esc_attr($survey_settings['final_step_title'] ?? ''); ?>">
                                        </div>
                                        <div class="field-group">
                                            <label class="field-label"><?php esc_html_e('Submit button text', 'mc-leads-engine'); ?></label>
                                            <div class="field-hint" style="margin-top:0; margin-bottom:6px;"><?php esc_html_e('The text displayed on the final submit button.', 'mc-leads-engine'); ?></div>
                                            <input class="field-input" type="text" name="final_button_text" value="<?php echo esc_attr($survey_settings['final_button_text'] ?? ''); ?>">
                                        </div>
                                        <div class="sv-settings-toggles">
                                            <div class="sv-toggle-group" style="display:flex; flex-direction:column; align-items:flex-start; gap:4px; margin-bottom:12px;">
                                                <div style="display:flex; align-items:center; gap:10px;">
                                                    <label class="switch">
                                                        <input type="checkbox" id="svs-show-price" name="show_final_price" value="1" <?php checked(!empty($survey_settings['show_final_price'])); ?>>
                                                        <span class="track"></span>
                                                    </label>
                                                    <label for="svs-show-price" class="sv-toggle-text" style="font-weight:600;"><?php esc_html_e('Show estimated price on final step', 'mc-leads-engine'); ?></label>
                                                </div>
                                                <div class="field-hint" style="margin-left:44px; margin-top:0;"><?php esc_html_e('If enabled, the computed price estimate will be shown to the user.', 'mc-leads-engine'); ?></div>
                                            </div>
                                            <div class="sv-toggle-group" style="display:flex; flex-direction:column; align-items:flex-start; gap:4px;">
                                                <div style="display:flex; align-items:center; gap:10px;">
                                                    <label class="switch">
                                                        <input type="checkbox" id="svs-show-score" name="show_final_score" value="1" <?php checked(!empty($survey_settings['show_final_score'])); ?>>
                                                        <span class="track"></span>
                                                    </label>
                                                    <label for="svs-show-score" class="sv-toggle-text" style="font-weight:600;"><?php esc_html_e('Show lead score on final step', 'mc-leads-engine'); ?></label>
                                                </div>
                                                <div class="field-hint" style="margin-left:44px; margin-top:0;"><?php esc_html_e('If enabled, the total lead score will be shown to the user.', 'mc-leads-engine'); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="sv-settings-col">
                                        <div class="field-group">
                                            <label class="field-label"><?php esc_html_e('Description (internal)', 'mc-leads-engine'); ?></label>
                                            <div class="field-hint" style="margin-top:0; margin-bottom:6px;"><?php esc_html_e('Private notes about this survey, visible only to admin users.', 'mc-leads-engine'); ?></div>
                                            <textarea class="field-input" rows="6" name="description" style="min-height:120px;resize:vertical;"><?php echo esc_textarea($selected_survey['description'] ?? ''); ?></textarea>
                                        </div>
                                        <div class="field-group">
                                            <label class="field-label"><?php esc_html_e('Final step message', 'mc-leads-engine'); ?></label>
                                            <div class="field-hint" style="margin-top:0; margin-bottom:6px;"><?php esc_html_e('HTML and text shown to users on completion. Supports shortcodes.', 'mc-leads-engine'); ?></div>
                                            <textarea class="field-input" rows="6" name="final_message" style="min-height:120px;resize:vertical;" placeholder="<?php esc_attr_e('e.g. Thanks! Review your answers below.', 'mc-leads-engine'); ?>"><?php echo esc_textarea($survey_settings['final_message'] ?? ''); ?></textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="sv-settings-actions">
                                    <button class="btn primary" type="submit"><?php esc_html_e('Save survey settings', 'mc-leads-engine'); ?></button>
                                </div>
                            </form>
                        </div><!-- /.sv-tab-pane[settings] -->

                        <?php else : ?>
                        <div class="sv-no-survey">
                            <span class="dashicons dashicons-feedback sv-no-survey-icon"></span>
                            <h3><?php esc_html_e('No survey selected', 'mc-leads-engine'); ?></h3>
                            <p><?php esc_html_e('Choose a survey from the dropdown above, or create a new one to get started.', 'mc-leads-engine'); ?></p>
                            <a class="btn primary" href="<?php echo esc_url(add_query_arg(array('page' => 'mc-leads-engine-surveys', 'mc_panel' => 'surveys', 'survey_id' => 0), admin_url('admin.php'))); ?>">
                                <span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e('Create your first survey', 'mc-leads-engine'); ?>
                            </a>
                        </div>
                        <?php endif; ?>

                    </section>

                    <section class="panel<?php echo $panel === 'analytics' ? ' active' : ''; ?>" id="panel-analytics" data-panel="analytics">
                        <div class="stat-grid">
                            <div class="stat-card">
                                <div class="stat-label"><span class="dashicons dashicons-groups"></span> <?php esc_html_e('Total Leads', 'mc-leads-engine'); ?></div>
                                <div class="stat-value"><?php echo esc_html(number_format_i18n($analytics['total_leads'])); ?></div>
                                <div class="stat-delta"><?php esc_html_e('All time', 'mc-leads-engine'); ?></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-label"><span class="dashicons dashicons-money-alt"></span> <?php esc_html_e('Pipeline Value', 'mc-leads-engine'); ?></div>
                                <div class="stat-value"><?php echo esc_html(number_format_i18n($analytics['revenue'], 0)); ?></div>
                                <div class="stat-delta"><?php esc_html_e('Estimate', 'mc-leads-engine'); ?></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-label"><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e('Conversion', 'mc-leads-engine'); ?></div>
                                <div class="stat-value"><?php echo esc_html(number_format_i18n($analytics['conversion_rate'], 0)); ?>%</div>
                                <div class="stat-delta"><?php esc_html_e('Complete rate', 'mc-leads-engine'); ?></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-label"><span class="dashicons dashicons-star-filled"></span> <?php esc_html_e('High Value', 'mc-leads-engine'); ?></div>
                                <div class="stat-value"><?php echo esc_html(count(array_filter($leads, function ($lead) { return (int) ($lead['lead_score'] ?? 0) >= 80; }))); ?></div>
                                <div class="stat-delta"><?php esc_html_e('High-score leads', 'mc-leads-engine'); ?></div>
                            </div>
                        </div>

                        <!-- Step Drop-off -->
                        <div class="card" style="margin-top:12px">
                            <div class="card-title"><?php esc_html_e('Step Drop-off', 'mc-leads-engine'); ?> <span><?php esc_html_e('By survey', 'mc-leads-engine'); ?></span></div>
                            <?php if (empty($step_progress)) : ?>
                                <div class="rule-desc" style="padding:6px 0"><?php esc_html_e('No step data yet. Progress is tracked as visitors move through the survey.', 'mc-leads-engine'); ?></div>
                            <?php endif; ?>
                            <?php foreach ($step_progress as $survey_id => $steps) :
                                $step_keys = array_keys($steps);
                                $peak = $steps ? max(array_map('intval', array_values($steps))) : 1;
                            ?>
                                <div class="step-dropoff-group">
                                    <div class="step-dropoff-survey-title">
                                        <?php echo esc_html(mc_leads_engine_survey_repository()->get_survey($survey_id)['title'] ?? sprintf(__('Survey #%d', 'mc-leads-engine'), $survey_id)); ?>
                                    </div>
                                    <?php foreach ($steps as $step => $count) :
                                        $pct = $peak > 0 ? round(((int) $count / $peak) * 100) : 0;
                                        if ($step === min($step_keys)) {
                                            $stage_label = __('Survey opened', 'mc-leads-engine');
                                        } elseif ($step === max($step_keys)) {
                                            $stage_label = __('Final step — lead submitted', 'mc-leads-engine');
                                        } else {
                                            $stage_label = sprintf(__('Section %d reached', 'mc-leads-engine'), $step - 1);
                                        }
                                    ?>
                                        <div class="step-dropoff-row">
                                            <div class="step-dropoff-meta">
                                                <span class="step-dropoff-label"><?php echo esc_html(sprintf(__('Step %d', 'mc-leads-engine'), $step)); ?></span>
                                                <span class="step-dropoff-info"><?php echo esc_html($stage_label); ?></span>
                                            </div>
                                            <div class="step-dropoff-right">
                                                <div class="performance-bar-track">
                                                    <div class="performance-bar-fill" style="width:<?php echo esc_attr(min(100, max(8, $pct))); ?>%"></div>
                                                </div>
                                                <span class="step-dropoff-count"><?php echo esc_html(number_format_i18n((int) $count)); ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="card" style="margin-top:12px">
                            <div class="card-title" style="display:flex;align-items:center;justify-content:space-between">
                                <span><?php esc_html_e('Recent Leads', 'mc-leads-engine'); ?></span>
                                <a class="btn" href="<?php echo esc_url(admin_url('admin.php?page=mc-leads-engine-leads')); ?>" style="font-size:10px"><?php esc_html_e('Export to Excel', 'mc-leads-engine'); ?></a>
                            </div>
                            <div class="leads-grid">
                                <?php foreach (array_slice($leads, 0, 20) as $lead) :
                                    global $wpdb;
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
                                    $survey_row = mc_leads_engine_survey_repository()->get_survey($lead['survey_id']);
                                    $title = $is_booking ? __('Bookings', 'mc-leads-engine') : ($survey_row['title'] ?? __('Lead', 'mc-leads-engine'));
                                    $initials = strtoupper(substr((string) ($is_booking ? 'BK' : ($survey_row['title'] ?? 'MC')), 0, 2));
                                    $score_class = (int) $lead['lead_score'] >= 80 ? 'score-high' : ((int) $lead['lead_score'] >= 50 ? 'score-med' : '');
                                ?>
                                    <div class="lead-card">
                                        <div class="lead-avatar"><?php echo esc_html($initials); ?></div>
                                        <div class="lead-info">
                                            <div class="lead-name"><?php echo esc_html($title); ?></div>
                                            <div class="lead-sub"><?php echo esc_html(sprintf(__('%s · %s', 'mc-leads-engine'), $survey_row['status'] ?? '', $lead['created_at'])); ?></div>
                                        </div>
                                        <div class="lead-value">
                                            <div class="lead-price"><?php echo esc_html(sprintf('KES %s', number_format_i18n((float) $lead['total_price'], 0))); ?></div>
                                            <div class="lead-score <?php echo esc_attr($score_class); ?>"><?php echo esc_html(sprintf(__('Score %d', 'mc-leads-engine'), (int) $lead['lead_score'])); ?></div>
                                        </div>
                                        <div class="survey-actions">
                                            <a class="mc-db-view-btn" href="<?php echo esc_url(admin_url('admin.php?page=mc-leads-engine-leads&lead_id=' . $lead['id'])); ?>"><?php esc_html_e('View', 'mc-leads-engine'); ?></a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>



                    <section class="panel<?php echo $panel === 'settings' ? ' active' : ''; ?>" id="panel-settings" data-panel="settings">
                        <form method="post" class="mc-settings-form">
                            <?php wp_nonce_field('mc_leads_engine_admin_action', 'mc_leads_engine_nonce'); ?>
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($current_url); ?>">
                            <input type="hidden" name="mc_leads_engine_action" value="save_settings">
                            
                            <div class="settings-container">
                                <!-- Left Sidebar Tabs -->
                                <div class="settings-sidebar">
                                    <button type="button" class="settings-tab-btn active" data-tab="general">
                                        <span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('General Settings', 'mc-leads-engine'); ?>
                                    </button>
                                    <button type="button" class="settings-tab-btn" data-tab="user-email">
                                        <span class="dashicons dashicons-email-alt"></span> <?php esc_html_e('User Email Notification', 'mc-leads-engine'); ?>
                                    </button>
                                    <button type="button" class="settings-tab-btn" data-tab="admin-email">
                                        <span class="dashicons dashicons-email-alt2"></span> <?php esc_html_e('Admin Email Notification', 'mc-leads-engine'); ?>
                                    </button>
                                    <button type="button" class="settings-tab-btn" data-tab="whatsapp">
                                        <span class="dashicons dashicons-whatsapp"></span> <?php esc_html_e('WhatsApp Notifications', 'mc-leads-engine'); ?>
                                    </button>
                                    <button type="button" class="settings-tab-btn" data-tab="consent">
                                        <span class="dashicons dashicons-shield"></span> <?php esc_html_e('Consent & Tracking', 'mc-leads-engine'); ?>
                                    </button>
                                    <button type="button" class="settings-tab-btn" data-tab="placeholders">
                                        <span class="dashicons dashicons-editor-code"></span> <?php esc_html_e('Placeholder Guide', 'mc-leads-engine'); ?>
                                    </button>
                                    <button type="button" class="settings-tab-btn" data-tab="pricing">
                                        <span class="dashicons dashicons-money-alt"></span> <?php esc_html_e('Pricing Rules', 'mc-leads-engine'); ?>
                                    </button>
                                </div>
                                
                                <!-- Right Pane -->
                                <div class="settings-content">
                                    <!-- General Tab -->
                                    <div class="settings-section-pane active" data-pane="general">
                                        <div class="card">
                                            <div class="card-title"><?php esc_html_e('General Settings', 'mc-leads-engine'); ?></div>
                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('Admin Notification Email', 'mc-leads-engine'); ?></div>
                                                <input class="field-input" type="email" name="notification_email" value="<?php echo esc_attr($settings['notification_email']); ?>">
                                                <span class="field-desc"><?php esc_html_e('Where admin submission notifications are sent.', 'mc-leads-engine'); ?></span>
                                            </div>
                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('Thank You Redirect URL', 'mc-leads-engine'); ?></div>
                                                <input class="field-input" type="url" name="thank_you_url" value="<?php echo esc_attr($settings['thank_you_url']); ?>">
                                                <span class="field-desc"><?php esc_html_e('Fallback URL redirect destination after a lead is submitted.', 'mc-leads-engine'); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- User Email Tab -->
                                    <div class="settings-section-pane" data-pane="user-email">
                                        <div class="card">
                                            <div class="card-title"><?php esc_html_e('User Email Notification Template', 'mc-leads-engine'); ?></div>
                                            <p class="field-desc-top"><?php esc_html_e('Configure the HTML email sent to clients who submit a survey. Use inline CSS to style the markup.', 'mc-leads-engine'); ?></p>
                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('Email Subject', 'mc-leads-engine'); ?></div>
                                                <input class="field-input" type="text" name="user_email_subject" value="<?php echo esc_attr($settings['user_email_subject']); ?>">
                                            </div>
                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('HTML Body Template', 'mc-leads-engine'); ?></div>
                                                <textarea class="field-input code-font" rows="12" name="user_email_body"><?php echo esc_textarea($settings['user_email_body']); ?></textarea>
                                                <span class="field-desc"><?php esc_html_e('Enter raw HTML with inline CSS. Dynamic bracket variables like [your-name] will be replaced.', 'mc-leads-engine'); ?></span>
                                            </div>
                                        </div>
                                        <div class="card" style="margin-top: 20px;">
                                            <div class="card-title"><?php esc_html_e('Booking User Email Notification Template', 'mc-leads-engine'); ?></div>
                                            <p class="field-desc-top"><?php esc_html_e('Configure the HTML email sent to clients who schedule a booking. Use inline CSS to style the markup.', 'mc-leads-engine'); ?></p>
                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('Email Subject', 'mc-leads-engine'); ?></div>
                                                <input class="field-input" type="text" name="booking_user_email_subject" value="<?php echo esc_attr($settings['booking_user_email_subject'] ?? ''); ?>">
                                            </div>
                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('HTML Body Template', 'mc-leads-engine'); ?></div>
                                                <textarea class="field-input code-font" rows="12" name="booking_user_email_body"><?php echo esc_textarea($settings['booking_user_email_body'] ?? ''); ?></textarea>
                                                <span class="field-desc"><?php esc_html_e('Enter raw HTML with inline CSS. Dynamic bracket variables like [booking_type], [booking_date], [booking_time], [booking_location] will be replaced.', 'mc-leads-engine'); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Admin Email Tab -->
                                    <div class="settings-section-pane" data-pane="admin-email">
                                        <div class="card">
                                            <div class="card-title"><?php esc_html_e('Admin Email Notification Template', 'mc-leads-engine'); ?></div>
                                            <p class="field-desc-top"><?php esc_html_e('Configure the HTML notification email sent to the site admin upon submission.', 'mc-leads-engine'); ?></p>
                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('Email Subject', 'mc-leads-engine'); ?></div>
                                                <input class="field-input" type="text" name="admin_email_subject" value="<?php echo esc_attr($settings['admin_email_subject']); ?>">
                                            </div>
                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('HTML Body Template', 'mc-leads-engine'); ?></div>
                                                <textarea class="field-input code-font" rows="12" name="admin_email_body"><?php echo esc_textarea($settings['admin_email_body']); ?></textarea>
                                                <span class="field-desc"><?php esc_html_e('Sent to your Notification Email address. Use [all_answers] to print all survey fields.', 'mc-leads-engine'); ?></span>
                                            </div>
                                        </div>
                                        <div class="card" style="margin-top: 20px;">
                                            <div class="card-title"><?php esc_html_e('Booking Admin Email Notification Template', 'mc-leads-engine'); ?></div>
                                            <p class="field-desc-top"><?php esc_html_e('Configure the HTML notification email sent to the site admin when a booking is scheduled.', 'mc-leads-engine'); ?></p>
                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('Email Subject', 'mc-leads-engine'); ?></div>
                                                <input class="field-input" type="text" name="booking_admin_email_subject" value="<?php echo esc_attr($settings['booking_admin_email_subject'] ?? ''); ?>">
                                            </div>
                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('HTML Body Template', 'mc-leads-engine'); ?></div>
                                                <textarea class="field-input code-font" rows="12" name="booking_admin_email_body"><?php echo esc_textarea($settings['booking_admin_email_body'] ?? ''); ?></textarea>
                                                <span class="field-desc"><?php esc_html_e('Sent to your Notification Email address.', 'mc-leads-engine'); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- WhatsApp Tab -->
                                    <div class="settings-section-pane" data-pane="whatsapp">
                                        <div class="card">
                                            <div class="card-title"><?php esc_html_e('WhatsApp Notification Settings', 'mc-leads-engine'); ?></div>
                                            
                                            <h3 style="margin-top: 15px; border-bottom: 1px solid var(--mc-border); padding-bottom: 5px; color: var(--mc-text); font-size: 14px; font-weight: 700;"><?php esc_html_e('Gateway API Settings', 'mc-leads-engine'); ?></h3>
                                            
                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('WhatsApp Gateway API Provider', 'mc-leads-engine'); ?></div>
                                                <select class="field-input" name="whatsapp_gateway" id="mc-spa-whatsapp-gateway">
                                                    <option value="ultramsg" <?php selected($settings['whatsapp_gateway'], 'ultramsg'); ?>>UltraMsg (Recommended)</option>
                                                    <option value="twilio" <?php selected($settings['whatsapp_gateway'], 'twilio'); ?>>Twilio SMS/WhatsApp</option>
                                                    <option value="cloud_api" <?php selected($settings['whatsapp_gateway'], 'cloud_api'); ?>>WhatsApp Business Cloud API (Meta)</option>
                                                    <option value="custom" <?php selected($settings['whatsapp_gateway'], 'custom'); ?>>Custom Webhook Gateway</option>
                                                </select>
                                            </div>
                                            <div class="settings-field">
                                                <div class="field-label" id="mc-spa-whatsapp-api-key-label"><?php esc_html_e('WhatsApp API Key / Access Token', 'mc-leads-engine'); ?></div>
                                                <input class="field-input" type="text" name="whatsapp_api_key" value="<?php echo esc_attr($settings['whatsapp_api_key']); ?>">
                                            </div>
                                            <div class="settings-field" id="mc-spa-whatsapp-instance-id-field">
                                                <div class="field-label" id="mc-spa-whatsapp-instance-id-label"><?php esc_html_e('Instance ID / Account SID / Webhook URL', 'mc-leads-engine'); ?></div>
                                                <input class="field-input" type="text" name="whatsapp_instance_id" value="<?php echo esc_attr($settings['whatsapp_instance_id']); ?>">
                                            </div>
                                            <div class="settings-field" id="mc-spa-whatsapp-sender-field">
                                                <div class="field-label"><?php esc_html_e('Sender Number / ID / Phone Number ID', 'mc-leads-engine'); ?></div>
                                                <input class="field-input" type="text" name="whatsapp_sender" value="<?php echo esc_attr($settings['whatsapp_sender']); ?>" placeholder="e.g. +14155238886">
                                            </div>

                                            <h3 style="margin-top: 25px; border-bottom: 1px solid var(--mc-border); padding-bottom: 5px; color: var(--mc-text); font-size: 14px; font-weight: 700;"><?php esc_html_e('Admin Alert Settings', 'mc-leads-engine'); ?></h3>
                                            
                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('Admin Recipient WhatsApp Phone Number', 'mc-leads-engine'); ?></div>
                                                <input class="field-input" type="text" name="admin_whatsapp_phone" value="<?php echo esc_attr($settings['admin_whatsapp_phone']); ?>" placeholder="e.g. +254712345678">
                                                <span class="field-desc"><?php esc_html_e('The administrator phone number in international format.', 'mc-leads-engine'); ?></span>
                                            </div>
                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('Leads Admin Alert Message Template', 'mc-leads-engine'); ?></div>
                                                <textarea class="field-input" rows="6" name="admin_whatsapp_body"><?php echo esc_textarea($settings['admin_whatsapp_body']); ?></textarea>
                                                <span class="field-desc"><?php esc_html_e('Plain text alert sent to the admin phone number for leads.', 'mc-leads-engine'); ?></span>
                                            </div>
                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('Booking Admin Alert Message Template', 'mc-leads-engine'); ?></div>
                                                <textarea class="field-input" rows="6" name="booking_admin_whatsapp_body"><?php echo esc_textarea($settings['booking_admin_whatsapp_body'] ?? ''); ?></textarea>
                                                <span class="field-desc"><?php esc_html_e('Plain text alert sent to the admin phone number for bookings.', 'mc-leads-engine'); ?></span>
                                            </div>

                                            <h3 style="margin-top: 25px; border-bottom: 1px solid var(--mc-border); padding-bottom: 5px; color: var(--mc-text); font-size: 14px; font-weight: 700;"><?php esc_html_e('User Alert Settings', 'mc-leads-engine'); ?></h3>

                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('Leads User Alert Message Template', 'mc-leads-engine'); ?></div>
                                                <textarea class="field-input" rows="6" name="user_whatsapp_body"><?php echo esc_textarea($settings['user_whatsapp_body']); ?></textarea>
                                                <span class="field-desc"><?php esc_html_e('Plain text notification sent directly to the client\'s phone number for leads.', 'mc-leads-engine'); ?></span>
                                            </div>
                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('Booking User Alert Message Template', 'mc-leads-engine'); ?></div>
                                                <textarea class="field-input" rows="6" name="booking_user_whatsapp_body"><?php echo esc_textarea($settings['booking_user_whatsapp_body'] ?? ''); ?></textarea>
                                                <span class="field-desc"><?php esc_html_e('Plain text notification sent directly to the client\'s phone number for bookings.', 'mc-leads-engine'); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Consent & Tracking Tab -->
                                    <div class="settings-section-pane" data-pane="consent">
                                        <div class="card">
                                            <div class="card-title"><?php esc_html_e('Cookie Consent Banner', 'mc-leads-engine'); ?></div>
                                            <p class="field-desc-top"><?php esc_html_e('Configure a premium, floating cookie banner to get consent for passive tracking. Core functionality is never blocked.', 'mc-leads-engine'); ?></p>
                                            
                                            <div class="settings-field">
                                                <label class="field-label" style="font-weight:600; display: flex; align-items: center; gap: 8px;">
                                                    <input type="checkbox" name="cookie_banner_enable" value="1" <?php checked($settings['cookie_banner_enable'] ?? 0, 1); ?>>
                                                    <?php esc_html_e('Enable Cookie Consent Banner', 'mc-leads-engine'); ?>
                                                </label>
                                            </div>
                                            
                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('Banner Heading Title', 'mc-leads-engine'); ?></div>
                                                <input class="field-input" type="text" name="cookie_banner_title" value="<?php echo esc_attr($settings['cookie_banner_title'] ?? ''); ?>">
                                            </div>
                                            
                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('Banner Body Message', 'mc-leads-engine'); ?></div>
                                                <textarea class="field-input" rows="4" name="cookie_banner_message"><?php echo esc_textarea($settings['cookie_banner_message'] ?? ''); ?></textarea>
                                            </div>
                                            
                                            <div class="settings-row">
                                                <div class="settings-field">
                                                    <div class="field-label"><?php esc_html_e('Accept Button Text', 'mc-leads-engine'); ?></div>
                                                    <input class="field-input" type="text" name="cookie_banner_btn_accept" value="<?php echo esc_attr($settings['cookie_banner_btn_accept'] ?? ''); ?>">
                                                </div>
                                                <div class="settings-field">
                                                    <div class="field-label"><?php esc_html_e('Reject Button Text', 'mc-leads-engine'); ?></div>
                                                    <input class="field-input" type="text" name="cookie_banner_btn_reject" value="<?php echo esc_attr($settings['cookie_banner_btn_reject'] ?? ''); ?>">
                                                </div>
                                                <div class="settings-field">
                                                    <div class="field-label"><?php esc_html_e('Customize Button Text', 'mc-leads-engine'); ?></div>
                                                    <input class="field-input" type="text" name="cookie_banner_btn_settings" value="<?php echo esc_attr($settings['cookie_banner_btn_settings'] ?? ''); ?>">
                                                </div>
                                            </div>

                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('Visual Design Theme', 'mc-leads-engine'); ?></div>
                                                <select class="field-input" name="cookie_banner_theme">
                                                    <option value="glassmorphism" <?php selected($settings['cookie_banner_theme'] ?? '', 'glassmorphism'); ?>>Glassmorphism (Premium)</option>
                                                    <option value="light" <?php selected($settings['cookie_banner_theme'] ?? '', 'light'); ?>>Sleek Light</option>
                                                    <option value="dark" <?php selected($settings['cookie_banner_theme'] ?? '', 'dark'); ?>>Modern Dark</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="card" style="margin-top:20px;">
                                            <div class="card-title"><?php esc_html_e('Google Analytics (gtag.js)', 'mc-leads-engine'); ?></div>
                                            <div class="settings-field">
                                                <label class="field-label" style="font-weight:600; display: flex; align-items: center; gap: 8px;">
                                                    <input type="checkbox" name="tracking_ga_enable" value="1" <?php checked($settings['tracking_ga_enable'] ?? 0, 1); ?>>
                                                    <?php esc_html_e('Enable Google Analytics Tracking', 'mc-leads-engine'); ?>
                                                </label>
                                            </div>
                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('Google Analytics Measurement ID', 'mc-leads-engine'); ?></div>
                                                <input class="field-input" type="text" name="tracking_ga_id" value="<?php echo esc_attr($settings['tracking_ga_id'] ?? ''); ?>" placeholder="G-XXXXXXXXXX">
                                            </div>
                                        </div>

                                        <div class="card" style="margin-top:20px;">
                                            <div class="card-title"><?php esc_html_e('Meta Pixel (Facebook Pixel)', 'mc-leads-engine'); ?></div>
                                            <div class="settings-field">
                                                <label class="field-label" style="font-weight:600; display: flex; align-items: center; gap: 8px;">
                                                    <input type="checkbox" name="tracking_pixel_enable" value="1" <?php checked($settings['tracking_pixel_enable'] ?? 0, 1); ?>>
                                                    <?php esc_html_e('Enable Meta Pixel Tracking', 'mc-leads-engine'); ?>
                                                </label>
                                            </div>
                                            <div class="settings-field">
                                                <div class="field-label"><?php esc_html_e('Meta Pixel ID', 'mc-leads-engine'); ?></div>
                                                <input class="field-input" type="text" name="tracking_pixel_id" value="<?php echo esc_attr($settings['tracking_pixel_id'] ?? ''); ?>" placeholder="e.g. 1234567890">
                                            </div>
                                        </div>

                                        <div class="card" style="margin-top:20px;">
                                            <div class="card-title"><?php esc_html_e('WhatsApp Click Tracking', 'mc-leads-engine'); ?></div>
                                            <p class="field-desc-top"><?php esc_html_e('Track clicks to WhatsApp wa.me / api.whatsapp.com links dynamically on your site when tracking consent is granted.', 'mc-leads-engine'); ?></p>
                                            <div class="settings-field">
                                                <label class="field-label" style="font-weight:600; display: flex; align-items: center; gap: 8px;">
                                                    <input type="checkbox" name="tracking_whatsapp_click" value="1" <?php checked($settings['tracking_whatsapp_click'] ?? 0, 1); ?>>
                                                    <?php esc_html_e('Enable WhatsApp click events conversion tracking', 'mc-leads-engine'); ?>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Placeholders Tab -->
                                    <div class="settings-section-pane" data-pane="placeholders">
                                        <div class="card">
                                            <div class="card-title"><?php esc_html_e('Dynamic Placeholders Cheat Sheet', 'mc-leads-engine'); ?></div>
                                            <p><?php esc_html_e('Use these tags in email subjects, bodies, or WhatsApp messages to embed client entries.', 'mc-leads-engine'); ?></p>
                                            <table class="wp-list-table widefat striped" style="margin-top: 15px;">
                                                <thead>
                                                    <tr>
                                                        <th style="padding: 10px; font-weight: bold; width: 30%;"><?php esc_html_e('Bracket Tag', 'mc-leads-engine'); ?></th>
                                                        <th style="padding: 10px; font-weight: bold;"><?php esc_html_e('Replaced With', 'mc-leads-engine'); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td><code class="mc-code-badge">[lead_id]</code></td>
                                                        <td><?php esc_html_e('Database record ID.', 'mc-leads-engine'); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><code class="mc-code-badge">[total_price]</code></td>
                                                        <td><?php esc_html_e('Calculated project pricing total.', 'mc-leads-engine'); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><code class="mc-code-badge">[lead_score]</code></td>
                                                        <td><?php esc_html_e('Calculated project lead score.', 'mc-leads-engine'); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><code class="mc-code-badge">[survey_title]</code></td>
                                                        <td><?php esc_html_e('The title of the survey form.', 'mc-leads-engine'); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><code class="mc-code-badge">[all_answers]</code></td>
                                                        <td><?php esc_html_e('Summary table/list of all answers.', 'mc-leads-engine'); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><code class="mc-code-badge">[slugified-question-text]</code></td>
                                                        <td><?php esc_html_e('Answer to standard question slug, e.g. [what-is-your-business-name].', 'mc-leads-engine'); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td><code class="mc-code-badge">[cf7-field-name]</code></td>
                                                        <td><?php esc_html_e('Integrated CF7 field values, e.g. [your-name], [your-email].', 'mc-leads-engine'); ?></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <!-- Pricing Rules Tab -->
                                    <div class="settings-section-pane" data-pane="pricing" id="panel-pricing">
                                        <!-- Panel intro description & sample rule -->
                                        <div class="pricing-intro-card">
                                            <div class="pricing-intro-title">
                                                <span class="dashicons dashicons-info"></span>
                                                <?php esc_html_e('How Pricing Rules Work', 'mc-leads-engine'); ?>
                                            </div>
                                            <p class="pricing-intro-text">
                                                <?php esc_html_e('Rules calculate dynamic pricing and lead scores based on the answers selected in surveys. The engine looks for keywords matched against question texts or option values.', 'mc-leads-engine'); ?>
                                            </p>
                                            <div class="pricing-sample-rule">
                                                <strong><?php esc_html_e('Sample Rule Example:', 'mc-leads-engine'); ?></strong>
                                                <ul>
                                                    <li><strong><?php esc_html_e('Name:', 'mc-leads-engine'); ?></strong> <?php esc_html_e('SEO Addon', 'mc-leads-engine'); ?></li>
                                                    <li><strong><?php esc_html_e('Type:', 'mc-leads-engine'); ?></strong> <span class="rule-type-badge badge-option"><?php esc_html_e('Option Match', 'mc-leads-engine'); ?></span></li>
                                                    <li><strong><?php esc_html_e('Match Keyword:', 'mc-leads-engine'); ?></strong> <code>seo</code></li>
                                                    <li><strong><?php esc_html_e('Amount (KES):', 'mc-leads-engine'); ?></strong> <code>50,000</code></li>
                                                    <li><strong><?php esc_html_e('Explanation:', 'mc-leads-engine'); ?></strong> <?php esc_html_e('If the customer selects an option containing "seo" (e.g. "SEO Package"), KES 50,000 is added to their estimate.', 'mc-leads-engine'); ?></li>
                                                </ul>
                                            </div>
                                        </div>

                                        <!-- Base price + header -->
                                        <div class="pricing-header-row">
                                            <div class="pricing-base-wrap">
                                                <label class="pricing-base-label"><?php esc_html_e('Base Price (KES)', 'mc-leads-engine'); ?></label>
                                                <input type="number" id="mc-base-price" class="pricing-base-input" value="<?php echo esc_attr((float) ($settings['default_base_price'] ?? 0)); ?>" min="0" step="1" placeholder="0">
                                                <span class="pricing-base-hint"><?php esc_html_e('Applied to every lead before rules', 'mc-leads-engine'); ?></span>
                                            </div>
                                            <button type="button" id="mc-add-rule-btn" class="btn">
                                                <span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e('Add Rule', 'mc-leads-engine'); ?>
                                            </button>
                                        </div>

                                        <!-- Inline add/edit form (hidden by default) -->
                                        <div id="mc-rule-form" class="pricing-rule-form" style="display:none">
                                            <div class="pricing-rule-form-grid">
                                                <div class="pricing-form-field">
                                                    <label><?php esc_html_e('Rule Name', 'mc-leads-engine'); ?></label>
                                                    <input type="text" id="prf-name" class="field-input" placeholder="<?php esc_attr_e('e.g. SEO Package', 'mc-leads-engine'); ?>">
                                                </div>
                                                <div class="pricing-form-field">
                                                    <label><?php esc_html_e('Type', 'mc-leads-engine'); ?></label>
                                                    <select id="prf-type" class="filter-select">
                                                        <option value="fixed"><?php esc_html_e('Fixed — always adds amount', 'mc-leads-engine'); ?></option>
                                                        <option value="per_unit"><?php esc_html_e('Per Unit — amount × answer number', 'mc-leads-engine'); ?></option>
                                                        <option value="option"><?php esc_html_e('Option Match — adds amount if option selected', 'mc-leads-engine'); ?></option>
                                                    </select>
                                                </div>
                                                <div class="pricing-form-field">
                                                    <label><?php esc_html_e('Match Keyword', 'mc-leads-engine'); ?></label>
                                                    <input type="text" id="prf-match" class="field-input" placeholder="<?php esc_attr_e('e.g. seo, pages, booking', 'mc-leads-engine'); ?>">
                                                    <span class="pricing-field-hint"><?php esc_html_e('Matched against question text and selected answers', 'mc-leads-engine'); ?></span>
                                                </div>
                                                <div class="pricing-form-field">
                                                    <label><?php esc_html_e('Amount (KES)', 'mc-leads-engine'); ?></label>
                                                    <input type="number" id="prf-amount" class="field-input" placeholder="0" min="0" step="1">
                                                </div>
                                                <div class="pricing-form-field">
                                                    <label><?php esc_html_e('Score Impact', 'mc-leads-engine'); ?></label>
                                                    <input type="number" id="prf-score" class="field-input" placeholder="0" step="1">
                                                    <span class="pricing-field-hint"><?php esc_html_e('Optional — adds to the lead score', 'mc-leads-engine'); ?></span>
                                                </div>
                                            </div>
                                            <div class="pricing-form-actions">
                                                <button type="button" id="mc-rule-save-btn" class="btn"><?php esc_html_e('Save Rule', 'mc-leads-engine'); ?></button>
                                                <button type="button" id="mc-rule-cancel-btn" class="btn-ghost"><?php esc_html_e('Cancel', 'mc-leads-engine'); ?></button>
                                            </div>
                                        </div>

                                        <!-- Rule list -->
                                        <div id="mc-rule-list" class="rule-grid">
                                            <?php if (empty($pricing_rules)) : ?>
                                                <div class="pricing-empty" id="mc-rule-empty">
                                                    <span class="dashicons dashicons-tag"></span>
                                                    <span><?php esc_html_e('No pricing rules yet. Click "Add Rule" to get started.', 'mc-leads-engine'); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Pricing simulator -->
                                        <div class="card pricing-simulator" style="margin-top:16px">
                                            <div class="card-title"><?php esc_html_e('Pricing Simulator', 'mc-leads-engine'); ?> <span><?php esc_html_e('Test your rules', 'mc-leads-engine'); ?></span></div>
                                            <div class="pricing-sim-controls">
                                                <select id="mc-sim-survey" class="filter-select">
                                                    <option value="0"><?php esc_html_e('Select a survey…', 'mc-leads-engine'); ?></option>
                                                    <?php foreach ($surveys as $survey) : ?>
                                                        <option value="<?php echo esc_attr($survey['id']); ?>"><?php echo esc_html($survey['title']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="button" id="mc-sim-run" class="btn">
                                                    <span class="dashicons dashicons-controls-play"></span> <?php esc_html_e('Simulate', 'mc-leads-engine'); ?>
                                                </button>
                                            </div>
                                            <div id="mc-sim-result" class="pricing-sim-result" style="display:none"></div>
                                        </div>

                                        <!-- Hidden data bridge: initial rules from PHP → JS -->
                                        <textarea id="mc-pricing-rules-data" style="display:none"><?php echo esc_textarea(wp_json_encode($pricing_rules, JSON_UNESCAPED_UNICODE)); ?></textarea>
                                        <input type="hidden" id="mc-pricing-nonce" value="<?php echo esc_attr(wp_create_nonce('mc_leads_engine_nonce')); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div style="margin-top:14px"><button class="btn primary" type="submit"><?php esc_html_e('Save Notification Settings', 'mc-leads-engine'); ?></button></div>
                        </form>
                    </section>
                </div>
            </main>
        </div>
    </div>
    <?php
}

/* ── AJAX: Save pricing rules ────────────────────────────── */
function mc_leads_engine_ajax_save_pricing_rules() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Forbidden', 403);
    }

    check_ajax_referer('mc_leads_engine_nonce', 'nonce');

    $rules_raw = isset($_POST['rules']) ? wp_unslash($_POST['rules']) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    $rules = json_decode($rules_raw, true);

    if (!is_array($rules)) {
        wp_send_json_error('Invalid rules format');
    }

    $clean_rules = array();
    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $clean_rules[] = array(
            'name'         => sanitize_text_field($rule['name'] ?? ''),
            'type'         => sanitize_key($rule['type'] ?? 'fixed'),
            'match'        => sanitize_text_field($rule['match'] ?? ''),
            'amount'       => is_numeric($rule['amount'] ?? '') ? (float) $rule['amount'] : 0,
            'score_impact' => isset($rule['score_impact']) && is_numeric($rule['score_impact']) ? (int) $rule['score_impact'] : 0,
        );
    }

    $base_price = isset($_POST['base_price']) && is_numeric($_POST['base_price']) ? (float) $_POST['base_price'] : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

    $settings = mc_leads_engine_get_settings();
    $settings['default_pricing_rules_json'] = wp_json_encode($clean_rules, JSON_UNESCAPED_UNICODE);
    $settings['default_base_price'] = $base_price;
    update_option('mc_leads_engine_settings', $settings);

    wp_send_json_success(array(
        'rules'      => $clean_rules,
        'base_price' => $base_price,
    ));
}
add_action('wp_ajax_mc_leads_engine_save_pricing_rules', 'mc_leads_engine_ajax_save_pricing_rules');

/* ── AJAX: Simulate pricing ──────────────────────────────── */
function mc_leads_engine_ajax_simulate_pricing() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Forbidden', 403);
    }

    check_ajax_referer('mc_leads_engine_nonce', 'nonce');

    $survey_id = absint($_POST['survey_id'] ?? 0);
    $result = mc_leads_engine_pricing_engine()->calculate_survey_price(array(), $survey_id);

    $breakdown = array();
    foreach ($result['breakdown'] as $row) {
        if ((float) $row['amount'] === 0.0) {
            continue;
        }
        $breakdown[] = array(
            'label'     => $row['label'],
            'amount'    => $row['amount'],
            'rule_type' => $row['rule_type'],
        );
    }

    wp_send_json_success(array(
        'total'     => $result['total_price'],
        'score'     => $result['lead_score'],
        'breakdown' => $breakdown,
    ));
}
add_action('wp_ajax_mc_leads_engine_simulate_pricing', 'mc_leads_engine_ajax_simulate_pricing');
