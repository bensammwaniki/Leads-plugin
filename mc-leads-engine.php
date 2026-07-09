<?php
/*
Plugin Name: MC Leads Engine
Plugin URI: https://memoriescreative.com/
Description: Modular survey, pricing, lead capture, CF7 integration, analytics, and shortcode engine.
Version: 1.3.0
Author: Bensam Mwaniki
Author URI: https://memoriescreative.com/
Requires at least: 5.8
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) {
    exit;
}

define('MC_LEADS_ENGINE_VERSION', '1.4.0');
define('MC_LEADS_ENGINE_FILE', __FILE__);
define('MC_LEADS_ENGINE_PATH', plugin_dir_path(__FILE__));
define('MC_LEADS_ENGINE_URL', plugin_dir_url(__FILE__));

require_once MC_LEADS_ENGINE_PATH . 'database/install.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/helpers.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-session.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-survey.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-section.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-question.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-pricing-engine.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-xlsx-writer.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-leads.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-activity.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-digest.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-cf7-integration.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-booking.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-consent-tracker.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-shortcodes.php';

function mc_leads_engine_table($suffix) {
    global $wpdb;

    return $wpdb->prefix . 'mcle_' . $suffix;
}

function mc_leads_engine_get_settings() {
    $defaults = array(
        'notification_email'         => get_option('admin_email'),
        'whatsapp_api_key'           => '',
        'whatsapp_gateway'           => 'ultramsg',
        'whatsapp_instance_id'       => '',
        'whatsapp_sender'            => '',
        'default_base_price'         => 0,
        'default_pricing_rules_json' => '',
        'thank_you_url'              => '',
        'user_email_subject'         => __('Thank you for your submission!', 'mc-leads-engine'),
        'user_email_body'            => '<div>
  <p>Hi [your-name],</p>
  <p>Thanks for your submission for <strong>[survey_title]</strong>.</p>
  <p>
    Lead ID: #[lead_id]<br>
    Estimated Total: KES [total_price]<br>
    Lead Score: [lead_score]
  </p>
  <p>We will review it and get back to you shortly.</p>
</div>',
        'admin_email_subject'        => __('New Lead Submission #[lead_id] - [survey_title]', 'mc-leads-engine'),
        'admin_email_body'           => '<div>
  <p>A new lead was submitted for <strong>[survey_title]</strong>.</p>
  <p>
    Lead ID: #[lead_id]<br>
    Session ID: [session_id]<br>
    Total Price: KES [total_price]<br>
    Lead Score: [lead_score]<br>
    Date/Time: [created_at]
  </p>
</div>',
        'admin_whatsapp_phone'       => '',
        'admin_whatsapp_body'        => __("New Lead Submission #[lead_id] for \"[survey_title]\"\nPrice: KES [total_price]\nScore: [lead_score]\nClient: [your-name] ([email-address])", 'mc-leads-engine'),
        'user_whatsapp_body'         => __("Hello [your-name],\nThank you for completing the \"[survey_title]\" estimate.\n\nEstimate Details:\n- Estimate: KES [total_price]\n- Lead ID: #[lead_id]\n\nWe will get in touch with you shortly.", 'mc-leads-engine'),
        
        // Booking Notifications templates
        'booking_user_email_subject' => __('Meeting Confirmation: [booking_type] scheduled', 'mc-leads-engine'),
        'booking_user_email_body'    => '<div>
  <p>Hi [full-name],</p>
  <p>Your [booking_type] is confirmed for [booking_date] at [booking_time].</p>
  <p>Location: [booking_location]</p>
</div>',
        'booking_admin_email_subject'=> __('New Booking scheduled: [booking_type] - [full-name]', 'mc-leads-engine'),
        'booking_admin_email_body'   => '<div>
  <p>A new meeting was booked by <strong>[full-name]</strong>.</p>
  <p>
    Client Email: [email]<br>
    Client Phone: [phone]<br>
    Meeting Type: [booking_type]<br>
    Date & Time: [booking_date] @ [booking_time]<br>
    Location: [booking_location]
  </p>
</div>',
        'booking_admin_whatsapp_body'=> __("New Booking Scheduled!\nClient: [full-name]\nMeeting Type: [booking_type]\nDate & Time: [booking_date] @ [booking_time]\nLocation: [booking_location]", 'mc-leads-engine'),
        'booking_user_whatsapp_body' => __("Hello [full-name],\nYour [booking_type] has been scheduled for [booking_date] at [booking_time].\nLocation: [booking_location]\n\nThank you for booking with us!", 'mc-leads-engine'),

        // Booking System settings
        'gcal_client_id'             => '',
        'gcal_client_secret'         => '',
        'gcal_calendar_id'           => 'primary',
        'gcal_access_token'          => '',
        'gcal_refresh_token'         => '',
        'gcal_token_expires'         => 0,
        'gmaps_api_key'              => '',
        'booking_predefined_locations'=> "Java House, Westlands|Nairobi Garage, Kilimani|Prestige Plaza, Ngong Road",
        'booking_hours_start'        => '09:00',
        'booking_hours_end'          => '17:00',
        'booking_days'               => array('1', '2', '3', '4', '5'), // Mon-Fri
        'booking_duration'           => 30,
        'booking_buffer'             => 15,
        'booking_default_cf7'        => 0,
        'booking_score_online'       => 10,
        'booking_score_coffee'       => 20,
        'booking_score_office'       => 30,
        'booking_score_host'         => 20,
        
        // Consent & Tracking defaults
        'cookie_banner_enable'       => 0,
        'cookie_banner_title'        => __('We value your privacy', 'mc-leads-engine'),
        'cookie_banner_message'      => __('We use cookies to enhance your experience, serve personalized ads or content, and analyze our traffic. By clicking "Accept All", you consent to our use of cookies.', 'mc-leads-engine'),
        'cookie_banner_btn_accept'   => __('Accept All', 'mc-leads-engine'),
        'cookie_banner_btn_reject'   => __('Reject All', 'mc-leads-engine'),
        'cookie_banner_btn_settings' => __('Customize', 'mc-leads-engine'),
        'cookie_banner_theme'        => 'glassmorphism',
        'tracking_ga_id'             => '',
        'tracking_ga_enable'         => 0,
        'tracking_pixel_id'          => '',
        'tracking_pixel_enable'      => 0,
        'tracking_whatsapp_click'    => 0,

        // Lead scoring bands
        'score_hot_threshold'        => 80,
        'score_warm_threshold'       => 50,

        // Weekly digest email
        'digest_email_enable'        => 0,
    );

    $settings = get_option('mc_leads_engine_settings', array());

    return wp_parse_args(is_array($settings) ? $settings : array(), $defaults);
}

function mc_leads_engine_get_survey_settings($survey_id) {
    $defaults = array(
        'final_step_title'  => __('Final step', 'mc-leads-engine'),
        'show_final_price'  => 1,
        'show_final_score'  => 1,
        'show_title'        => 1,
        'show_description'  => 1,
        'final_button_text' => __('Submit Survey', 'mc-leads-engine'),
        'final_message'     => __('Review your answers and submit the survey to create a lead.', 'mc-leads-engine'),
    );

    if (!$survey_id) {
        return $defaults;
    }

    $settings = get_option("mc_leads_engine_survey_settings_{$survey_id}", array());
    return wp_parse_args(is_array($settings) ? $settings : array(), $defaults);
}

function mc_leads_engine_update_survey_settings($survey_id, $settings) {
    if (!$survey_id) {
        return false;
    }

    $clean_settings = array(
        'final_step_title'  => sanitize_text_field($settings['final_step_title'] ?? ''),
        'show_final_price'  => isset($settings['show_final_price']) ? (int) $settings['show_final_price'] : 0,
        'show_final_score'  => isset($settings['show_final_score']) ? (int) $settings['show_final_score'] : 0,
        'show_title'        => isset($settings['show_title']) ? (int) $settings['show_title'] : 0,
        'show_description'  => isset($settings['show_description']) ? (int) $settings['show_description'] : 0,
        'final_button_text' => sanitize_text_field($settings['final_button_text'] ?? ''),
        'final_message'     => wp_kses_post($settings['final_message'] ?? ''),
    );

    return update_option("mc_leads_engine_survey_settings_{$survey_id}", $clean_settings);
}

function mc_leads_engine_format_final_message($message, $pricing, $survey_id = 0) {
    $settings = mc_leads_engine_get_settings();
    $currency = $settings['currency_symbol'] ?? 'KES';
    
    $total_price = number_format_i18n((float) ($pricing['total_price'] ?? 0), 2);
    $formatted_price = $currency . ' ' . $total_price;
    $lead_score = (int) ($pricing['lead_score'] ?? 0);
    
    $survey_title = '';
    if ($survey_id) {
        $survey = mc_leads_engine_survey_repository()->get_survey($survey_id);
        if ($survey) {
            $survey_title = $survey['title'] ?? '';
        }
    }
    
    $message = str_replace('[total_price]', $total_price, $message);
    $message = str_replace('[estimate]', $formatted_price, $message);
    $message = str_replace('[lead_score]', $lead_score, $message);
    $message = str_replace('[survey_title]', $survey_title, $message);
    
    return wp_kses_post($message);
}

function mc_leads_engine_sanitize_recursive($value) {
    if (is_array($value)) {
        $clean = array();

        foreach ($value as $key => $item) {
            $clean[sanitize_key($key)] = mc_leads_engine_sanitize_recursive($item);
        }

        return $clean;
    }

    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return $value + 0;
    }

    return sanitize_text_field(wp_unslash((string) $value));
}

function mc_leads_engine_get_template_path($template_name) {
    $template_name = ltrim($template_name, '/');
    $theme_override = locate_template(array(
        'mc-leads-engine/' . $template_name,
        $template_name,
    ));

    if ($theme_override) {
        return $theme_override;
    }

    return MC_LEADS_ENGINE_PATH . 'templates/' . $template_name;
}

function mc_leads_engine_get_survey_shortcode($survey_id, $cf7_id = 0) {
    $survey_id = absint($survey_id);
    $cf7_id = absint($cf7_id);

    if (!$survey_id) {
        return '';
    }

    if ($cf7_id) {
        return sprintf('[mc_survey id="%d" cf7="%d"]', $survey_id, $cf7_id);
    }

    return sprintf('[mc_survey id="%d"]', $survey_id);
}

function mc_leads_engine_get_redirect_target($fallback = '') {
    $redirect_to = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';
    if ($redirect_to) {
        $validated = wp_validate_redirect($redirect_to, '');
        if ($validated) {
            return $validated;
        }
    }

    $fallback = esc_url_raw($fallback);

    return $fallback;
}

function mc_leads_engine_render_template($template_name, $args = array()) {
    $template_path = mc_leads_engine_get_template_path($template_name);

    if (!file_exists($template_path)) {
        return '';
    }

    ob_start();
    extract($args, EXTR_SKIP);
    include $template_path;

    return ob_get_clean();
}

function mc_leads_engine_session() {
    static $session = null;

    if (!$session) {
        $session = new MC_Leads_Engine_Session();
    }

    return $session;
}

function mc_leads_engine_survey_repository() {
    static $survey = null;

    if (!$survey) {
        $survey = new MC_Leads_Engine_Survey();
    }

    return $survey;
}

function mc_leads_engine_pricing_engine() {
    static $pricing_engine = null;

    if (!$pricing_engine) {
        $pricing_engine = new MC_Leads_Engine_Pricing_Engine();
    }

    return $pricing_engine;
}

function mc_leads_engine_leads_repository() {
    static $leads = null;

    if (!$leads) {
        $leads = new MC_Leads_Engine_Leads();
    }

    return $leads;
}

function mc_leads_engine_cf7_integration() {
    static $cf7 = null;

    if (!$cf7) {
        $cf7 = new MC_Leads_Engine_CF7_Integration();
    }

    return $cf7;
}

function mc_leads_engine_booking() {
    static $booking = null;

    if (!$booking) {
        $booking = new MC_Leads_Engine_Booking();
    }

    return $booking;
}

function mc_leads_engine_consent_tracker() {
    static $tracker = null;

    if (!$tracker) {
        $tracker = new MC_Leads_Engine_Consent_Tracker();
    }

    return $tracker;
}

function mc_leads_engine_boot() {
    mc_leads_engine_session()->maybe_start_session();

    // Auto-upgrade DB schema when plugin version changes
    $installed_version = get_option('mc_leads_engine_db_version', '0.0.0');
    if (version_compare($installed_version, MC_LEADS_ENGINE_VERSION, '<')) {
        mc_leads_engine_install();
        update_option('mc_leads_engine_db_version', MC_LEADS_ENGINE_VERSION);
    }

    if (is_admin()) {
        require_once MC_LEADS_ENGINE_PATH . 'admin/admin-menu.php';
        require_once MC_LEADS_ENGINE_PATH . 'admin/admin-app.php';
        require_once MC_LEADS_ENGINE_PATH . 'admin/admin-leads.php';
        require_once MC_LEADS_ENGINE_PATH . 'admin/admin-bookings.php';
        require_once MC_LEADS_ENGINE_PATH . 'admin/admin-analytics.php';
        require_once MC_LEADS_ENGINE_PATH . 'admin/admin-settings.php';
    }

    mc_leads_engine_cf7_integration();
    mc_leads_engine_booking();
    mc_leads_engine_consent_tracker();
    new MC_Leads_Engine_Shortcodes();
}
add_action('plugins_loaded', 'mc_leads_engine_boot');

function mc_leads_engine_handle_session_actions() {
    if (is_admin()) {
        return;
    }

    $session = mc_leads_engine_session();

    if (isset($_GET['mc_leads_restart']) && (int) $_GET['mc_leads_restart'] === 1) {
        $session->clear_session();
        wp_safe_redirect(remove_query_arg('mc_leads_restart'));
        exit;
    }

    if (isset($_GET['mc_new_booking']) && (int) $_GET['mc_new_booking'] === 1) {
        $session->clear_session();
        wp_safe_redirect(remove_query_arg('mc_new_booking'));
        exit;
    }
}
add_action('template_redirect', 'mc_leads_engine_handle_session_actions');

register_activation_hook(__FILE__, 'mc_leads_engine_install');

// ─── Weekly Digest Cron ────────────────────────────────────────────────────
add_action('mc_leads_engine_weekly_digest', array('MC_Leads_Digest', 'send'));

if (!wp_next_scheduled('mc_leads_engine_weekly_digest')) {
    wp_schedule_event(time(), 'weekly', 'mc_leads_engine_weekly_digest');
}

// ─── AJAX: Update lead status ─────────────────────────────────────────────
add_action('wp_ajax_mc_leads_update_status', function () {
    check_ajax_referer('mc_leads_engine_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'mc-leads-engine')));
    }

    $lead_id = absint($_POST['lead_id'] ?? 0);
    $status  = sanitize_key($_POST['status'] ?? '');
    $notes   = sanitize_textarea_field($_POST['notes'] ?? '');

    if (!$lead_id || !$status) {
        wp_send_json_error(array('message' => __('Invalid data.', 'mc-leads-engine')));
    }

    $ok = mc_leads_engine_leads_repository()->update_lead_status($lead_id, $status, $notes);
    $ok ? wp_send_json_success() : wp_send_json_error(array('message' => __('Update failed.', 'mc-leads-engine')));
});

// ─── AJAX: Add manual activity note ──────────────────────────────────────
add_action('wp_ajax_mc_leads_add_note', function () {
    check_ajax_referer('mc_leads_engine_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'mc-leads-engine')));
    }

    $lead_id = absint($_POST['lead_id'] ?? 0);
    $note    = sanitize_textarea_field($_POST['note'] ?? '');

    if (!$lead_id || !$note) {
        wp_send_json_error(array('message' => __('Note cannot be empty.', 'mc-leads-engine')));
    }

    $ok = MC_Leads_Activity::log($lead_id, 'note', $note, get_current_user_id());
    if ($ok) {
        wp_send_json_success(array(
            'time' => current_time('mysql'),
            'user' => wp_get_current_user()->display_name ?: __('Admin', 'mc-leads-engine'),
            'body' => $note,
        ));
    } else {
        wp_send_json_error(array('message' => __('Could not save note.', 'mc-leads-engine')));
    }
});
