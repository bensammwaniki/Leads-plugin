<?php

if (!defined('ABSPATH')) {
    exit;
}

function mc_leads_engine_handle_settings_save() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'mc-leads-engine'));
    }

    if (empty($_POST['mc_leads_engine_action']) || $_POST['mc_leads_engine_action'] !== 'save_settings') {
        return;
    }

    check_admin_referer('mc_leads_engine_admin_action', 'mc_leads_engine_nonce');

    $current_settings = mc_leads_engine_get_settings();

    $settings = array(
        'notification_email'         => sanitize_email(wp_unslash($_POST['notification_email'] ?? $current_settings['notification_email'] ?? get_option('admin_email'))),
        'whatsapp_api_key'           => sanitize_text_field(wp_unslash($_POST['whatsapp_api_key'] ?? $current_settings['whatsapp_api_key'] ?? '')),
        'whatsapp_gateway'           => sanitize_text_field(wp_unslash($_POST['whatsapp_gateway'] ?? $current_settings['whatsapp_gateway'] ?? 'ultramsg')),
        'whatsapp_instance_id'       => sanitize_text_field(wp_unslash($_POST['whatsapp_instance_id'] ?? $current_settings['whatsapp_instance_id'] ?? '')),
        'whatsapp_sender'            => sanitize_text_field(wp_unslash($_POST['whatsapp_sender'] ?? $current_settings['whatsapp_sender'] ?? '')),
        'default_base_price'         => isset($_POST['default_base_price']) ? (float) $_POST['default_base_price'] : (float) ($current_settings['default_base_price'] ?? 0),
        'default_pricing_rules_json' => isset($_POST['default_pricing_rules_json']) ? sanitize_textarea_field(wp_unslash($_POST['default_pricing_rules_json'])) : ($current_settings['default_pricing_rules_json'] ?? '[]'),
        'thank_you_url'              => esc_url_raw(wp_unslash($_POST['thank_you_url'] ?? $current_settings['thank_you_url'] ?? '')),
        
        // New email and WhatsApp templates
        'user_email_subject'         => sanitize_text_field(wp_unslash($_POST['user_email_subject'] ?? $current_settings['user_email_subject'] ?? '')),
        'user_email_body'            => wp_kses_post(wp_unslash($_POST['user_email_body'] ?? $current_settings['user_email_body'] ?? '')),
        'admin_email_subject'        => sanitize_text_field(wp_unslash($_POST['admin_email_subject'] ?? $current_settings['admin_email_subject'] ?? '')),
        'admin_email_body'           => wp_kses_post(wp_unslash($_POST['admin_email_body'] ?? $current_settings['admin_email_body'] ?? '')),
        'admin_whatsapp_phone'       => sanitize_text_field(wp_unslash($_POST['admin_whatsapp_phone'] ?? $current_settings['admin_whatsapp_phone'] ?? '')),
        'admin_whatsapp_body'        => sanitize_textarea_field(wp_unslash($_POST['admin_whatsapp_body'] ?? $current_settings['admin_whatsapp_body'] ?? '')),
        'user_whatsapp_body'         => sanitize_textarea_field(wp_unslash($_POST['user_whatsapp_body'] ?? $current_settings['user_whatsapp_body'] ?? '')),
        
        // Booking notification templates
        'booking_user_email_subject' => sanitize_text_field(wp_unslash($_POST['booking_user_email_subject'] ?? $current_settings['booking_user_email_subject'] ?? '')),
        'booking_user_email_body'    => wp_kses_post(wp_unslash($_POST['booking_user_email_body'] ?? $current_settings['booking_user_email_body'] ?? '')),
        'booking_admin_email_subject'=> sanitize_text_field(wp_unslash($_POST['booking_admin_email_subject'] ?? $current_settings['booking_admin_email_subject'] ?? '')),
        'booking_admin_email_body'   => wp_kses_post(wp_unslash($_POST['booking_admin_email_body'] ?? $current_settings['booking_admin_email_body'] ?? '')),
        'booking_admin_whatsapp_body'=> sanitize_textarea_field(wp_unslash($_POST['booking_admin_whatsapp_body'] ?? $current_settings['booking_admin_whatsapp_body'] ?? '')),
        'booking_user_whatsapp_body' => sanitize_textarea_field(wp_unslash($_POST['booking_user_whatsapp_body'] ?? $current_settings['booking_user_whatsapp_body'] ?? '')),

        // Booking Settings variables
        'gcal_client_id'             => sanitize_text_field(wp_unslash($_POST['gcal_client_id'] ?? $current_settings['gcal_client_id'] ?? '')),
        'gcal_client_secret'         => sanitize_text_field(wp_unslash($_POST['gcal_client_secret'] ?? $current_settings['gcal_client_secret'] ?? '')),
        'gcal_calendar_id'           => sanitize_text_field(wp_unslash($_POST['gcal_calendar_id'] ?? $current_settings['gcal_calendar_id'] ?? 'primary')),
        'gcal_access_token'          => sanitize_text_field(wp_unslash($_POST['gcal_access_token'] ?? $current_settings['gcal_access_token'] ?? '')),
        'gcal_refresh_token'         => sanitize_text_field(wp_unslash($_POST['gcal_refresh_token'] ?? $current_settings['gcal_refresh_token'] ?? '')),
        'gcal_token_expires'         => isset($_POST['gcal_token_expires']) ? (int) $_POST['gcal_token_expires'] : (int) ($current_settings['gcal_token_expires'] ?? 0),
        'gmaps_api_key'              => sanitize_text_field(wp_unslash($_POST['gmaps_api_key'] ?? $current_settings['gmaps_api_key'] ?? '')),
        'booking_predefined_locations'=> sanitize_textarea_field(wp_unslash($_POST['booking_predefined_locations'] ?? $current_settings['booking_predefined_locations'] ?? '')),
        'booking_hours_start'        => sanitize_text_field(wp_unslash($_POST['booking_hours_start'] ?? $current_settings['booking_hours_start'] ?? '09:00')),
        'booking_hours_end'          => sanitize_text_field(wp_unslash($_POST['booking_hours_end'] ?? $current_settings['booking_hours_end'] ?? '17:00')),
        'booking_days'               => isset($_POST['booking_days']) && is_array($_POST['booking_days']) ? array_map('sanitize_key', $_POST['booking_days']) : ($current_settings['booking_days'] ?? array('1', '2', '3', '4', '5')),
        'booking_duration'           => isset($_POST['booking_duration']) ? (int) $_POST['booking_duration'] : (int) ($current_settings['booking_duration'] ?? 30),
        'booking_buffer'             => isset($_POST['booking_buffer']) ? (int) $_POST['booking_buffer'] : (int) ($current_settings['booking_buffer'] ?? 15),
        'booking_default_cf7'        => isset($_POST['booking_default_cf7']) ? (int) $_POST['booking_default_cf7'] : (int) ($current_settings['booking_default_cf7'] ?? 0),
        'booking_score_online'       => isset($_POST['booking_score_online']) ? (int) $_POST['booking_score_online'] : (int) ($current_settings['booking_score_online'] ?? 10),
        'booking_score_coffee'       => isset($_POST['booking_score_coffee']) ? (int) $_POST['booking_score_coffee'] : (int) ($current_settings['booking_score_coffee'] ?? 20),
        'booking_score_office'       => isset($_POST['booking_score_office']) ? (int) $_POST['booking_score_office'] : (int) ($current_settings['booking_score_office'] ?? 30),
        'booking_score_host'         => isset($_POST['booking_score_host']) ? (int) $_POST['booking_score_host'] : (int) ($current_settings['booking_score_host'] ?? 20),
        
        // Lead Scoring & Digest
        'score_hot_threshold'        => isset($_POST['score_hot_threshold']) ? absint($_POST['score_hot_threshold']) : (int) ($current_settings['score_hot_threshold'] ?? 80),
        'score_warm_threshold'       => isset($_POST['score_warm_threshold']) ? absint($_POST['score_warm_threshold']) : (int) ($current_settings['score_warm_threshold'] ?? 50),
        'digest_email_enable'        => isset($_POST['digest_email_enable']) ? 1 : 0,

        // Consent & Tracking Settings variables
        'cookie_banner_enable'       => isset($_POST['cookie_banner_enable']) ? 1 : 0,
        'cookie_banner_title'        => sanitize_text_field(wp_unslash($_POST['cookie_banner_title'] ?? $current_settings['cookie_banner_title'] ?? '')),
        'cookie_banner_message'      => sanitize_textarea_field(wp_unslash($_POST['cookie_banner_message'] ?? $current_settings['cookie_banner_message'] ?? '')),
        'cookie_banner_btn_accept'   => sanitize_text_field(wp_unslash($_POST['cookie_banner_btn_accept'] ?? $current_settings['cookie_banner_btn_accept'] ?? '')),
        'cookie_banner_btn_reject'   => sanitize_text_field(wp_unslash($_POST['cookie_banner_btn_reject'] ?? $current_settings['cookie_banner_btn_reject'] ?? '')),
        'cookie_banner_btn_settings' => sanitize_text_field(wp_unslash($_POST['cookie_banner_btn_settings'] ?? $current_settings['cookie_banner_btn_settings'] ?? '')),
        'cookie_banner_theme'        => sanitize_key($_POST['cookie_banner_theme'] ?? $current_settings['cookie_banner_theme'] ?? 'glassmorphism'),
        'tracking_ga_id'             => sanitize_text_field(wp_unslash($_POST['tracking_ga_id'] ?? $current_settings['tracking_ga_id'] ?? '')),
        'tracking_ga_enable'         => isset($_POST['tracking_ga_enable']) ? 1 : 0,
        'tracking_pixel_id'          => sanitize_text_field(wp_unslash($_POST['tracking_pixel_id'] ?? $current_settings['tracking_pixel_id'] ?? '')),
        'tracking_pixel_enable'      => isset($_POST['tracking_pixel_enable']) ? 1 : 0,
        'tracking_whatsapp_click'    => isset($_POST['tracking_whatsapp_click']) ? 1 : 0,
    );

    update_option('mc_leads_engine_settings', $settings);

    // Get fallback target redirect
    $redirect = mc_leads_engine_get_redirect_target(add_query_arg(array('page' => 'mc-leads-engine-settings', 'updated' => 1), admin_url('admin.php')));
    wp_safe_redirect($redirect);
    exit;
}
add_action('admin_init', 'mc_leads_engine_handle_settings_save');

function mc_leads_engine_handle_data_purge() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (empty($_POST['mc_leads_engine_purge_submit'])) {
        return;
    }

    check_admin_referer('mc_leads_engine_admin_action', 'mc_leads_engine_nonce');

    $range = sanitize_key($_POST['purge_range'] ?? '');
    if (!in_array($range, array('past_month', 'last_financial_year', 'all_time'), true)) {
        return;
    }

    global $wpdb;

    // Calculate start & end range
    $now = current_time('timestamp');
    $current_year = (int) wp_date('Y', $now);
    $current_month = (int) wp_date('n', $now);

    $start_date = null;
    $end_date   = null;

    if ($range === 'past_month') {
        $end_date = wp_date('Y-m-d H:i:s', $now - (30 * DAY_IN_SECONDS));
    } elseif ($range === 'last_financial_year') {
        // Fiscal Year: April 1 to March 31
        if ($current_month >= 4) {
            $start_year = $current_year - 1;
            $end_year = $current_year;
        } else {
            $start_year = $current_year - 2;
            $end_year = $current_year - 1;
        }
        $start_date = "{$start_year}-04-01 00:00:00";
        $end_date   = "{$end_year}-03-31 23:59:59";
    }

    // 1. Fetch lead IDs to delete
    $leads_table = mc_leads_engine_table('leads');
    $where = array('1=1');
    $params = array();

    if ($start_date !== null) {
        $where[] = "created_at >= %s";
        $params[] = $start_date;
    }
    if ($end_date !== null) {
        $where[] = "created_at <= %s";
        $params[] = $end_date;
    }
    $where_sql = implode(' AND ', $where);

    $sql = "SELECT id FROM {$leads_table} WHERE {$where_sql}";
    if (!empty($params)) {
        $sql = $wpdb->prepare($sql, $params);
    }
    $lead_ids = $wpdb->get_col($sql);

    // 2. Perform deletions
    if (!empty($lead_ids)) {
        $ids_placeholder = implode(',', array_map('intval', $lead_ids));

        $wpdb->query("DELETE FROM " . mc_leads_engine_table('lead_answers') . " WHERE lead_id IN ({$ids_placeholder})");
        $wpdb->query("DELETE FROM " . mc_leads_engine_table('lead_cf7_data') . " WHERE lead_id IN ({$ids_placeholder})");
        $wpdb->query("DELETE FROM " . mc_leads_engine_table('bookings') . " WHERE lead_id IN ({$ids_placeholder})");
        $wpdb->query("DELETE FROM {$leads_table} WHERE id IN ({$ids_placeholder})");
    }

    // 3. Purge orphaned bookings in date range
    $bookings_table = mc_leads_engine_table('bookings');
    $b_where = array('1=1');
    $b_params = array();
    if ($start_date !== null) {
        $b_where[] = "created_at >= %s";
        $b_params[] = $start_date;
    }
    if ($end_date !== null) {
        $b_where[] = "created_at <= %s";
        $b_params[] = $end_date;
    }
    $b_where_sql = implode(' AND ', $b_where);
    $b_sql = "DELETE FROM {$bookings_table} WHERE {$b_where_sql}";
    if (!empty($b_params)) {
        $b_sql = $wpdb->prepare($b_sql, $b_params);
    }
    $wpdb->query($b_sql);

    // 4. Purge step progress events in date range
    $steps_table = mc_leads_engine_table('step_events');
    $s_where = array('1=1');
    $s_params = array();
    if ($start_date !== null) {
        $s_where[] = "created_at >= %s";
        $s_params[] = $start_date;
    }
    if ($end_date !== null) {
        $s_where[] = "created_at <= %s";
        $s_params[] = $end_date;
    }
    $s_where_sql = implode(' AND ', $s_where);
    $s_sql = "DELETE FROM {$steps_table} WHERE {$s_where_sql}";
    if (!empty($s_params)) {
        $s_sql = $wpdb->prepare($s_sql, $s_params);
    }
    $wpdb->query($s_sql);

    wp_safe_redirect(add_query_arg(array('page' => 'mc-leads-engine-settings', 'purged' => 1), admin_url('admin.php')));
    exit;
}
add_action('admin_init', 'mc_leads_engine_handle_data_purge', 5);

function mc_leads_engine_render_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'mc-leads-engine'));
    }

    $settings = mc_leads_engine_get_settings();
    $surveys = mc_leads_engine_survey_repository()->get_surveys(array('limit' => 100));
    $pricing_rules = array();
    $pricing_rules_raw = trim((string) ($settings['default_pricing_rules_json'] ?? ''));
    if ($pricing_rules_raw !== '') {
        $decoded = json_decode($pricing_rules_raw, true);
        if (is_array($decoded)) {
            $pricing_rules = $decoded;
        }
    }
    ?>
    <div class="wrap mc-leads-engine-admin">
        <?php if (!empty($_GET['updated'])) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'mc-leads-engine'); ?></p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['purged'])) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Data purged successfully.', 'mc-leads-engine'); ?></p></div>
        <?php endif; ?>
        
        <form method="post" class="mc-settings-form">
            <?php wp_nonce_field('mc_leads_engine_admin_action', 'mc_leads_engine_nonce'); ?>
            <input type="hidden" name="mc_leads_engine_action" value="save_settings">
            
            <div class="topbar" style="margin-bottom:20px; border-radius:10px; border:1px solid var(--line); box-shadow:var(--shadow-sm); padding:18px 28px; background:var(--surface); display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <div class="topbar-title" style="font-size:19px; font-weight:800; letter-spacing:-.3px; color:var(--text);"><?php esc_html_e('Settings', 'mc-leads-engine'); ?></div>
                    <div class="topbar-sub" style="font-size:12px; color:var(--muted); margin-top:3px;"><?php esc_html_e('Notifications, scoring, integrations & data', 'mc-leads-engine'); ?></div>
                </div>
                <button class="btn primary" type="submit"><?php esc_html_e('Save changes', 'mc-leads-engine'); ?></button>
            </div>

            <div class="settings-shell">
                <!-- Left Sidebar Tabs -->
                <div class="settings-tabs">
                    <button type="button" class="settings-tab-btn active" data-tab="general">
                        <span class="ic">⚙</span> <?php esc_html_e('General', 'mc-leads-engine'); ?>
                    </button>
                    <button type="button" class="settings-tab-btn" data-tab="user-email">
                        <span class="ic">✉</span> <?php esc_html_e('User email', 'mc-leads-engine'); ?>
                    </button>
                    <button type="button" class="settings-tab-btn" data-tab="admin-email">
                        <span class="ic">📩</span> <?php esc_html_e('Admin email', 'mc-leads-engine'); ?>
                    </button>
                    <button type="button" class="settings-tab-btn" data-tab="whatsapp">
                        <span class="ic">💬</span> <?php esc_html_e('WhatsApp', 'mc-leads-engine'); ?>
                    </button>
                    <button type="button" class="settings-tab-btn" data-tab="booking">
                        <span class="ic">📅</span> <?php esc_html_e('Booking', 'mc-leads-engine'); ?>
                    </button>
                    <button type="button" class="settings-tab-btn" data-tab="consent">
                        <span class="ic">🛡</span> <?php esc_html_e('Consent & tracking', 'mc-leads-engine'); ?>
                    </button>
                    <button type="button" class="settings-tab-btn" data-tab="placeholders">
                        <span class="ic">{ }</span> <?php esc_html_e('Placeholders', 'mc-leads-engine'); ?>
                    </button>
                    <button type="button" class="settings-tab-btn" data-tab="pricing">
                        <span class="ic">💰</span> <?php esc_html_e('Pricing rules', 'mc-leads-engine'); ?>
                    </button>
                    <button type="button" class="settings-tab-btn" data-tab="purging">
                        <span class="ic">🗑</span> <?php esc_html_e('Data maintenance', 'mc-leads-engine'); ?> <span class="danger-dot"></span>
                    </button>
                </div>
                
                <!-- Right Content Panes -->
                <div class="settings-content" id="panes">
                    
                    <!-- GENERAL PANE -->
                    <div class="settings-section-pane active" data-pane="general">
                        <div class="card">
                            <div class="card-title"><?php esc_html_e('General settings', 'mc-leads-engine'); ?></div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('Admin notification email', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="email" name="notification_email" value="<?php echo esc_attr($settings['notification_email']); ?>">
                                <div class="field-hint"><?php esc_html_e('Where admin submission notifications are sent.', 'mc-leads-engine'); ?></div>
                            </div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('Thank-you redirect URL', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="url" name="thank_you_url" value="<?php echo esc_attr($settings['thank_you_url']); ?>">
                                <div class="field-hint"><?php esc_html_e('Fallback redirect destination after a lead is submitted.', 'mc-leads-engine'); ?></div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-title"><?php esc_html_e('Lead scoring thresholds', 'mc-leads-engine'); ?></div>
                            <div class="card-desc"><?php esc_html_e('These cutoffs decide the Hot / Warm / Cold badge shown across Leads, Analytics, and Bookings.', 'mc-leads-engine'); ?></div>
                            <div class="field-row">
                                <div class="field">
                                    <label class="field-label"><?php esc_html_e('Hot threshold (≥)', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="number" name="score_hot_threshold" min="0" max="1000" value="<?php echo esc_attr($settings['score_hot_threshold'] ?? 80); ?>">
                                </div>
                                <div class="field">
                                    <label class="field-label"><?php esc_html_e('Warm threshold (≥)', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="number" name="score_warm_threshold" min="0" max="1000" value="<?php echo esc_attr($settings['score_warm_threshold'] ?? 50); ?>">
                                </div>
                            </div>
                            <div class="threshold-viz">
                                <div class="threshold-track"></div>
                                <div class="threshold-marks"><span>0</span><span>50</span><span>80</span><span>200+</span></div>
                                <div class="threshold-legend">
                                    <span><i class="leg-dot" style="background:#d8dade"></i> <?php esc_html_e('Cold · 0–49', 'mc-leads-engine'); ?></span>
                                    <span><i class="leg-dot" style="background:var(--amber)"></i> <?php esc_html_e('Warm · 50–79', 'mc-leads-engine'); ?></span>
                                    <span><i class="leg-dot" style="background:var(--coral)"></i> <?php esc_html_e('Hot · 80+', 'mc-leads-engine'); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-title"><?php esc_html_e('Weekly digest email', 'mc-leads-engine'); ?></div>
                            <div class="card-desc"><?php esc_html_e('An automated summary of new lead counts, pipeline value, and hot-lead highlights, sent every Monday morning.', 'mc-leads-engine'); ?></div>
                            <div class="toggle-row">
                                <div>
                                    <div class="tlabel"><?php esc_html_e('Send weekly digest to admin notification address', 'mc-leads-engine'); ?></div>
                                    <div class="tdesc"><?php esc_html_e('Runs via WP-Cron · uses the address set above', 'mc-leads-engine'); ?></div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" name="digest_email_enable" value="1" <?php checked(!empty($settings['digest_email_enable'])); ?>>
                                    <span class="track"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- USER EMAIL PANE -->
                    <div class="settings-section-pane" data-pane="user-email">
                        <div class="card">
                            <div class="card-title"><?php esc_html_e('User email — Survey submission', 'mc-leads-engine'); ?></div>
                            <div class="card-desc"><?php esc_html_e('Sent to the client automatically after they complete a survey.', 'mc-leads-engine'); ?></div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('Email subject', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="user_email_subject" value="<?php echo esc_attr($settings['user_email_subject']); ?>">
                            </div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('HTML body template', 'mc-leads-engine'); ?></label>
                                <div class="code-box">
                                    <div class="code-box-head">
                                        <span>user_email_body.html</span>
                                        <span class="tag"><?php esc_html_e('inline CSS', 'mc-leads-engine'); ?></span>
                                    </div>
                                    <textarea class="code-editor" rows="12" name="user_email_body"><?php echo esc_textarea($settings['user_email_body']); ?></textarea>
                                </div>
                                <div class="field-hint"><?php esc_html_e('Bracket variables like [full-name] and [total_price] are replaced automatically.', 'mc-leads-engine'); ?></div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-title"><?php esc_html_e('User email — Booking confirmation', 'mc-leads-engine'); ?></div>
                            <div class="card-desc"><?php esc_html_e('Sent to the client after they schedule a meeting.', 'mc-leads-engine'); ?></div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('Email subject', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="booking_user_email_subject" value="<?php echo esc_attr($settings['booking_user_email_subject'] ?? ''); ?>">
                            </div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('HTML body template', 'mc-leads-engine'); ?></label>
                                <div class="code-box">
                                    <div class="code-box-head">
                                        <span>booking_user_email_body.html</span>
                                        <span class="tag"><?php esc_html_e('inline CSS', 'mc-leads-engine'); ?></span>
                                    </div>
                                    <textarea class="code-editor" rows="12" name="booking_user_email_body"><?php echo esc_textarea($settings['booking_user_email_body'] ?? ''); ?></textarea>
                                </div>
                                <div class="field-hint"><?php esc_html_e('Bracket variables like [booking_type], [booking_date], [booking_time], [booking_location] are replaced.', 'mc-leads-engine'); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ADMIN EMAIL PANE -->
                    <div class="settings-section-pane" data-pane="admin-email">
                        <div class="card">
                            <div class="card-title"><?php esc_html_e('Admin email — New lead alert', 'mc-leads-engine'); ?></div>
                            <div class="card-desc"><?php esc_html_e('Sent to your notification address whenever a survey is submitted.', 'mc-leads-engine'); ?></div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('Email subject', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="admin_email_subject" value="<?php echo esc_attr($settings['admin_email_subject']); ?>">
                            </div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('HTML body template', 'mc-leads-engine'); ?></label>
                                <div class="code-box">
                                    <div class="code-box-head">
                                        <span>admin_email_body.html</span>
                                        <span class="tag"><?php esc_html_e('inline CSS', 'mc-leads-engine'); ?></span>
                                    </div>
                                    <textarea class="code-editor" rows="12" name="admin_email_body"><?php echo esc_textarea($settings['admin_email_body']); ?></textarea>
                                </div>
                                <div class="field-hint"><?php echo sprintf(__('Use %s to print every survey field as a formatted block.', 'mc-leads-engine'), '<code class="ph-tag">[all_answers]</code>'); ?></div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-title"><?php esc_html_e('Admin email — New booking alert', 'mc-leads-engine'); ?></div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('Email subject', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="booking_admin_email_subject" value="<?php echo esc_attr($settings['booking_admin_email_subject'] ?? ''); ?>">
                            </div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('HTML body template', 'mc-leads-engine'); ?></label>
                                <div class="code-box">
                                    <div class="code-box-head">
                                        <span>booking_admin_email_body.html</span>
                                        <span class="tag"><?php esc_html_e('inline CSS', 'mc-leads-engine'); ?></span>
                                    </div>
                                    <textarea class="code-editor" rows="10" name="booking_admin_email_body"><?php echo esc_textarea($settings['booking_admin_email_body'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- WHATSAPP PANE -->
                    <div class="settings-section-pane" data-pane="whatsapp">
                        <div class="card">
                            <div class="card-title"><?php esc_html_e('Gateway API settings', 'mc-leads-engine'); ?></div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('Gateway provider', 'mc-leads-engine'); ?></label>
                                <select class="field-input" name="whatsapp_gateway" id="mc-whatsapp-gateway">
                                    <option value="ultramsg" <?php selected($settings['whatsapp_gateway'], 'ultramsg'); ?>>UltraMsg (recommended)</option>
                                    <option value="twilio" <?php selected($settings['whatsapp_gateway'], 'twilio'); ?>>Twilio SMS/WhatsApp</option>
                                    <option value="cloud_api" <?php selected($settings['whatsapp_gateway'], 'cloud_api'); ?>>WhatsApp Business Cloud API (Meta)</option>
                                    <option value="custom" <?php selected($settings['whatsapp_gateway'], 'custom'); ?>>Custom webhook gateway</option>
                                </select>
                            </div>
                            <div class="field-row">
                                <div class="field">
                                    <label class="field-label" id="mc-whatsapp-api-key-label"><?php esc_html_e('WhatsApp API Key / Access Token', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="password" name="whatsapp_api_key" value="<?php echo esc_attr($settings['whatsapp_api_key']); ?>">
                                </div>
                                <div class="field" id="mc-whatsapp-instance-id-field">
                                    <label class="field-label" id="mc-whatsapp-instance-id-label"><?php esc_html_e('Instance ID / Account SID / Webhook URL', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="text" name="whatsapp_instance_id" value="<?php echo esc_attr($settings['whatsapp_instance_id']); ?>">
                                </div>
                            </div>
                            <div class="field" id="mc-whatsapp-sender-field">
                                <label class="field-label"><?php esc_html_e('Sender Number / ID / Phone Number ID', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="whatsapp_sender" value="<?php echo esc_attr($settings['whatsapp_sender']); ?>" placeholder="e.g. +14155238886">
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-title"><?php esc_html_e('Admin alerts', 'mc-leads-engine'); ?></div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('Admin WhatsApp number', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="admin_whatsapp_phone" value="<?php echo esc_attr($settings['admin_whatsapp_phone']); ?>" placeholder="e.g. +254712345678">
                            </div>
                            <div class="field-row">
                                <div class="field">
                                    <label class="field-label"><?php esc_html_e('Lead alert message', 'mc-leads-engine'); ?></label>
                                    <textarea class="field-input" rows="5" name="admin_whatsapp_body"><?php echo esc_textarea($settings['admin_whatsapp_body']); ?></textarea>
                                </div>
                                <div class="field">
                                    <label class="field-label"><?php esc_html_e('Booking alert message', 'mc-leads-engine'); ?></label>
                                    <textarea class="field-input" rows="5" name="booking_admin_whatsapp_body"><?php echo esc_textarea($settings['booking_admin_whatsapp_body'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-title"><?php esc_html_e('Client alerts', 'mc-leads-engine'); ?></div>
                            <div class="field-row">
                                <div class="field">
                                    <label class="field-label"><?php esc_html_e('Lead confirmation message', 'mc-leads-engine'); ?></label>
                                    <textarea class="field-input" rows="5" name="user_whatsapp_body"><?php echo esc_textarea($settings['user_whatsapp_body']); ?></textarea>
                                </div>
                                <div class="field">
                                    <label class="field-label"><?php esc_html_e('Booking confirmation message', 'mc-leads-engine'); ?></label>
                                    <textarea class="field-input" rows="5" name="booking_user_whatsapp_body"><?php echo esc_textarea($settings['booking_user_whatsapp_body'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- BOOKING PANE -->
                    <div class="settings-section-pane" data-pane="booking">
                        <div class="card">
                            <div class="card-title"><?php esc_html_e('Google Calendar integration', 'mc-leads-engine'); ?></div>
                            <div class="card-desc"><?php esc_html_e('Syncs availability in real time to prevent double booking.', 'mc-leads-engine'); ?></div>
                            
                            <?php if (!empty($_GET['gcal_auth_success'])) : ?>
                                <div class="notice notice-success inline" style="margin-bottom:15px;"><p><?php esc_html_e('Successfully authorized with Google Calendar!', 'mc-leads-engine'); ?></p></div>
                            <?php endif; ?>

                            <div class="conn-card">
                                <div class="conn-left">
                                    <span class="conn-dot" style="background:<?php echo !empty($settings['gcal_access_token']) ? '#22c55e' : '#ef4444'; ?>;"></span>
                                    <div>
                                        <div class="conn-title">
                                            <?php 
                                            if (!empty($settings['gcal_access_token'])) {
                                                esc_html_e('Connected', 'mc-leads-engine');
                                            } else {
                                                esc_html_e('Not Connected', 'mc-leads-engine');
                                            }
                                            ?>
                                        </div>
                                        <div class="conn-sub">
                                            <?php 
                                            if (!empty($settings['gcal_access_token'])) {
                                                $expires_in = (int)($settings['gcal_token_expires'] ?? 0) - time();
                                                if ($expires_in > 0) {
                                                    printf(esc_html__('Access Token Active — Expires in %d min', 'mc-leads-engine'), round($expires_in / 60));
                                                } else {
                                                    esc_html_e('Access Token Expired — Auto refresh active', 'mc-leads-engine');
                                                }
                                            } else {
                                                esc_html_e('Google Account authorization is required to sync calendar slots.', 'mc-leads-engine');
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                                <?php
                                if (!empty($settings['gcal_client_id']) && !empty($settings['gcal_client_secret'])) {
                                    $auth_url = mc_leads_engine_booking()->get_gcal_client_auth_url();
                                    printf('<a class="btn" href="%s">%s</a>', esc_url($auth_url), esc_html__('Re-authorize', 'mc-leads-engine'));
                                } else {
                                    echo '<span class="description" style="color:#ef4444;">' . esc_html__('Save Credentials First', 'mc-leads-engine') . '</span>';
                                }
                                ?>
                            </div>

                            <div class="field-row">
                                <div class="field">
                                    <label class="field-label"><?php esc_html_e('Google client ID', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="text" name="gcal_client_id" value="<?php echo esc_attr($settings['gcal_client_id'] ?? ''); ?>">
                                </div>
                                <div class="field">
                                    <label class="field-label"><?php esc_html_e('Google client secret', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="password" name="gcal_client_secret" value="<?php echo esc_attr($settings['gcal_client_secret'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('Calendar ID', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="gcal_calendar_id" value="<?php echo esc_attr($settings['gcal_calendar_id'] ?? 'primary'); ?>">
                                <div class="field-hint"><?php esc_html_e('Leave as "primary" to use the default calendar, or paste a specific calendar resource ID.', 'mc-leads-engine'); ?></div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-title"><?php esc_html_e('Maps &amp; predefined meeting locations', 'mc-leads-engine'); ?></div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('Google Maps API key', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="password" name="gmaps_api_key" value="<?php echo esc_attr($settings['gmaps_api_key'] ?? ''); ?>">
                            </div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('Predefined locations', 'mc-leads-engine'); ?></label>
                                <textarea class="field-input" rows="4" name="booking_predefined_locations" placeholder="Java House, Westlands|Nairobi Garage, Kilimani"><?php echo esc_textarea($settings['booking_predefined_locations'] ?? ''); ?></textarea>
                                <div class="field-hint"><?php esc_html_e('Predefined spots separated by | (vertical bar). E.g. Java House, Westlands|Nairobi Garage, Kilimani', 'mc-leads-engine'); ?></div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-title"><?php esc_html_e('Working hours &amp; availability', 'mc-leads-engine'); ?></div>
                            <div class="field-row">
                                <div class="field">
                                    <label class="field-label"><?php esc_html_e('Daily start', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="text" name="booking_hours_start" value="<?php echo esc_attr($settings['booking_hours_start'] ?? '09:00'); ?>">
                                </div>
                                <div class="field">
                                    <label class="field-label"><?php esc_html_e('Daily end', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="text" name="booking_hours_end" value="<?php echo esc_attr($settings['booking_hours_end'] ?? '17:00'); ?>">
                                </div>
                            </div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('Working days', 'mc-leads-engine'); ?></label>
                                <div class="days-row">
                                    <?php 
                                    $saved_days = $settings['booking_days'] ?? array('1','2','3','4','5');
                                    $days_list = array(
                                        '1' => __('Mon', 'mc-leads-engine'),
                                        '2' => __('Tue', 'mc-leads-engine'),
                                        '3' => __('Wed', 'mc-leads-engine'),
                                        '4' => __('Thu', 'mc-leads-engine'),
                                        '5' => __('Fri', 'mc-leads-engine'),
                                        '6' => __('Sat', 'mc-leads-engine'),
                                        '7' => __('Sun', 'mc-leads-engine'),
                                    );
                                    foreach ($days_list as $num => $lbl) :
                                        $is_on = in_array((string)$num, $saved_days, true);
                                    ?>
                                        <label class="day-chip<?php echo $is_on ? ' on' : ''; ?>">
                                            <input type="checkbox" name="booking_days[]" value="<?php echo esc_attr($num); ?>" <?php checked($is_on); ?> onchange="this.parentElement.classList.toggle('on', this.checked);">
                                            <?php echo esc_html($lbl); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="field-row">
                                <div class="field">
                                    <label class="field-label"><?php esc_html_e('Slot duration (min)', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="number" name="booking_duration" value="<?php echo esc_attr($settings['booking_duration'] ?? 30); ?>">
                                </div>
                                <div class="field">
                                    <label class="field-label"><?php esc_html_e('Buffer between slots (min)', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="number" name="booking_buffer" value="<?php echo esc_attr($settings['booking_buffer'] ?? 15); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-title"><?php esc_html_e('Lead scoring by meeting type', 'mc-leads-engine'); ?></div>
                            <div class="card-desc"><?php esc_html_e('Points added to a lead\'s score based on which booking format they choose — a coffee meeting signals more intent than a quick call.', 'mc-leads-engine'); ?></div>
                            <div class="field-row">
                                <div class="field">
                                    <label class="field-label"><?php esc_html_e('Online video call', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="number" name="booking_score_online" value="<?php echo esc_attr($settings['booking_score_online'] ?? 10); ?>">
                                </div>
                                <div class="field">
                                    <label class="field-label"><?php esc_html_e('Coffee meeting', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="number" name="booking_score_coffee" value="<?php echo esc_attr($settings['booking_score_coffee'] ?? 20); ?>">
                                </div>
                            </div>
                            <div class="field-row">
                                <div class="field">
                                    <label class="field-label"><?php esc_html_e('Office visit', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="number" name="booking_score_office" value="<?php echo esc_attr($settings['booking_score_office'] ?? 30); ?>">
                                </div>
                                <div class="field">
                                    <label class="field-label"><?php esc_html_e('Predefined host location', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="number" name="booking_score_host" value="<?php echo esc_attr($settings['booking_score_host'] ?? 20); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- CONSENT & TRACKING PANE -->
                    <div class="settings-section-pane" data-pane="consent">
                        <div class="card">
                            <div class="card-title"><?php esc_html_e('Cookie consent banner', 'mc-leads-engine'); ?></div>
                            <div class="toggle-row" style="margin-bottom:14px;">
                                <div class="tlabel"><?php esc_html_e('Enable cookie consent banner', 'mc-leads-engine'); ?></div>
                                <label class="switch">
                                    <input type="checkbox" name="cookie_banner_enable" value="1" <?php checked($settings['cookie_banner_enable'] ?? 0, 1); ?>>
                                    <span class="track"></span>
                                </label>
                            </div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('Banner heading', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="cookie_banner_title" value="<?php echo esc_attr($settings['cookie_banner_title'] ?? ''); ?>">
                            </div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('Banner message', 'mc-leads-engine'); ?></label>
                                <textarea class="field-input" rows="3" name="cookie_banner_message"><?php echo esc_textarea($settings['cookie_banner_message'] ?? ''); ?></textarea>
                            </div>
                            <div class="field-row3">
                                <div class="field">
                                    <label class="field-label"><?php esc_html_e('Accept text', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="text" name="cookie_banner_btn_accept" value="<?php echo esc_attr($settings['cookie_banner_btn_accept'] ?? ''); ?>">
                                </div>
                                <div class="field">
                                    <label class="field-label"><?php esc_html_e('Reject text', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="text" name="cookie_banner_btn_reject" value="<?php echo esc_attr($settings['cookie_banner_btn_reject'] ?? ''); ?>">
                                </div>
                                <div class="field">
                                    <label class="field-label"><?php esc_html_e('Customize text', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="text" name="cookie_banner_btn_settings" value="<?php echo esc_attr($settings['cookie_banner_btn_settings'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('Visual theme', 'mc-leads-engine'); ?></label>
                                <select class="field-input" name="cookie_banner_theme">
                                    <option value="glassmorphism" <?php selected($settings['cookie_banner_theme'] ?? '', 'glassmorphism'); ?>><?php esc_html_e('Glassmorphism (premium)', 'mc-leads-engine'); ?></option>
                                    <option value="light" <?php selected($settings['cookie_banner_theme'] ?? '', 'light'); ?>><?php esc_html_e('Sleek light', 'mc-leads-engine'); ?></option>
                                    <option value="dark" <?php selected($settings['cookie_banner_theme'] ?? '', 'dark'); ?>><?php esc_html_e('Modern dark', 'mc-leads-engine'); ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-title"><?php esc_html_e('Google Analytics', 'mc-leads-engine'); ?></div>
                            <div class="toggle-row" style="margin-bottom:14px;">
                                <div class="tlabel"><?php esc_html_e('Enable Google Analytics tracking', 'mc-leads-engine'); ?></div>
                                <label class="switch">
                                    <input type="checkbox" name="tracking_ga_enable" value="1" <?php checked($settings['tracking_ga_enable'] ?? 0, 1); ?>>
                                    <span class="track"></span>
                                </label>
                            </div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('Measurement ID', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="tracking_ga_id" value="<?php echo esc_attr($settings['tracking_ga_id'] ?? ''); ?>" placeholder="G-XXXXXXXXXX">
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-title"><?php esc_html_e('Meta Pixel', 'mc-leads-engine'); ?></div>
                            <div class="toggle-row" style="margin-bottom:14px;">
                                <div class="tlabel"><?php esc_html_e('Enable Meta Pixel tracking', 'mc-leads-engine'); ?></div>
                                <label class="switch">
                                    <input type="checkbox" name="tracking_pixel_enable" value="1" <?php checked($settings['tracking_pixel_enable'] ?? 0, 1); ?>>
                                    <span class="track"></span>
                                </label>
                            </div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('Pixel ID', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="tracking_pixel_id" value="<?php echo esc_attr($settings['tracking_pixel_id'] ?? ''); ?>" placeholder="e.g. 1234567890">
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-title"><?php esc_html_e('WhatsApp click tracking', 'mc-leads-engine'); ?></div>
                            <div class="card-desc"><?php esc_html_e('Tracks clicks on wa.me / api.whatsapp.com links as conversion events, when tracking consent is granted.', 'mc-leads-engine'); ?></div>
                            <div class="toggle-row">
                                <div class="tlabel"><?php esc_html_e('Enable WhatsApp click tracking', 'mc-leads-engine'); ?></div>
                                <label class="switch">
                                    <input type="checkbox" name="tracking_whatsapp_click" value="1" <?php checked($settings['tracking_whatsapp_click'] ?? 0, 1); ?>>
                                    <span class="track"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- PLACEHOLDERS PANE -->
                    <div class="settings-section-pane" data-pane="placeholders">
                        <div class="card">
                            <div class="card-title"><?php esc_html_e('Dynamic placeholders cheat sheet', 'mc-leads-engine'); ?></div>
                            <div class="card-desc"><?php esc_html_e('Use these tags in email subjects, bodies, or WhatsApp messages — they\'re replaced with real submission data.', 'mc-leads-engine'); ?></div>
                            <table class="ph-table">
                                <thead>
                                    <tr>
                                        <th style="width:26%;"><?php esc_html_e('Tag', 'mc-leads-engine'); ?></th>
                                        <th><?php esc_html_e('Replaced with', 'mc-leads-engine'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><code class="ph-tag">[lead_id]</code></td>
                                        <td><?php esc_html_e('Unique database record ID of the lead.', 'mc-leads-engine'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code class="ph-tag">[session_id]</code></td>
                                        <td><?php esc_html_e('The visitor\'s active tracking session token.', 'mc-leads-engine'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code class="ph-tag">[total_price]</code></td>
                                        <td><?php esc_html_e('Calculated project pricing total, e.g. 25000.00.', 'mc-leads-engine'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code class="ph-tag">[lead_score]</code></td>
                                        <td><?php esc_html_e('Calculated lead score from your pricing rules, e.g. 75.', 'mc-leads-engine'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code class="ph-tag">[survey_title]</code></td>
                                        <td><?php esc_html_e('Title of the submitted survey.', 'mc-leads-engine'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code class="ph-tag">[created_at]</code></td>
                                        <td><?php esc_html_e('Submission timestamp, YYYY-MM-DD HH:MM:SS.', 'mc-leads-engine'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code class="ph-tag">[all_answers]</code></td>
                                        <td><?php esc_html_e('Every filled field, rendered as styled HTML in emails or key/value rows in WhatsApp.', 'mc-leads-engine'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code class="ph-tag">[slugified-question-text]</code></td>
                                        <td><?php esc_html_e('Answer by slugified question, e.g. "What is your business name?" &rarr;', 'mc-leads-engine'); ?> <code class="ph-tag">[what-is-your-business-name]</code>.</td>
                                    </tr>
                                    <tr>
                                        <td><code class="ph-tag">[q_QUESTION_ID]</code></td>
                                        <td><?php esc_html_e('Answer by specific question ID — stable even if the question title changes, e.g.', 'mc-leads-engine'); ?> <code class="ph-tag">[q_12]</code>.</td>
                                    </tr>
                                    <tr>
                                        <td><code class="ph-tag">[cf7-field-name]</code></td>
                                        <td><?php esc_html_e('Contact Form 7 field values by name, e.g. [your-name], [your-email].', 'mc-leads-engine'); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- PRICING RULES PANE -->
                    <div class="settings-section-pane" data-pane="pricing" id="panel-pricing">
                        <div class="card">
                            <div class="card-title"><?php esc_html_e('How pricing rules work', 'mc-leads-engine'); ?></div>
                            <div class="card-desc"><?php esc_html_e('Rules calculate dynamic pricing and lead score from survey answers. The engine matches keywords against question text or selected option values.', 'mc-leads-engine'); ?></div>
                            <div style="background:var(--paper); border:1px solid var(--line-soft); border-radius:9px; padding:13px 15px; font-size:12px; color:var(--text); line-height:1.7;">
                                <b><?php esc_html_e('Sample:', 'mc-leads-engine'); ?></b> <?php esc_html_e('"SEO Addon" · Option Match · keyword', 'mc-leads-engine'); ?> <code class="ph-tag">seo</code> · <?php esc_html_e('+KES 50,000 — if the customer picks an option containing "seo", KES 50,000 is added to their estimate.', 'mc-leads-engine'); ?>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-title"><?php esc_html_e('Base price &amp; rules', 'mc-leads-engine'); ?></div>
                            <div class="field-row" style="align-items:end; margin-bottom:16px;">
                                <div class="field" style="margin-bottom:0;">
                                    <label class="field-label"><?php esc_html_e('Base price (KES)', 'mc-leads-engine'); ?></label>
                                    <input type="number" id="mc-base-price" class="field-input" value="<?php echo esc_attr((float) ($settings['default_base_price'] ?? 0)); ?>" min="0" step="1" placeholder="0">
                                    <div class="field-hint"><?php esc_html_e('Applied to every lead before rules run.', 'mc-leads-engine'); ?></div>
                                </div>
                                <button type="button" id="mc-add-rule-btn" class="btn primary" style="justify-self:end;">＋ <?php esc_html_e('Add rule', 'mc-leads-engine'); ?></button>
                            </div>

                            <!-- Inline add/edit form (hidden by default) -->
                            <div id="mc-rule-form" class="pricing-rule-form" style="display:none; background: var(--paper); border: 1px solid var(--line); border-radius: var(--radius); padding: 15px; margin-bottom: 16px;">
                                <div class="field-row" style="grid-template-columns: 1fr 1fr; margin-bottom: 12px;">
                                    <div class="field" style="margin-bottom:0;">
                                        <label class="field-label"><?php esc_html_e('Rule Name', 'mc-leads-engine'); ?></label>
                                        <input type="text" id="prf-name" class="field-input" placeholder="e.g. SEO Package">
                                    </div>
                                    <div class="field" style="margin-bottom:0;">
                                        <label class="field-label"><?php esc_html_e('Type', 'mc-leads-engine'); ?></label>
                                        <select id="prf-type" class="field-input">
                                            <option value="fixed"><?php esc_html_e('Fixed — always adds amount', 'mc-leads-engine'); ?></option>
                                            <option value="per_unit"><?php esc_html_e('Per Unit — amount × answer number', 'mc-leads-engine'); ?></option>
                                            <option value="option"><?php esc_html_e('Option Match — adds amount if option selected', 'mc-leads-engine'); ?></option>
                                        </select>
                                    </div>
                                </div>
                                <div class="field" style="margin-bottom: 12px;">
                                    <label class="field-label"><?php esc_html_e('Match Keyword', 'mc-leads-engine'); ?></label>
                                    <input type="text" id="prf-match" class="field-input" placeholder="e.g. seo, pages, booking">
                                    <span class="field-hint"><?php esc_html_e('Matched against question text and selected answers', 'mc-leads-engine'); ?></span>
                                </div>
                                <div class="field-row" style="grid-template-columns: 1fr 1fr; margin-bottom: 16px;">
                                    <div class="field" style="margin-bottom:0;">
                                        <label class="field-label"><?php esc_html_e('Amount (KES)', 'mc-leads-engine'); ?></label>
                                        <input type="number" id="prf-amount" class="field-input" placeholder="0" min="0" step="1">
                                    </div>
                                    <div class="field" style="margin-bottom:0;">
                                        <label class="field-label"><?php esc_html_e('Score Impact', 'mc-leads-engine'); ?></label>
                                        <input type="number" id="prf-score" class="field-input" placeholder="0" step="1">
                                        <span class="field-hint"><?php esc_html_e('Optional — adds to the lead score', 'mc-leads-engine'); ?></span>
                                    </div>
                                </div>
                                <div class="pricing-form-actions" style="display:flex; gap:8px; justify-content:flex-end;">
                                    <button type="button" id="mc-rule-cancel-btn" class="btn"><?php esc_html_e('Cancel', 'mc-leads-engine'); ?></button>
                                    <button type="button" id="mc-rule-save-btn" class="btn primary"><?php esc_html_e('Save Rule', 'mc-leads-engine'); ?></button>
                                </div>
                            </div>

                            <!-- Rule list -->
                            <div id="mc-rule-list" class="rule-grid">
                                <?php if (empty($pricing_rules)) : ?>
                                    <div class="rule-empty" id="mc-rule-empty">
                                        <span class="ic">🏷</span>
                                        <span><?php esc_html_e('No pricing rules yet — add one to start shaping estimates automatically.', 'mc-leads-engine'); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Pricing simulator -->
                        <div class="card pricing-simulator">
                            <div class="card-title"><?php esc_html_e('Pricing simulator', 'mc-leads-engine'); ?> <span style="font-size:11px;color:var(--muted-2);font-weight:600;margin-left:8px;"><?php esc_html_e('Test your rules', 'mc-leads-engine'); ?></span></div>
                            <div style="display:flex; gap:8px;">
                                <select id="mc-sim-survey" class="field-input" style="max-width:260px;">
                                    <option value="0"><?php esc_html_e('Select a survey…', 'mc-leads-engine'); ?></option>
                                    <?php foreach ($surveys as $survey) : ?>
                                        <option value="<?php echo esc_attr($survey['id']); ?>"><?php echo esc_html($survey['title']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" id="mc-sim-run" class="btn">▶ <?php esc_html_e('Simulate', 'mc-leads-engine'); ?></button>
                            </div>
                            <div id="mc-sim-result" class="pricing-sim-result" style="display:none; margin-top:14px; padding:12px; background:var(--paper); border:1px solid var(--line); border-radius:8px;"></div>
                        </div>

                        <!-- Hidden data bridge: initial rules from PHP → JS -->
                        <textarea id="mc-pricing-rules-data" style="display:none"><?php echo esc_textarea(wp_json_encode($pricing_rules, JSON_UNESCAPED_UNICODE)); ?></textarea>
                        <input type="hidden" id="mc-pricing-nonce" value="<?php echo esc_attr(wp_create_nonce('mc_leads_engine_nonce')); ?>">
                    </div>
                    
                    <!-- DATA MAINTENANCE PANE -->
                    <div class="settings-section-pane" data-pane="purging">
                        <div class="danger-zone">
                            <div class="card-title"><?php esc_html_e('Data maintenance & purging', 'mc-leads-engine'); ?></div>
                            <div class="card-desc" style="color:#8a3d3a;"><?php esc_html_e('Permanently delete lead submissions, bookings, answers, and tracking data. This cannot be undone.', 'mc-leads-engine'); ?></div>
                            <div class="field">
                                <label class="field-label"><?php esc_html_e('Time range to delete', 'mc-leads-engine'); ?></label>
                                <select class="field-input" name="purge_range" style="max-width:340px;">
                                    <option value="past_month"><?php esc_html_e('Older than past month (30+ days ago)', 'mc-leads-engine'); ?></option>
                                    <option value="last_financial_year"><?php esc_html_e('Previous financial year (Apr 1 – Mar 31)', 'mc-leads-engine'); ?></option>
                                    <option value="all_time"><?php esc_html_e('All time (wipe everything)', 'mc-leads-engine'); ?></option>
                                </select>
                            </div>
                            <button name="mc_leads_engine_purge_submit" value="1" class="btn danger" type="submit" onclick="return confirm('<?php echo esc_js(__('Are you absolutely sure you want to delete this data? This action is permanent and cannot be undone.', 'mc-leads-engine')); ?>');">
                                🗑 <?php esc_html_e('Purge selected data', 'mc-leads-engine'); ?>
                            </button>
                        </div>
                    </div>
                    
                </div>
            </div>
        </form>
    </div>
<?php
}
