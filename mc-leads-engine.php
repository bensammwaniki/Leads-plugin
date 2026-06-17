<?php
/*
Plugin Name: MC Leads Engine
Plugin URI: https://memoriescreative.com/
Description: Modular survey, pricing, lead capture, CF7 integration, analytics, and shortcode engine.
Version: 1.2.0
Author: Bensam Mwaniki
Author URI: https://memoriescreative.com/
Requires at least: 5.8
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) {
    exit;
}

define('MC_LEADS_ENGINE_VERSION', '1.2.0');
define('MC_LEADS_ENGINE_FILE', __FILE__);
define('MC_LEADS_ENGINE_PATH', plugin_dir_path(__FILE__));
define('MC_LEADS_ENGINE_URL', plugin_dir_url(__FILE__));

require_once MC_LEADS_ENGINE_PATH . 'database/install.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-session.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-survey.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-section.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-question.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-pricing-engine.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-leads.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-cf7-integration.php';
require_once MC_LEADS_ENGINE_PATH . 'includes/class-booking.php';
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
        'user_email_body'            => '<div style="font-family: sans-serif; padding: 20px; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 8px; background-color: #f8fafc;">
  <h2 style="color: #2563eb; border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-top: 0;">We received your submission!</h2>
  <p style="color: #334155; line-height: 1.6;">Hello [your-name],</p>
  <p style="color: #334155; line-height: 1.6;">Thank you for completing the <strong>[survey_title]</strong> survey. Here is a summary of your estimate:</p>
  <div style="background-color: #ffffff; padding: 15px; border-radius: 6px; margin: 15px 0; border-left: 4px solid #2563eb; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
    <p style="margin: 5px 0; color: #0f172a;"><strong>Lead ID:</strong> #[lead_id]</p>
    <p style="margin: 5px 0; color: #0f172a;"><strong>Estimated Total:</strong> KES [total_price]</p>
    <p style="margin: 5px 0; color: #0f172a;"><strong>Lead Score:</strong> [lead_score]</p>
  </div>
  <p style="color: #334155; line-height: 1.6;">We will review your details and get in touch with you shortly.</p>
  <p style="color: #64748b; font-size: 12px; margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 10px;">This is an automated notification. Please do not reply directly to this email.</p>
</div>',
        'admin_email_subject'        => __('New Lead Submission #[lead_id] - [survey_title]', 'mc-leads-engine'),
        'admin_email_body'           => '<div style="font-family: sans-serif; padding: 20px; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 8px; background-color: #ffffff;">
  <h2 style="color: #ef4444; border-bottom: 2px solid #ef4444; padding-bottom: 10px; margin-top: 0;">New Lead Submitted</h2>
  <p style="color: #0f172a;">A new lead has been generated through the survey: <strong>[survey_title]</strong>.</p>
  <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
    <tr style="background-color: #f8fafc;"><td style="padding: 8px; border: 1px solid #e2e8f0; font-weight: bold; color: #334155;">Lead ID</td><td style="padding: 8px; border: 1px solid #e2e8f0; color: #0f172a;">#[lead_id]</td></tr>
    <tr><td style="padding: 8px; border: 1px solid #e2e8f0; font-weight: bold; color: #334155;">Session ID</td><td style="padding: 8px; border: 1px solid #e2e8f0; color: #0f172a;">[session_id]</td></tr>
    <tr style="background-color: #f8fafc;"><td style="padding: 8px; border: 1px solid #e2e8f0; font-weight: bold; color: #334155;">Total Price</td><td style="padding: 8px; border: 1px solid #e2e8f0; color: #0f172a;">KES [total_price]</td></tr>
    <tr><td style="padding: 8px; border: 1px solid #e2e8f0; font-weight: bold; color: #334155;">Lead Score</td><td style="padding: 8px; border: 1px solid #e2e8f0; color: #0f172a;">[lead_score]</td></tr>
    <tr style="background-color: #f8fafc;"><td style="padding: 8px; border: 1px solid #e2e8f0; font-weight: bold; color: #334155;">Date/Time</td><td style="padding: 8px; border: 1px solid #e2e8f0; color: #0f172a;">[created_at]</td></tr>
  </table>
  <h3 style="color: #0f172a; margin-top: 20px; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px;">Submitted Answers</h3>
  <div style="background-color: #f8fafc; padding: 15px; border-radius: 6px; border: 1px solid #e2e8f0; color: #334155;">[all_answers]</div>
</div>',
        'admin_whatsapp_phone'       => '',
        'admin_whatsapp_body'        => __("New Lead Submission #[lead_id] for \"[survey_title]\"\nPrice: KES [total_price]\nScore: [lead_score]\nClient: [your-name] ([email-address])", 'mc-leads-engine'),
        'user_whatsapp_body'         => __("Hello [your-name],\nThank you for completing the \"[survey_title]\" estimate.\n\nEstimate Details:\n- Estimate: KES [total_price]\n- Lead ID: #[lead_id]\n\nWe will get in touch with you shortly.", 'mc-leads-engine'),
        
        // Booking Notifications templates
        'booking_user_email_subject' => __('Meeting Confirmation: [booking_type] scheduled', 'mc-leads-engine'),
        'booking_user_email_body'    => '<div style="font-family: sans-serif; padding: 20px; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 8px; background-color: #f8fafc;">
  <h2 style="color: #F15D61; border-bottom: 2px solid #F15D61; padding-bottom: 10px; margin-top: 0;">Your Meeting is Scheduled!</h2>
  <p style="color: #334155; line-height: 1.6;">Hello [full-name],</p>
  <p style="color: #334155; line-height: 1.6;">Your meeting has been successfully booked. Here are your details:</p>
  <div style="background-color: #ffffff; padding: 15px; border-radius: 6px; margin: 15px 0; border-left: 4px solid #F15D61; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
    <p style="margin: 5px 0; color: #0f172a;"><strong>Meeting Type:</strong> [booking_type]</p>
    <p style="margin: 5px 0; color: #0f172a;"><strong>Date & Time:</strong> [booking_date] @ [booking_time]</p>
    <p style="margin: 5px 0; color: #0f172a;"><strong>Location:</strong> [booking_location]</p>
  </div>
  <p style="color: #334155; line-height: 1.6;">If you need to reschedule or have any questions, please get in touch with us.</p>
  <p style="color: #64748b; font-size: 12px; margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 10px;">This is an automated confirmation of your booking.</p>
</div>',
        'booking_admin_email_subject'=> __('New Booking scheduled: [booking_type] - [full-name]', 'mc-leads-engine'),
        'booking_admin_email_body'   => '<div style="font-family: sans-serif; padding: 20px; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 8px; background-color: #ffffff;">
  <h2 style="color: #F15D61; border-bottom: 2px solid #F15D61; padding-bottom: 10px; margin-top: 0;">New Meeting Booked</h2>
  <p style="color: #0f172a;">A new meeting has been scheduled by a client.</p>
  <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
    <tr style="background-color: #f8fafc;"><td style="padding: 8px; border: 1px solid #e2e8f0; font-weight: bold; color: #334155;">Client Name</td><td style="padding: 8px; border: 1px solid #e2e8f0; color: #0f172a;">[full-name]</td></tr>
    <tr><td style="padding: 8px; border: 1px solid #e2e8f0; font-weight: bold; color: #334155;">Client Email</td><td style="padding: 8px; border: 1px solid #e2e8f0; color: #0f172a;">[email]</td></tr>
    <tr style="background-color: #f8fafc;"><td style="padding: 8px; border: 1px solid #e2e8f0; font-weight: bold; color: #334155;">Client Phone</td><td style="padding: 8px; border: 1px solid #e2e8f0; color: #0f172a;">[phone]</td></tr>
    <tr><td style="padding: 8px; border: 1px solid #e2e8f0; font-weight: bold; color: #334155;">Meeting Type</td><td style="padding: 8px; border: 1px solid #e2e8f0; color: #0f172a;">[booking_type]</td></tr>
    <tr style="background-color: #f8fafc;"><td style="padding: 8px; border: 1px solid #e2e8f0; font-weight: bold; color: #334155;">Date & Time</td><td style="padding: 8px; border: 1px solid #e2e8f0; color: #0f172a;">[booking_date] @ [booking_time]</td></tr>
    <tr><td style="padding: 8px; border: 1px solid #e2e8f0; font-weight: bold; color: #334155;">Location</td><td style="padding: 8px; border: 1px solid #e2e8f0; color: #0f172a;">[booking_location]</td></tr>
  </table>
  <h3 style="color: #0f172a; margin-top: 20px; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px;">Client Message</h3>
  <div style="background-color: #f8fafc; padding: 15px; border-radius: 6px; border: 1px solid #e2e8f0; color: #334155;">[message]</div>
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
    );

    $settings = get_option('mc_leads_engine_settings', array());

    return wp_parse_args(is_array($settings) ? $settings : array(), $defaults);
}

function mc_leads_engine_get_survey_settings($survey_id) {
    $defaults = array(
        'final_step_title'  => __('Final step', 'mc-leads-engine'),
        'show_final_price'  => 1,
        'show_final_score'  => 1,
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
        'final_button_text' => sanitize_text_field($settings['final_button_text'] ?? ''),
        'final_message'     => wp_kses_post($settings['final_message'] ?? ''),
    );

    return update_option("mc_leads_engine_survey_settings_{$survey_id}", $clean_settings);
}

function mc_leads_engine_format_final_message($message, $pricing, $survey_id = 0) {
    $total_price = number_format_i18n((float) ($pricing['total_price'] ?? 0), 2);
    $lead_score = (int) ($pricing['lead_score'] ?? 0);
    
    $survey_title = '';
    if ($survey_id) {
        $survey = mc_leads_engine_survey_repository()->get_survey($survey_id);
        if ($survey) {
            $survey_title = $survey['title'] ?? '';
        }
    }
    
    $message = str_replace('[total_price]', $total_price, $message);
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

