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
    $daily_stats = mc_leads_engine_leads_repository()->get_daily_lead_stats(30);

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
        <div class="mc-admin-app">
            <main class="mc-admin-main">
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

                <div class="mc-admin-content">
                    <section class="panel<?php echo $panel === 'dashboard' ? ' active' : ''; ?>" id="panel-dashboard" data-panel="dashboard">
                        <div class="stat-grid">
                            <div class="stat-card">
                                <div class="stat-label"><span class="dashicons dashicons-groups"></span> <?php esc_html_e('Total Leads', 'mc-leads-engine'); ?></div>
                                <div class="stat-value"><?php echo esc_html(number_format_i18n($analytics['total_leads'])); ?></div>
                                <div class="stat-delta"><?php echo esc_html(sprintf(__('↑ %s this month', 'mc-leads-engine'), number_format_i18n((float) ($analytics_data['survey_completions'] ? array_sum(array_map('intval', $analytics_data['survey_completions'])) : 0)))); ?></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-label"><span class="dashicons dashicons-analytics"></span> <?php esc_html_e('Active Surveys', 'mc-leads-engine'); ?></div>
                                <div class="stat-value"><?php echo esc_html(count(array_filter($surveys, function ($survey) { return ($survey['status'] ?? '') === 'published'; }))); ?></div>
                                <div class="stat-delta"><?php esc_html_e('Live and collecting leads', 'mc-leads-engine'); ?></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-label"><span class="dashicons dashicons-money-alt"></span> <?php esc_html_e('Avg Lead Value', 'mc-leads-engine'); ?></div>
                                <div class="stat-value"><?php echo esc_html(number_format_i18n($analytics['avg_value'], 2)); ?></div>
                                <div class="stat-delta"><?php esc_html_e('KES avg estimate', 'mc-leads-engine'); ?></div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-label"><span class="dashicons dashicons-star-filled"></span> <?php esc_html_e('High Value', 'mc-leads-engine'); ?></div>
                                <div class="stat-value"><?php echo esc_html(count(array_filter($leads, function ($lead) { return (int) ($lead['lead_score'] ?? 0) >= 80; }))); ?></div>
                                <div class="stat-delta"><?php esc_html_e('Score ≥ 80', 'mc-leads-engine'); ?></div>
                            </div>
                        </div>

                        <div class="chart-row">
                            <div class="card">
                                <div class="card-title"><?php esc_html_e('Leads over time', 'mc-leads-engine'); ?> <span><?php esc_html_e('Last 30 days', 'mc-leads-engine'); ?></span></div>
                                <?php echo mc_leads_engine_admin_render_chart_bars($lead_daily_values, 'mini-bars'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </div>
                            <div class="card">
                                <div class="card-title"><?php esc_html_e('Survey performance', 'mc-leads-engine'); ?></div>
                                <div class="survey-performance-list">
                                    <?php foreach (array_slice($survey_summaries, 0, 4) as $survey_summary) : ?>
                                        <div class="performance-row">
                                            <div class="performance-head">
                                                <span><?php echo esc_html($survey_summary['title']); ?></span>
                                                <strong><?php echo esc_html($survey_summary['leads']); ?></strong>
                                            </div>
                                            <div class="performance-bar-track">
                                                <div class="performance-bar-fill" style="width: <?php echo esc_attr(min(100, max(8, $survey_summary['leads'] ? ($survey_summary['leads'] / $survey_peak_leads * 100) : 0))); ?>%"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="panel<?php echo $panel === 'surveys' ? ' active' : ''; ?>" id="panel-surveys" data-panel="surveys">
                        <div class="survey-dashboard-header">
                            <div class="survey-dashboard-row">
                                <div style="display:flex; align-items:center; gap:12px; flex-grow:1; flex-wrap:wrap;">
                                    <label class="field-label" style="font-weight:700; text-transform:uppercase; font-size:11px; letter-spacing:0.04em; color:var(--mc-muted); margin:0;"><?php esc_html_e('Active Survey:', 'mc-leads-engine'); ?></label>
                                    <select class="filter-select" style="max-width:240px; margin:0;" onchange="window.location.href = 'admin.php?page=mc-leads-engine-surveys&mc_panel=surveys&survey_id=' + this.value">
                                        <option value="0"><?php esc_html_e('-- Create/Select Survey --', 'mc-leads-engine'); ?></option>
                                        <?php foreach ($surveys as $survey) : ?>
                                            <option value="<?php echo esc_attr($survey['id']); ?>" <?php selected($selected_survey_id, $survey['id']); ?>><?php echo esc_html($survey['title']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($selected_survey_id) : ?>
                                        <div style="font-size:11px; background:#f1f5f9; border:1px solid #cbd5e1; padding:4px 8px; border-radius:4px; color:var(--mc-text);">
                                            <?php esc_html_e('Shortcode:', 'mc-leads-engine'); ?> <code style="background:none; padding:0; border:none; color:var(--mc-brand); font-weight:600;"><?php echo esc_html(mc_leads_engine_get_survey_shortcode($selected_survey_id)); ?></code>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div style="display:flex; gap:8px; align-items:center;">
                                    <a class="btn primary" href="<?php echo esc_url(add_query_arg(array('page' => 'mc-leads-engine-surveys', 'mc_panel' => 'surveys', 'survey_id' => 0), admin_url('admin.php'))); ?>">
                                        <span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e('New Survey', 'mc-leads-engine'); ?>
                                    </a>
                                    <?php if ($selected_survey_id) : ?>
                                        <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Delete this survey?', 'mc-leads-engine')); ?>');" style="display:inline-block; margin:0;">
                                            <?php wp_nonce_field('mc_leads_engine_admin_action', 'mc_leads_engine_nonce'); ?>
                                            <input type="hidden" name="page" value="mc-leads-engine-surveys">
                                            <input type="hidden" name="mc_panel" value="surveys">
                                            <input type="hidden" name="mc_leads_engine_action" value="delete_survey">
                                            <input type="hidden" name="survey_id" value="<?php echo esc_attr($selected_survey_id); ?>">
                                            <button class="button button-link-delete" style="border:1px solid #ef4444; color:#ef4444; background:none; padding:6px 12px; border-radius:6px; cursor:pointer; font-weight:600; height:34px; line-height:1;" type="submit"><?php esc_html_e('Delete', 'mc-leads-engine'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($selected_survey_id === 0 || $selected_survey) : ?>
                                <?php $survey_settings = mc_leads_engine_get_survey_settings($selected_survey_id); ?>
                                <form method="post" class="survey-dashboard-grid-2col">
                                    <?php wp_nonce_field('mc_leads_engine_admin_action', 'mc_leads_engine_nonce'); ?>
                                    <input type="hidden" name="page" value="mc-leads-engine-surveys">
                                    <input type="hidden" name="mc_panel" value="surveys">
                                    <input type="hidden" name="mc_leads_engine_action" value="save_survey">
                                    <input type="hidden" name="survey_id" value="<?php echo esc_attr($selected_survey_id); ?>">
                                    
                                    <!-- Column 1: Title, Status, Final Step Titles, Toggles & Save Button -->
                                    <div class="survey-dashboard-left-col">
                                        <div class="settings-field">
                                            <label class="field-label"><?php esc_html_e('Title', 'mc-leads-engine'); ?></label>
                                            <input class="field-input" type="text" name="title" value="<?php echo esc_attr($selected_survey['title'] ?? ''); ?>">
                                        </div>
                                        <div class="settings-field">
                                            <label class="field-label"><?php esc_html_e('Status', 'mc-leads-engine'); ?></label>
                                            <select class="field-input" name="status">
                                                <option value="draft" <?php selected(($selected_survey['status'] ?? 'draft'), 'draft'); ?>><?php esc_html_e('Draft', 'mc-leads-engine'); ?></option>
                                                <option value="published" <?php selected(($selected_survey['status'] ?? ''), 'published'); ?>><?php esc_html_e('Published', 'mc-leads-engine'); ?></option>
                                            </select>
                                        </div>
                                        <!-- Final Step Title and Button Text side-by-side -->
                                        <div class="survey-double-fields-row">
                                            <div class="settings-field" style="flex:1;">
                                                <label class="field-label"><?php esc_html_e('Final Step Title', 'mc-leads-engine'); ?></label>
                                                <input class="field-input" type="text" name="final_step_title" value="<?php echo esc_attr($survey_settings['final_step_title'] ?? ''); ?>">
                                            </div>
                                            <div class="settings-field" style="flex:1;">
                                                <label class="field-label"><?php esc_html_e('Final Step Button Text', 'mc-leads-engine'); ?></label>
                                                <input class="field-input" type="text" name="final_button_text" value="<?php echo esc_attr($survey_settings['final_button_text']); ?>">
                                            </div>
                                        </div>
                                        <!-- Checkboxes side-by-side -->
                                        <div class="survey-toggles-row">
                                            <div class="toggle-row" style="margin-bottom:0; justify-content:space-between; flex:1; height:38px; box-sizing:border-box;">
                                                <span class="toggle-label" style="font-size:12px;"><?php esc_html_e('Show Estimated Price', 'mc-leads-engine'); ?></span>
                                                <input type="checkbox" class="toggle-checkbox" name="show_final_price" value="1" <?php checked(!empty($survey_settings['show_final_price'])); ?>>
                                            </div>
                                            <div class="toggle-row" style="margin-bottom:0; justify-content:space-between; flex:1; height:38px; box-sizing:border-box;">
                                                <span class="toggle-label" style="font-size:12px;"><?php esc_html_e('Show Lead Score', 'mc-leads-engine'); ?></span>
                                                <input type="checkbox" class="toggle-checkbox" name="show_final_score" value="1" <?php checked(!empty($survey_settings['show_final_score'])); ?>>
                                            </div>
                                        </div>
                                        <div class="settings-field action-field">
                                            <button class="btn primary" type="submit" style="width:100%; height:38px; justify-content:center;"><?php esc_html_e('Save Survey Settings', 'mc-leads-engine'); ?></button>
                                        </div>
                                    </div>

                                    <!-- Column 2: Description & Final Step Message textareas -->
                                    <div class="survey-dashboard-right-col">
                                        <div class="settings-field">
                                            <label class="field-label"><?php esc_html_e('Description', 'mc-leads-engine'); ?></label>
                                            <textarea class="field-input" rows="5" name="description" style="height: auto; min-height: 120px; resize: vertical;"><?php echo esc_textarea($selected_survey['description'] ?? ''); ?></textarea>
                                        </div>
                                        <div class="settings-field">
                                            <label class="field-label"><?php esc_html_e('Final Step Message', 'mc-leads-engine'); ?></label>
                                            <textarea class="field-input" rows="5" name="final_message" style="height: auto; min-height: 120px; resize: vertical;" placeholder="<?php esc_attr_e('e.g. Review your answers below.', 'mc-leads-engine'); ?>"><?php echo esc_textarea($survey_settings['final_message']); ?></textarea>
                                        </div>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>

                        <!-- Bottom Content: 3-Column Builder Layout (Only if a survey is selected) -->
                        <?php if ($selected_survey_id && $selected_survey) : ?>
                            <div class="builder-layout">
                                <!-- Panel 1: Sections -->
                                <div class="builder-panel">
                                    <div class="panel-header section-header">
                                        <span><?php esc_html_e('Sections', 'mc-leads-engine'); ?></span>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="section-add-form">
                                            <?php wp_nonce_field('mc_leads_engine_admin_action', 'mc_leads_engine_nonce'); ?>
                                            <input type="hidden" name="page" value="mc-leads-engine-surveys">
                                            <input type="hidden" name="mc_panel" value="surveys">
                                            <input type="hidden" name="mc_leads_engine_action" value="save_section">
                                            <input type="hidden" name="survey_id" value="<?php echo esc_attr($selected_survey_id); ?>">
                                            <input type="hidden" name="section_id" value="0">
                                            <div class="section-add-row">
                                                <input class="field-input section-add-input" type="text" name="section_title" placeholder="<?php esc_attr_e('New section name', 'mc-leads-engine'); ?>">
                                                <button class="button button-primary" type="submit"><span class="dashicons dashicons-plus-alt2"></span></button>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="panel-body">
                                        <?php if (empty($sections)) : ?>
                                            <p class="description" style="padding:12px;"><?php esc_html_e('No sections yet. Add one above.', 'mc-leads-engine'); ?></p>
                                        <?php endif; ?>
                                        <?php foreach ($sections as $section) : ?>
                                            <div class="section-item<?php echo absint($section['id']) === $selected_section_id ? ' active' : ''; ?>" data-section-id="<?php echo absint($section['id']); ?>">
                                                <div class="section-left">
                                                    <a class="section-link" href="<?php echo esc_url(mc_leads_engine_admin_selected_url('surveys', $selected_survey_id, $section['id'], 0)); ?>">
                                                        <span class="section-title-text"><?php echo esc_html($section['title']); ?></span>
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
                                                <div class="section-meta">
                                                    <button type="button" class="section-edit-trigger" aria-label="<?php esc_attr_e('Edit section title', 'mc-leads-engine'); ?>"><span class="dashicons dashicons-edit"></span></button>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="section-delete-form">
                                                        <?php wp_nonce_field('mc_leads_engine_admin_action', 'mc_leads_engine_nonce'); ?>
                                                        <input type="hidden" name="page" value="mc-leads-engine-surveys">
                                                        <input type="hidden" name="mc_panel" value="surveys">
                                                        <input type="hidden" name="mc_leads_engine_action" value="delete_section">
                                                        <input type="hidden" name="survey_id" value="<?php echo esc_attr($selected_survey_id); ?>">
                                                        <input type="hidden" name="section_id" value="<?php echo esc_attr($section['id']); ?>">
                                                        <button type="submit" class="icon-btn del section-delete" aria-label="<?php esc_attr_e('Delete section', 'mc-leads-engine'); ?>"><span class="dashicons dashicons-trash"></span></button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Panel 2: Questions -->
                                <div class="builder-panel">
                                    <div class="panel-header">
                                        <span><?php esc_html_e('Questions', 'mc-leads-engine'); ?></span>
                                        <?php if ($selected_section_id) : ?>
                                            <a href="<?php echo esc_url($add_question_url); ?>" title="<?php esc_attr_e('Add question', 'mc-leads-engine'); ?>"><span class="dashicons dashicons-plus-alt2"></span></a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="panel-body question-drag-container" data-survey-id="<?php echo esc_attr($selected_survey_id); ?>" data-section-id="<?php echo esc_attr($selected_section_id); ?>">
                                        <?php if (!$selected_section_id) : ?>
                                            <p class="description" style="padding:12px;"><?php esc_html_e('Select a section to view questions.', 'mc-leads-engine'); ?></p>
                                        <?php elseif (empty($selected_questions)) : ?>
                                            <div class="empty-state">
                                                <p class="description" style="padding:12px 12px 0;"><?php esc_html_e('No questions in this section yet.', 'mc-leads-engine'); ?></p>
                                                <a class="empty-add" href="<?php echo esc_url($add_question_url); ?>"><span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e('Add question', 'mc-leads-engine'); ?></a>
                                            </div>
                                        <?php endif; ?>

                                        <?php foreach ($selected_questions as $question) : ?>
                                            <div class="q-card<?php echo absint($question['id']) === $selected_question_id ? ' active' : ''; ?>" data-question-id="<?php echo absint($question['id']); ?>">
                                                <a class="q-card-link" href="<?php echo esc_url(mc_leads_engine_admin_selected_url('surveys', $selected_survey_id, $selected_section_id, $question['id'])); ?>">
                                                    <div class="q-card-top">
                                                        <?php echo esc_html($question['question_text']); ?>
                                                    </div>
                                                    <div class="q-card-meta">
                                                        <?php if (!empty($question['required'])) : ?><span class="q-tag" style="background:var(--mc-brand-soft);color:var(--mc-brand);border-color:#B5D4F4"><?php esc_html_e('Required', 'mc-leads-engine'); ?></span><?php endif; ?>
                                                    </div>
                                                    <?php if (!empty($question['options'])) : ?>
                                                        <div class="q-options">
                                                            <?php foreach ($question['options'] as $option) : ?>
                                                                <div class="q-opt"><?php echo esc_html($option['label']); ?> <span class="price"><?php echo esc_html(($option['price_impact'] >= 0 ? '+' : '') . number_format_i18n((float) $option['price_impact'], 0)); ?></span></div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </a>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="q-card-delete-form">
                                                    <?php wp_nonce_field('mc_leads_engine_admin_action', 'mc_leads_engine_nonce'); ?>
                                                    <input type="hidden" name="page" value="mc-leads-engine-surveys">
                                                    <input type="hidden" name="mc_panel" value="surveys">
                                                    <input type="hidden" name="mc_leads_engine_action" value="delete_question">
                                                    <input type="hidden" name="survey_id" value="<?php echo esc_attr($selected_survey_id); ?>">
                                                    <input type="hidden" name="section_id" value="<?php echo esc_attr($selected_section_id); ?>">
                                                    <input type="hidden" name="question_id" value="<?php echo esc_attr($question['id']); ?>">
                                                    <span class="dashicons dashicons-menu q-card-drag-handle"></span>
                                                    <button type="submit" class="icon-btn del q-card-delete" aria-label="<?php esc_attr_e('Delete question', 'mc-leads-engine'); ?>"><span class="dashicons dashicons-trash"></span></button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Panel 3: Question Editor Settings -->
                                <div class="builder-panel">
                                    <div class="panel-header"><?php esc_html_e('Question Settings', 'mc-leads-engine'); ?></div>
                                    <div class="panel-body">
                                        <?php if (!$selected_survey || !$selected_section) : ?>
                                            <p class="description" style="padding:12px;"><?php esc_html_e('Select a section to edit questions.', 'mc-leads-engine'); ?></p>
                                        <?php else : ?>
                                            <form method="post">
                                                <?php wp_nonce_field('mc_leads_engine_admin_action', 'mc_leads_engine_nonce'); ?>
                                                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($current_url); ?>">
                                                <input type="hidden" name="mc_leads_engine_action" value="save_question">
                                                <input type="hidden" name="survey_id" value="<?php echo esc_attr($selected_survey_id); ?>">
                                                <input type="hidden" name="section_id" value="<?php echo esc_attr($selected_section_id); ?>">
                                                <input type="hidden" name="question_id" value="<?php echo esc_attr($selected_question['id'] ?? 0); ?>">
                                                <div class="settings-field">
                                                    <div class="field-label"><?php esc_html_e('Question text / CF7 shortcode', 'mc-leads-engine'); ?></div>
                                                    <textarea class="field-input" rows="2" name="question_text" placeholder="<?php esc_attr_e('Question text or CF7 shortcode', 'mc-leads-engine'); ?>"><?php echo esc_textarea($selected_question['question_text'] ?? ''); ?></textarea>
                                                    <div class="description"><?php esc_html_e('Use a Contact Form 7 shortcode here when the question type is Contact Form 7.', 'mc-leads-engine'); ?></div>
                                                </div>
                                                <div class="settings-field">
                                                    <div class="field-label"><?php esc_html_e('Answer type', 'mc-leads-engine'); ?></div>
                                                    <select class="field-input" name="type">
                                                        <option value="radio" <?php selected(($selected_question['type'] ?? ''), 'radio'); ?>><?php esc_html_e('Multiple Choice', 'mc-leads-engine'); ?></option>
                                                        <option value="checkbox" <?php selected(($selected_question['type'] ?? ''), 'checkbox'); ?>><?php esc_html_e('Multiple Choice (Multi)', 'mc-leads-engine'); ?></option>
                                                        <option value="number" <?php selected(($selected_question['type'] ?? ''), 'number'); ?>><?php esc_html_e('Number Input', 'mc-leads-engine'); ?></option>
                                                        <option value="text" <?php selected(($selected_question['type'] ?? ''), 'text'); ?>><?php esc_html_e('Text Input', 'mc-leads-engine'); ?></option>
                                                        <option value="cf7" <?php selected(($selected_question['type'] ?? ''), 'cf7'); ?>><?php esc_html_e('Contact Form 7', 'mc-leads-engine'); ?></option>
                                                    </select>
                                                </div>
                                                <div class="toggle-row">
                                                    <span class="toggle-label"><?php esc_html_e('Required', 'mc-leads-engine'); ?></span>
                                                    <input type="checkbox" class="toggle-checkbox" name="required" value="1" <?php checked(!empty($selected_question['required'])); ?>>
                                                </div>
                                                <div class="settings-field">
                                                    <div class="field-label"><?php esc_html_e('Order', 'mc-leads-engine'); ?></div>
                                                    <input class="field-input" type="number" name="order_index" value="<?php echo esc_attr($selected_question['order_index'] ?? 0); ?>">
                                                </div>
                                                <?php if (($selected_question['type'] ?? '') !== 'cf7') : ?>
                                                    <div style="margin-top:12px">
                                                        <div class="field-label"><?php esc_html_e('Options', 'mc-leads-engine'); ?></div>
                                                        <div class="rule-desc" style="margin-top:6px;">
                                                            <?php esc_html_e('Each option can change the price and score. Use Order to control display priority, and remove unused rows with the trash button.', 'mc-leads-engine'); ?>
                                                        </div>
                                                        <?php $existing_options = !empty($selected_question['id']) ? (new MC_Leads_Engine_Question())->get_options($selected_question['id']) : array(); ?>
                                                        <div class="rule-grid" data-option-builder data-next-index="<?php echo esc_attr(max(1, count($existing_options))); ?>">
                                                            <div class="options-list" data-option-list>
                                                                <?php for ($i = 0; $i < max(1, count($existing_options)); $i++) : $opt = $existing_options[$i] ?? array(); ?>
                                                                <div class="rule-card" data-option-row>
                                                                     <div class="rule-info">
                                                                         <div class="option-field-wrap">
                                                                             <label class="option-field-label"><?php esc_html_e('Label', 'mc-leads-engine'); ?></label>
                                                                             <input class="field-input" type="text" name="options[<?php echo esc_attr($i); ?>][label]" placeholder="<?php esc_attr_e('Label', 'mc-leads-engine'); ?>" value="<?php echo esc_attr($opt['label'] ?? ''); ?>">
                                                                         </div>
                                                                         <div class="option-field-wrap">
                                                                             <label class="option-field-label"><?php esc_html_e('Value', 'mc-leads-engine'); ?></label>
                                                                             <input class="field-input" type="text" name="options[<?php echo esc_attr($i); ?>][value]" placeholder="<?php esc_attr_e('Value', 'mc-leads-engine'); ?>" value="<?php echo esc_attr($opt['value'] ?? ''); ?>">
                                                                         </div>
                                                                         <div class="option-field-wrap description-field-wrap">
                                                                             <label class="option-field-label"><?php esc_html_e('Description', 'mc-leads-engine'); ?></label>
                                                                             <input class="field-input" type="text" name="options[<?php echo esc_attr($i); ?>][description]" placeholder="<?php esc_attr_e('Description (optional)', 'mc-leads-engine'); ?>" value="<?php echo esc_attr($opt['description'] ?? ''); ?>">
                                                                         </div>
                                                                         <div class="rule-field-grid">
                                                                             <div class="option-field-wrap">
                                                                                 <label class="option-field-label"><?php esc_html_e('Price', 'mc-leads-engine'); ?></label>
                                                                                 <input class="field-input" type="number" step="0.01" name="options[<?php echo esc_attr($i); ?>][price_impact]" placeholder="<?php esc_attr_e('Price', 'mc-leads-engine'); ?>" value="<?php echo esc_attr($opt['price_impact'] ?? 0); ?>">
                                                                             </div>
                                                                             <div class="option-field-wrap">
                                                                                 <label class="option-field-label"><?php esc_html_e('Score', 'mc-leads-engine'); ?></label>
                                                                                 <input class="field-input" type="number" name="options[<?php echo esc_attr($i); ?>][score_impact]" placeholder="<?php esc_attr_e('Score', 'mc-leads-engine'); ?>" value="<?php echo esc_attr($opt['score_impact'] ?? 0); ?>">
                                                                             </div>
                                                                             <div class="option-field-wrap">
                                                                                 <label class="option-field-label"><?php esc_html_e('Order', 'mc-leads-engine'); ?></label>
                                                                                 <input class="field-input" type="number" name="options[<?php echo esc_attr($i); ?>][order_index]" placeholder="<?php esc_attr_e('Order', 'mc-leads-engine'); ?>" value="<?php echo esc_attr($opt['order_index'] ?? $i); ?>">
                                                                             </div>
                                                                             <div class="option-field-wrap delete-btn-wrap">
                                                                                 <label class="option-field-label">&nbsp;</label>
                                                                                 <button type="button" class="icon-btn del" data-remove-option-row><span class="dashicons dashicons-trash"></span></button>
                                                                             </div>
                                                                         </div>
                                                                     </div>
                                                                 </div>
                                                            <?php endfor; ?>
                                                        </div>
                                                        <template data-option-template>
                                                             <div class="rule-card" data-option-row>
                                                                 <div class="rule-info">
                                                                     <div class="option-field-wrap">
                                                                         <label class="option-field-label"><?php esc_html_e('Label', 'mc-leads-engine'); ?></label>
                                                                         <input class="field-input" type="text" name="options[__INDEX__][label]" placeholder="<?php esc_attr_e('Label', 'mc-leads-engine'); ?>" value="">
                                                                     </div>
                                                                     <div class="option-field-wrap">
                                                                         <label class="option-field-label"><?php esc_html_e('Value', 'mc-leads-engine'); ?></label>
                                                                         <input class="field-input" type="text" name="options[__INDEX__][value]" placeholder="<?php esc_attr_e('Value', 'mc-leads-engine'); ?>" value="">
                                                                     </div>
                                                                     <div class="option-field-wrap description-field-wrap">
                                                                         <label class="option-field-label"><?php esc_html_e('Description', 'mc-leads-engine'); ?></label>
                                                                         <input class="field-input" type="text" name="options[__INDEX__][description]" placeholder="<?php esc_attr_e('Description (optional)', 'mc-leads-engine'); ?>" value="">
                                                                     </div>
                                                                     <div class="rule-field-grid">
                                                                         <div class="option-field-wrap">
                                                                             <label class="option-field-label"><?php esc_html_e('Price', 'mc-leads-engine'); ?></label>
                                                                             <input class="field-input" type="number" step="0.01" name="options[__INDEX__][price_impact]" placeholder="<?php esc_attr_e('Price', 'mc-leads-engine'); ?>" value="0">
                                                                         </div>
                                                                         <div class="option-field-wrap">
                                                                             <label class="option-field-label"><?php esc_html_e('Score', 'mc-leads-engine'); ?></label>
                                                                             <input class="field-input" type="number" name="options[__INDEX__][score_impact]" placeholder="<?php esc_attr_e('Score', 'mc-leads-engine'); ?>" value="0">
                                                                         </div>
                                                                         <div class="option-field-wrap">
                                                                             <label class="option-field-label"><?php esc_html_e('Order', 'mc-leads-engine'); ?></label>
                                                                             <input class="field-input" type="number" name="options[__INDEX__][order_index]" placeholder="<?php esc_attr_e('Order', 'mc-leads-engine'); ?>" value="0">
                                                                         </div>
                                                                         <div class="option-field-wrap delete-btn-wrap">
                                                                             <label class="option-field-label">&nbsp;</label>
                                                                             <button type="button" class="icon-btn del" data-remove-option-row><span class="dashicons dashicons-trash"></span></button>
                                                                         </div>
                                                                     </div>
                                                                 </div>
                                                             </div>
                                                        </template>
                                                        <div class="empty-add" data-add-option-row><span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e('Option', 'mc-leads-engine'); ?></div>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                                <div style="margin-top:14px"><button class="btn primary" type="submit"><?php esc_html_e('Save question', 'mc-leads-engine'); ?></button></div>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php else : ?>
                            <div class="notice notice-info inline" style="margin-top:10px;"><p><?php esc_html_e('Please create or select a survey to begin building sections and questions.', 'mc-leads-engine'); ?></p></div>
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

                        <div class="chart-row">
                            <div class="card">
                                <div class="card-title"><?php esc_html_e('Leads over time', 'mc-leads-engine'); ?> <span><?php esc_html_e('Last 30 days', 'mc-leads-engine'); ?></span></div>
                                <?php echo mc_leads_engine_admin_render_chart_bars($lead_daily_values, 'mini-bars'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </div>
                            <div class="card">
                                <div class="card-title"><?php esc_html_e('Revenue trend', 'mc-leads-engine'); ?> <span><?php esc_html_e('Last 30 days', 'mc-leads-engine'); ?></span></div>
                                <?php echo mc_leads_engine_admin_render_chart_bars($revenue_daily_values, 'mini-bars', 'hi'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </div>
                        </div>

                        <div class="card" style="margin-top:12px">
                            <div class="card-title"><?php esc_html_e('Survey performance', 'mc-leads-engine'); ?></div>
                            <div class="survey-performance-list">
                                <?php foreach ($survey_summaries as $survey_summary) : ?>
                                    <div class="performance-row">
                                        <div class="performance-head">
                                            <span><?php echo esc_html($survey_summary['title']); ?></span>
                                            <strong><?php echo esc_html($survey_summary['leads']); ?></strong>
                                        </div>
                                        <div class="performance-bar-track">
                                            <div class="performance-bar-fill" style="width: <?php echo esc_attr(min(100, max(8, $survey_summary['leads'] ? ($survey_summary['leads'] / $survey_peak_leads * 100) : 0))); ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

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
                                            <a class="icon-btn" href="<?php echo esc_url(admin_url('admin.php?page=mc-leads-engine-leads&lead_id=' . $lead['id'])); ?>"><span class="dashicons dashicons-visibility"></span></a>
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
