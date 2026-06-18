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
        <h1 class="wp-heading-inline"><?php esc_html_e('Settings', 'mc-leads-engine'); ?></h1>
        <hr class="wp-header-end">
        
        <?php if (!empty($_GET['updated'])) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'mc-leads-engine'); ?></p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['purged'])) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Data purged successfully.', 'mc-leads-engine'); ?></p></div>
        <?php endif; ?>
        
        <form method="post" class="mc-settings-form">
            <?php wp_nonce_field('mc_leads_engine_admin_action', 'mc_leads_engine_nonce'); ?>
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
                    <button type="button" class="settings-tab-btn" data-tab="booking">
                        <span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e('Booking Settings', 'mc-leads-engine'); ?>
                    </button>
                    <button type="button" class="settings-tab-btn" data-tab="placeholders">
                        <span class="dashicons dashicons-editor-code"></span> <?php esc_html_e('Placeholder Guide', 'mc-leads-engine'); ?>
                    </button>
                    <button type="button" class="settings-tab-btn" data-tab="pricing">
                        <span class="dashicons dashicons-money-alt"></span> <?php esc_html_e('Pricing Rules', 'mc-leads-engine'); ?>
                    </button>
                    <button type="button" class="settings-tab-btn" data-tab="purging">
                        <span class="dashicons dashicons-trash"></span> <?php esc_html_e('Data Maintenance', 'mc-leads-engine'); ?>
                    </button>
                </div>
                
                <!-- Right Pane -->
                <div class="settings-content">
                    <!-- General Tab -->
                    <div class="settings-section-pane active" data-pane="general">
                        <div class="card">
                            <div class="card-title"><?php esc_html_e('General Settings', 'mc-leads-engine'); ?></div>
                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('Admin Notification Email', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="email" name="notification_email" value="<?php echo esc_attr($settings['notification_email']); ?>">
                                <span class="field-desc"><?php esc_html_e('Where admin submission notifications are sent.', 'mc-leads-engine'); ?></span>
                            </div>
                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('Thank You Redirect URL', 'mc-leads-engine'); ?></label>
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
                                <label class="field-label"><?php esc_html_e('Email Subject', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="user_email_subject" value="<?php echo esc_attr($settings['user_email_subject']); ?>">
                            </div>
                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('HTML Body Template', 'mc-leads-engine'); ?></label>
                                <textarea class="field-input code-font" rows="14" name="user_email_body"><?php echo esc_textarea($settings['user_email_body']); ?></textarea>
                                <span class="field-desc"><?php esc_html_e('Enter raw HTML with inline CSS. Dynamic bracket variables like [your-name] will be replaced with form values.', 'mc-leads-engine'); ?></span>
                            </div>
                        </div>
                        <div class="card" style="margin-top: 20px;">
                            <div class="card-title"><?php esc_html_e('Booking User Email Notification Template', 'mc-leads-engine'); ?></div>
                            <p class="field-desc-top"><?php esc_html_e('Configure the HTML email sent to clients who schedule a booking. Use inline CSS to style the markup.', 'mc-leads-engine'); ?></p>
                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('Email Subject', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="booking_user_email_subject" value="<?php echo esc_attr($settings['booking_user_email_subject'] ?? ''); ?>">
                            </div>
                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('HTML Body Template', 'mc-leads-engine'); ?></label>
                                <textarea class="field-input code-font" rows="14" name="booking_user_email_body"><?php echo esc_textarea($settings['booking_user_email_body'] ?? ''); ?></textarea>
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
                                <label class="field-label"><?php esc_html_e('Email Subject', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="admin_email_subject" value="<?php echo esc_attr($settings['admin_email_subject']); ?>">
                            </div>
                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('HTML Body Template', 'mc-leads-engine'); ?></label>
                                <textarea class="field-input code-font" rows="14" name="admin_email_body"><?php echo esc_textarea($settings['admin_email_body']); ?></textarea>
                                <span class="field-desc"><?php esc_html_e('Sent to your Notification Email address. Use [all_answers] to print a structured log of all survey inputs.', 'mc-leads-engine'); ?></span>
                            </div>
                        </div>
                        <div class="card" style="margin-top: 20px;">
                            <div class="card-title"><?php esc_html_e('Booking Admin Email Notification Template', 'mc-leads-engine'); ?></div>
                            <p class="field-desc-top"><?php esc_html_e('Configure the HTML notification email sent to the site admin when a booking is scheduled.', 'mc-leads-engine'); ?></p>
                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('Email Subject', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="booking_admin_email_subject" value="<?php echo esc_attr($settings['booking_admin_email_subject'] ?? ''); ?>">
                            </div>
                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('HTML Body Template', 'mc-leads-engine'); ?></label>
                                <textarea class="field-input code-font" rows="14" name="booking_admin_email_body"><?php echo esc_textarea($settings['booking_admin_email_body'] ?? ''); ?></textarea>
                                <span class="field-desc"><?php esc_html_e('Sent to your Notification Email address.', 'mc-leads-engine'); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- WhatsApp Tab -->
                    <div class="settings-section-pane" data-pane="whatsapp">
                        <div class="card">
                            <div class="card-title"><?php esc_html_e('WhatsApp Notification Settings', 'mc-leads-engine'); ?></div>
                            
                            <h3 style="margin-top: 15px; border-bottom: 1px solid var(--mc-border); padding-bottom: 5px; color: var(--mc-text);"><?php esc_html_e('Gateway API Settings', 'mc-leads-engine'); ?></h3>
                            
                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('WhatsApp Gateway API Provider', 'mc-leads-engine'); ?></label>
                                <select class="field-input" name="whatsapp_gateway" id="mc-whatsapp-gateway">
                                    <option value="ultramsg" <?php selected($settings['whatsapp_gateway'], 'ultramsg'); ?>>UltraMsg (Recommended)</option>
                                    <option value="twilio" <?php selected($settings['whatsapp_gateway'], 'twilio'); ?>>Twilio SMS/WhatsApp</option>
                                    <option value="cloud_api" <?php selected($settings['whatsapp_gateway'], 'cloud_api'); ?>>WhatsApp Business Cloud API (Meta)</option>
                                    <option value="custom" <?php selected($settings['whatsapp_gateway'], 'custom'); ?>>Custom Webhook Gateway</option>
                                </select>
                            </div>
                            <div class="settings-field">
                                <label class="field-label" id="mc-whatsapp-api-key-label"><?php esc_html_e('WhatsApp API Key / Access Token', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="whatsapp_api_key" value="<?php echo esc_attr($settings['whatsapp_api_key']); ?>">
                            </div>
                            <div class="settings-field" id="mc-whatsapp-instance-id-field">
                                <label class="field-label" id="mc-whatsapp-instance-id-label"><?php esc_html_e('Instance ID / Account SID / Webhook URL', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="whatsapp_instance_id" value="<?php echo esc_attr($settings['whatsapp_instance_id']); ?>">
                            </div>
                            <div class="settings-field" id="mc-whatsapp-sender-field">
                                <label class="field-label"><?php esc_html_e('Sender Number / ID / Phone Number ID', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="whatsapp_sender" value="<?php echo esc_attr($settings['whatsapp_sender']); ?>" placeholder="e.g. +14155238886">
                            </div>

                            <h3 style="margin-top: 25px; border-bottom: 1px solid var(--mc-border); padding-bottom: 5px; color: var(--mc-text);"><?php esc_html_e('Admin Alert Settings', 'mc-leads-engine'); ?></h3>
                            
                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('Admin Recipient WhatsApp Phone Number', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="admin_whatsapp_phone" value="<?php echo esc_attr($settings['admin_whatsapp_phone']); ?>" placeholder="e.g. +254712345678">
                                <span class="field-desc"><?php esc_html_e('The administrator phone number in international format.', 'mc-leads-engine'); ?></span>
                            </div>
                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('Leads Admin Alert Message Template', 'mc-leads-engine'); ?></label>
                                <textarea class="field-input" rows="6" name="admin_whatsapp_body"><?php echo esc_textarea($settings['admin_whatsapp_body']); ?></textarea>
                                <span class="field-desc"><?php esc_html_e('Plain text alert sent to the admin phone number for leads.', 'mc-leads-engine'); ?></span>
                            </div>
                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('Booking Admin Alert Message Template', 'mc-leads-engine'); ?></label>
                                <textarea class="field-input" rows="6" name="booking_admin_whatsapp_body"><?php echo esc_textarea($settings['booking_admin_whatsapp_body'] ?? ''); ?></textarea>
                                <span class="field-desc"><?php esc_html_e('Plain text alert sent to the admin phone number for bookings.', 'mc-leads-engine'); ?></span>
                            </div>

                            <h3 style="margin-top: 25px; border-bottom: 1px solid var(--mc-border); padding-bottom: 5px; color: var(--mc-text);"><?php esc_html_e('User Alert Settings', 'mc-leads-engine'); ?></h3>

                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('Leads User Alert Message Template', 'mc-leads-engine'); ?></label>
                                <textarea class="field-input" rows="6" name="user_whatsapp_body"><?php echo esc_textarea($settings['user_whatsapp_body']); ?></textarea>
                                <span class="field-desc"><?php esc_html_e('Plain text notification sent directly to the client\'s phone number for leads.', 'mc-leads-engine'); ?></span>
                            </div>
                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('Booking User Alert Message Template', 'mc-leads-engine'); ?></label>
                                <textarea class="field-input" rows="6" name="booking_user_whatsapp_body"><?php echo esc_textarea($settings['booking_user_whatsapp_body'] ?? ''); ?></textarea>
                                <span class="field-desc"><?php esc_html_e('Plain text notification sent directly to the client\'s phone number for bookings.', 'mc-leads-engine'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Booking Settings Tab -->
                    <div class="settings-section-pane" data-pane="booking">
                        <div class="card">
                            <div class="card-title"><?php esc_html_e('Google Calendar Integration', 'mc-leads-engine'); ?></div>
                            <p class="field-desc-top"><?php esc_html_e('Connect the booking system to your Google Calendar to sync availability in real time and prevent double booking.', 'mc-leads-engine'); ?></p>
                            
                            <?php if (!empty($_GET['gcal_auth_success'])) : ?>
                                <div class="notice notice-success inline" style="margin-bottom:15px;"><p><?php esc_html_e('Successfully authorized with Google Calendar!', 'mc-leads-engine'); ?></p></div>
                            <?php endif; ?>

                            <div class="settings-row">
                                <div class="settings-field">
                                    <label class="field-label"><?php esc_html_e('Google Client ID', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="text" name="gcal_client_id" value="<?php echo esc_attr($settings['gcal_client_id'] ?? ''); ?>">
                                </div>
                                <div class="settings-field">
                                    <label class="field-label"><?php esc_html_e('Google Client Secret', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="text" name="gcal_client_secret" value="<?php echo esc_attr($settings['gcal_client_secret'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('Google Calendar ID', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="gcal_calendar_id" value="<?php echo esc_attr($settings['gcal_calendar_id'] ?? 'primary'); ?>">
                                <span class="field-desc"><?php esc_html_e('Primary is default. Or use specific calendar resource ID.', 'mc-leads-engine'); ?></span>
                            </div>

                            <div class="settings-field" style="background:#f8fafc; border:1px solid #e2e8f0; padding:15px; border-radius:8px;">
                                <label class="field-label" style="margin-bottom:5px;"><?php esc_html_e('Google Calendar Authorization Status', 'mc-leads-engine'); ?></label>
                                <p style="margin:0 0 10px 0; font-weight:600; font-size:13px; color: <?php echo !empty($settings['gcal_access_token']) ? '#16a34a' : '#dc2626'; ?>;">
                                    <?php 
                                    if (!empty($settings['gcal_access_token'])) {
                                        $expires_in = (int)($settings['gcal_token_expires'] ?? 0) - time();
                                        if ($expires_in > 0) {
                                            printf(esc_html__('Connected (Access Token Active - Expires in %d min)', 'mc-leads-engine'), round($expires_in / 60));
                                        } else {
                                            esc_html_e('Connected (Access Token Expired - Auto refresh active)', 'mc-leads-engine');
                                        }
                                    } else {
                                        esc_html_e('Not Authorized / Connected', 'mc-leads-engine');
                                    }
                                    ?>
                                </p>
                                <?php
                                if (!empty($settings['gcal_client_id']) && !empty($settings['gcal_client_secret'])) {
                                    $auth_url = mc_leads_engine_booking()->get_gcal_client_auth_url();
                                    printf('<a class="button button-primary" href="%s">%s</a>', esc_url($auth_url), esc_html__('Authorize Calendar Access', 'mc-leads-engine'));
                                } else {
                                    echo '<p class="description" style="color:#ef4444; margin:0;">' . esc_html__('Save Client ID & Secret above first, then click Save Settings to show Authorization button.', 'mc-leads-engine') . '</p>';
                                }
                                ?>
                            </div>
                        </div>

                        <div class="card" style="margin-top:20px;">
                            <div class="card-title"><?php esc_html_e('Google Maps Integration & Predefined Locations', 'mc-leads-engine'); ?></div>
                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('Google Maps API Key', 'mc-leads-engine'); ?></label>
                                <input class="field-input" type="text" name="gmaps_api_key" value="<?php echo esc_attr($settings['gmaps_api_key'] ?? ''); ?>">
                                <span class="field-desc"><?php esc_html_e('Used for address geocoding and autocomplete inside the office custom location details.', 'mc-leads-engine'); ?></span>
                            </div>
                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('Predefined Meeting Locations (Separated by |)', 'mc-leads-engine'); ?></label>
                                <textarea class="field-input" rows="4" name="booking_predefined_locations"><?php echo esc_textarea($settings['booking_predefined_locations'] ?? ''); ?></textarea>
                                <span class="field-desc"><?php esc_html_e('Suggested Cafes or Public meeting spaces, separated by the vertical bar (|). E.g. Java House, Westlands|Nairobi Garage, Kilimani', 'mc-leads-engine'); ?></span>
                            </div>
                        </div>

                        <div class="card" style="margin-top:20px;">
                            <div class="card-title"><?php esc_html_e('Working Hours & Availability Rules', 'mc-leads-engine'); ?></div>
                            <div class="settings-row">
                                <div class="settings-field">
                                    <label class="field-label"><?php esc_html_e('Daily Start Time', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="text" name="booking_hours_start" value="<?php echo esc_attr($settings['booking_hours_start'] ?? '09:00'); ?>" placeholder="e.g. 09:00">
                                </div>
                                <div class="settings-field">
                                    <label class="field-label"><?php esc_html_e('Daily End Time', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="text" name="booking_hours_end" value="<?php echo esc_attr($settings['booking_hours_end'] ?? '17:00'); ?>" placeholder="e.g. 17:00">
                                </div>
                            </div>

                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('Working Days', 'mc-leads-engine'); ?></label>
                                <div style="display:flex; gap:15px; flex-wrap:wrap; margin-top:5px;">
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
                                    ?>
                                        <label style="font-weight:normal;"><input type="checkbox" name="booking_days[]" value="<?php echo esc_attr($num); ?>" <?php checked(in_array((string)$num, $saved_days, true)); ?>> <?php echo esc_html($lbl); ?></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="settings-row">
                                <div class="settings-field">
                                    <label class="field-label"><?php esc_html_e('Slot Duration (minutes)', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="number" name="booking_duration" value="<?php echo esc_attr($settings['booking_duration'] ?? 30); ?>">
                                </div>
                                <div class="settings-field">
                                    <label class="field-label"><?php esc_html_e('Buffer Between Slots (minutes)', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="number" name="booking_buffer" value="<?php echo esc_attr($settings['booking_buffer'] ?? 15); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="card" style="margin-top:20px;">
                            <div class="card-title"><?php esc_html_e('Default Contact Form 7 Configuration', 'mc-leads-engine'); ?></div>
                            <p class="field-desc-top"><?php esc_html_e('Select the default Contact Form 7 form used to capture client contact details in Step 4 of the [mc_booking] shortcode.', 'mc-leads-engine'); ?></p>
                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('Default Booking CF7 Form', 'mc-leads-engine'); ?></label>
                                <?php 
                                $cf7_forms = array();
                                if (post_type_exists('wpcf7_contact_form')) {
                                    $cf7_posts = get_posts(array(
                                        'post_type' => 'wpcf7_contact_form',
                                        'numberposts' => -1,
                                    ));
                                    if (is_array($cf7_posts)) {
                                        foreach ($cf7_posts as $post) {
                                            $cf7_forms[$post->ID] = $post->post_title;
                                        }
                                    }
                                }
                                
                                if (!empty($cf7_forms)) : 
                                ?>
                                    <select class="field-input" name="booking_default_cf7">
                                        <option value="0"><?php esc_html_e('-- Select a form --', 'mc-leads-engine'); ?></option>
                                        <?php foreach ($cf7_forms as $id => $title) : ?>
                                            <option value="<?php echo esc_attr($id); ?>" <?php selected(($settings['booking_default_cf7'] ?? 0), $id); ?>><?php echo esc_html($title); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else : ?>
                                    <input class="field-input" type="number" name="booking_default_cf7" value="<?php echo esc_attr($settings['booking_default_cf7'] ?? ''); ?>" placeholder="<?php esc_attr_e('Enter Contact Form 7 Form ID', 'mc-leads-engine'); ?>">
                                    <span class="field-desc" style="color:#ef4444;"><?php esc_html_e('Contact Form 7 is either not active or you have no forms. Please input form ID manually.', 'mc-leads-engine'); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card" style="margin-top:20px;">
                            <div class="card-title"><?php esc_html_e('Lead Scoring Rules for Booking Selection', 'mc-leads-engine'); ?></div>
                            <p class="field-desc-top"><?php esc_html_e('Configure score points to add to the lead quality when they select a specific booking type.', 'mc-leads-engine'); ?></p>
                            <div class="settings-row">
                                <div class="settings-field">
                                    <label class="field-label"><?php esc_html_e('Online Video Call Score', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="number" name="booking_score_online" value="<?php echo esc_attr($settings['booking_score_online'] ?? 10); ?>">
                                </div>
                                <div class="settings-field">
                                    <label class="field-label"><?php esc_html_e('Coffee Meeting Score', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="number" name="booking_score_coffee" value="<?php echo esc_attr($settings['booking_score_coffee'] ?? 20); ?>">
                                </div>
                            </div>
                            <div class="settings-row">
                                <div class="settings-field">
                                    <label class="field-label"><?php esc_html_e('Office Visit Score', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="number" name="booking_score_office" value="<?php echo esc_attr($settings['booking_score_office'] ?? 30); ?>">
                                </div>
                                <div class="settings-field">
                                    <label class="field-label"><?php esc_html_e('Predefined Host Location Score', 'mc-leads-engine'); ?></label>
                                    <input class="field-input" type="number" name="booking_score_host" value="<?php echo esc_attr($settings['booking_score_host'] ?? 20); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Placeholders Tab -->
                    <div class="settings-section-pane" data-pane="placeholders">
                        <div class="card">
                            <div class="card-title"><?php esc_html_e('Dynamic Placeholders Cheat Sheet', 'mc-leads-engine'); ?></div>
                            <p><?php esc_html_e('You can use the following bracket codes in your notification subjects and message bodies. They will automatically be replaced with matching submission data.', 'mc-leads-engine'); ?></p>
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
                                        <td><?php esc_html_e('The unique database record ID of the lead submission.', 'mc-leads-engine'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code class="mc-code-badge">[session_id]</code></td>
                                        <td><?php esc_html_e('The visitor\'s active tracking session token.', 'mc-leads-engine'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code class="mc-code-badge">[total_price]</code></td>
                                        <td><?php esc_html_e('Calculated project pricing total (e.g., 25000.00).', 'mc-leads-engine'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code class="mc-code-badge">[lead_score]</code></td>
                                        <td><?php esc_html_e('Calculated project quality score based on rules (e.g. 75).', 'mc-leads-engine'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code class="mc-code-badge">[survey_title]</code></td>
                                        <td><?php esc_html_e('The name/title of the survey form submitted.', 'mc-leads-engine'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code class="mc-code-badge">[created_at]</code></td>
                                        <td><?php esc_html_e('Submission timestamp formatted as YYYY-MM-DD HH:MM:SS.', 'mc-leads-engine'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code class="mc-code-badge">[all_answers]</code></td>
                                        <td><?php esc_html_e('A comprehensive listing of all filled fields (renders as styled HTML in emails, and neat key-value rows in WhatsApp).', 'mc-leads-engine'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code class="mc-code-badge">[slugified-question-text]</code></td>
                                        <td>
                                            <?php esc_html_e('Slugified question title for standard surveys. Converts characters to lowercase and replaces spaces with dashes. Examples:', 'mc-leads-engine'); ?><br>
                                            - Question: <em>What is your business name?</em> &rarr; <code>[what-is-your-business-name]</code><br>
                                            - Question: <em>What type of organization are you?</em> &rarr; <code>[what-type-of-organization-are-you]</code>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><code class="mc-code-badge">[q_QUESTION_ID]</code></td>
                                        <td><?php esc_html_e('Retrieve a standard answer by the specific question ID. Useful if titles match or change, e.g. [q_12].', 'mc-leads-engine'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code class="mc-code-badge">[cf7-field-name]</code></td>
                                        <td><?php esc_html_e('When integrated with Contact Form 7, retrieve form values directly by their field name slug, e.g. [your-name], [your-email], [your-message].', 'mc-leads-engine'); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Purging / Data Maintenance Tab -->
                    <div class="settings-section-pane" data-pane="purging">
                        <div class="card">
                            <div class="card-title"><?php esc_html_e('Data Maintenance & Purging', 'mc-leads-engine'); ?></div>
                            <p class="field-desc-top"><?php esc_html_e('Purge lead submissions, bookings, answers, and tracking data from the database. Warning: This action is permanent and cannot be undone.', 'mc-leads-engine'); ?></p>
                            
                            <div class="settings-field">
                                <label class="field-label"><?php esc_html_e('Select Time Range to Delete:', 'mc-leads-engine'); ?></label>
                                <select class="field-input" name="purge_range" style="max-width:300px;">
                                    <option value="past_month"><?php esc_html_e('Older than Past Month (30+ days ago)', 'mc-leads-engine'); ?></option>
                                    <option value="last_financial_year"><?php esc_html_e('Previous Financial Year (Apr 1 - Mar 31)', 'mc-leads-engine'); ?></option>
                                    <option value="all_time"><?php esc_html_e('All Time (Start Afresh / Wipe All)', 'mc-leads-engine'); ?></option>
                                </select>
                            </div>
                            
                            <div style="margin-top:20px;">
                                <button name="mc_leads_engine_purge_submit" value="1" class="button button-link-delete" type="submit" style="border:1px solid #ef4444; color:#ef4444; background:none; padding:6px 16px; border-radius:4px; cursor:pointer; font-weight:600;" onclick="return confirm('<?php echo esc_js(__('Are you absolutely sure you want to delete this data? This action is permanent and cannot be undone.', 'mc-leads-engine')); ?>');">
                                    <?php esc_html_e('Purge Selected Data', 'mc-leads-engine'); ?>
                                </button>
                            </div>
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
            
            <div class="settings-actions-footer">
                <button class="button button-primary" type="submit"><?php esc_html_e('Save Notification Settings', 'mc-leads-engine'); ?></button>
            </div>
        </form>
    </div>
    <?php
}
