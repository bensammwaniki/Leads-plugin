<?php

if (!defined('ABSPATH')) {
    exit;
}

class MC_Leads_Engine_Booking {
    public function __construct() {
        add_shortcode('mc_booking', array($this, 'render_booking_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
        
        // REST API endpoint — used by the frontend instead of admin-ajax.php.
        // REST requests go to /wp-json/ (frontend URL) which LiteSpeed never
        // blocks for unauthenticated users, unlike /wp-admin/admin-ajax.php.
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // Legacy AJAX endpoints kept as fallback (may be blocked on some hosts).
        add_action('wp_ajax_mc_leads_booking_slots', array($this, 'ajax_get_slots'));
        add_action('wp_ajax_nopriv_mc_leads_booking_slots', array($this, 'ajax_get_slots'));
        add_action('wp_ajax_mc_leads_booking_predefined', array($this, 'ajax_get_predefined'));
        add_action('wp_ajax_nopriv_mc_leads_booking_predefined', array($this, 'ajax_get_predefined'));

        // Tell LiteSpeed Cache plugin to never cache our booking AJAX actions.
        add_action('init', array($this, 'litespeed_no_cache_for_booking_ajax'));

        // Admin OAuth Hook
        add_action('admin_init', array($this, 'handle_gcal_oauth_callback'));
    }

    /**
     * Register WordPress REST API routes for the booking wizard.
     * These bypass LiteSpeed's wp-admin blocking entirely.
     */
    public function register_rest_routes() {
        register_rest_route('mc-leads/v1', '/slots', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_get_slots'),
            'permission_callback' => '__return_true', // Public read-only endpoint
        ));

        register_rest_route('mc-leads/v1', '/predefined-locations', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_get_predefined'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * REST API callback: return available time slots for a given date.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rest_get_slots( $request ) {
        $date = sanitize_text_field($request->get_param('date'));
        if (empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new WP_Error(
                'invalid_date',
                __('Invalid date format.', 'mc-leads-engine'),
                array('status' => 400)
            );
        }

        try {
            $slots = $this->get_available_slots_for_date($date);
            return rest_ensure_response(array('slots' => $slots));
        } catch (Throwable $e) {
            return new WP_Error(
                'slots_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * REST API callback: return predefined meeting locations.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function rest_get_predefined( $request ) {
        $settings = mc_leads_engine_get_settings();
        $predefined_raw = $settings['booking_predefined_locations'] ?? '';
        $predefined = array();
        if (!empty($predefined_raw)) {
            foreach (explode('|', $predefined_raw) as $part) {
                $trimmed = trim($part);
                if ($trimmed !== '') {
                    $predefined[] = $trimmed;
                }
            }
        }
        return rest_ensure_response(array('locations' => $predefined));
    }

    /**
     * Tell the LiteSpeed Cache WordPress plugin to skip caching for our
     * booking AJAX endpoints.  Hostinger runs LiteSpeed at the server level
     * AND via the WordPress plugin — both need to be told to back off.
     */
    public function litespeed_no_cache_for_booking_ajax() {
        $action = sanitize_key($_REQUEST['action'] ?? '');
        if (in_array($action, array('mc_leads_booking_slots', 'mc_leads_booking_predefined'), true)) {
            // LiteSpeed Cache plugin hook
            do_action('litespeed_control_set_nocache', 'mc_booking_ajax');
            // Generic no-cache for any other cache plugin
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
            if (!defined('DONOTCACHEDB')) {
                define('DONOTCACHEDB', true);
            }
        }
    }

    public function register_assets() {
        wp_register_style('mc-leads-engine-booking', MC_LEADS_ENGINE_URL . 'assets/css/booking-frontend.css', array(), MC_LEADS_ENGINE_VERSION);
        wp_register_script('mc-leads-engine-booking', MC_LEADS_ENGINE_URL . 'assets/js/booking-frontend.js', array(), MC_LEADS_ENGINE_VERSION, true);
    }

    /**
     * Enqueue Google Maps JS API with Places library.
     * Called from render_booking_shortcode when a Maps API key is configured.
     * The 'callback=MCLeadsBookingMapsReady' parameter lets the JS know when
     * Maps has fully loaded so autocomplete can be attached to the input.
     */
    private function enqueue_google_maps( $api_key ) {
        if (empty($api_key) || wp_script_is('google-maps-places', 'enqueued')) {
            return;
        }

        $src = add_query_arg(array(
            'key'       => $api_key,
            'libraries' => 'places',
            'loading'   => 'async',
            'callback'  => 'MCLeadsBookingMapsReady',
        ), 'https://maps.googleapis.com/maps/api/js');

        // Register without a version (null) so WordPress doesn't append ?ver= which
        // can confuse the Maps API loader.
        wp_enqueue_script('google-maps-places', $src, array(), null, true);
    }

    public function render_booking_shortcode($atts) {
        $atts = shortcode_atts(array(
            'cf7' => 0,
        ), $atts, 'mc_booking');

        $settings = mc_leads_engine_get_settings();
        $cf7_id = absint($atts['cf7']);
        if (!$cf7_id) {
            $cf7_id = absint($settings['booking_default_cf7'] ?? 0);
        }

        if (!$cf7_id) {
            return '<div class="mc-leads-engine-notice">' . esc_html__('Booking CF7 form ID is required. Please specify it in the shortcode attributes or configure a default form in Settings.', 'mc-leads-engine') . '</div>';
        }

        wp_enqueue_style('mc-leads-engine-booking');
        wp_enqueue_script('mc-leads-engine-booking');

        // Load Google Maps Places API if a key is configured.
        $gmaps_key = $settings['gmaps_api_key'] ?? '';
        if (!empty($gmaps_key)) {
            $this->enqueue_google_maps($gmaps_key);
        }

        $session = mc_leads_engine_session();
        $session->maybe_start_session();

        // Handle thank you success screen
        if (isset($_GET['mc_leads_submitted']) && (int) $_GET['mc_leads_submitted'] === 1) {
            $lead_id = $_GET['lead_id'] ?? 0;
            if ($lead_id === 'active') {
                $lead_id = absint($session->get_data()['lead_id'] ?? 0);
            } else {
                $lead_id = absint($lead_id);
            }
            $session->clear_session();
            return mc_leads_engine_render_template('thank-you.php', array(
                'survey_id' => 0,
                'lead_id'   => $lead_id,
            ));
        }

        wp_localize_script(
            'mc-leads-engine-booking',
            'MCLeadsBooking',
            array(
                'ajaxUrl'   => admin_url('admin-ajax.php'),
                'restUrl'   => rest_url('mc-leads/v1/'),   // Used by frontend — bypasses LiteSpeed wp-admin blocking
                'nonce'     => wp_create_nonce('mc_leads_engine_booking_nonce'),
                'restNonce' => wp_create_nonce('wp_rest'),  // REST API nonce for authenticated calls
                'cf7Id'     => $cf7_id,
                'sessionId' => $session->get_session_id(),
                'gmapsKey'  => $settings['gmaps_api_key'] ?? '',
            )
        );

        $predefined_raw = $settings['booking_predefined_locations'] ?? '';
        $predefined = array();
        if (!empty($predefined_raw)) {
            $parts = explode('|', $predefined_raw);
            foreach ($parts as $part) {
                $trimmed = trim($part);
                if ($trimmed !== '') {
                    $predefined[] = $trimmed;
                }
            }
        }

        return mc_leads_engine_render_template('booking.php', array(
            'cf7_id'     => $cf7_id,
            'session_id' => $session->get_session_id(),
            'settings'   => $settings,
            'predefined' => $predefined,
        ));
    }

    public function ajax_get_slots() {
        // Force no-cache at every possible layer:
        // 1. nocache_headers() sends standard Cache-Control / Pragma headers
        // 2. X-LiteSpeed-Cache-Control tells LiteSpeed server NOT to cache/serve from cache
        // 3. X-LiteSpeed-Vary resets any vary-key LiteSpeed stored
        // Without these, Hostinger's LiteSpeed serves a cached HTML page to browsers
        // that lack a WordPress session cookie (Firefox, Safari, Edge, incognito),
        // causing JSON.parse() to fail and showing "Error loading slots".
        nocache_headers();
        header('X-LiteSpeed-Cache-Control: no-cache, no-store');
        header('X-LiteSpeed-Vary: ');
        header('X-Robots-Tag: noindex');

        $date = sanitize_text_field($_POST['date'] ?? '');
        if (empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            wp_send_json_error(array('message' => __('Invalid date format.', 'mc-leads-engine')));
        }

        try {
            $slots = $this->get_available_slots_for_date($date);
            wp_send_json_success(array('slots' => $slots));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function ajax_get_predefined() {
        // Force no-cache at every possible layer (see ajax_get_slots for explanation).
        nocache_headers();
        header('X-LiteSpeed-Cache-Control: no-cache, no-store');
        header('X-LiteSpeed-Vary: ');
        $settings = mc_leads_engine_get_settings();
        $predefined_raw = $settings['booking_predefined_locations'] ?? '';
        $predefined = array();
        if (!empty($predefined_raw)) {
            $parts = explode('|', $predefined_raw);
            foreach ($parts as $part) {
                $trimmed = trim($part);
                if ($trimmed !== '') {
                    $predefined[] = $trimmed;
                }
            }
        }
        wp_send_json_success(array('locations' => $predefined));
    }

    public function get_available_slots_for_date($date) {
        $settings = mc_leads_engine_get_settings();
        
        // Check if date falls in working days
        $day_of_week = date('N', strtotime($date)); // 1 (Mon) - 7 (Sun)
        $working_days = $settings['booking_days'] ?? array('1', '2', '3', '4', '5');
        if (!in_array((string)$day_of_week, $working_days, true)) {
            return array();
        }

        $start_time = $settings['booking_hours_start'] ?? '09:00';
        $end_time = $settings['booking_hours_end'] ?? '17:00';
        $duration = (int)($settings['booking_duration'] ?? 30);
        $buffer = (int)($settings['booking_buffer'] ?? 15);
        $interval = $duration + $buffer;

        $slots = array();
        $timezone = wp_timezone();
        try {
            $current_dt = new DateTime("$date $start_time", $timezone);
            $end_dt = new DateTime("$date $end_time", $timezone);
        } catch (Exception $e) {
            $current_dt = new DateTime("$date $start_time");
            $end_dt = new DateTime("$date $end_time");
        }

        // Do not display past times for today
        $now = function_exists('current_datetime') ? current_datetime() : new DateTimeImmutable('now', $timezone);

        while ($current_dt->getTimestamp() + ($duration * 60) <= $end_dt->getTimestamp()) {
            if ($current_dt >= $now) {
                $slots[] = array(
                    'time'      => $current_dt->format('H:i'),
                    'timestamp' => $current_dt->getTimestamp(),
                    'available' => true
                );
            }
            $current_dt->modify("+$interval minutes");
        }

        // Fetch local database bookings for this date
        global $wpdb;
        $bookings_table = mc_leads_engine_table('bookings');
        $local_bookings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meeting_time FROM {$bookings_table} WHERE meeting_date = %s",
                $date
            ),
            ARRAY_A
        );

        $local_busy = array();
        if (!empty($local_bookings)) {
            foreach ($local_bookings as $booking) {
                $time_str = $booking['meeting_time']; // E.g., "09:00:00"
                try {
                    $b_start_dt = new DateTime("$date $time_str", $timezone);
                    $b_start = $b_start_dt->getTimestamp();
                } catch (Exception $e) {
                    $b_start = strtotime("$date $time_str");
                }
                $b_end = $b_start + ($duration * 60);
                $local_busy[] = array(
                    'start' => $b_start,
                    'end'   => $b_end,
                );
            }
        }

        // Fetch Google Calendar busy slots if connected
        $busy_slots = $this->fetch_gcal_busy_slots($date);
        $all_busy_slots = array_merge($busy_slots, $local_busy);

        if (!empty($all_busy_slots)) {
            foreach ($slots as $key => $slot) {
                $slot_start = $slot['timestamp'];
                $slot_end = $slot['timestamp'] + ($duration * 60);

                foreach ($all_busy_slots as $busy) {
                    $busy_start = $busy['start'];
                    $busy_end = $busy['end'];

                    // Overlap check
                    if ($slot_start < $busy_end && $slot_end > $busy_start) {
                        $slots[$key]['available'] = false;
                        break;
                    }
                }
            }
        }

        return array_values(array_filter($slots, function($s) { return $s['available']; }));
    }

    private function fetch_gcal_busy_slots($date) {
        $token = $this->get_gcal_access_token();
        if (!$token) {
            return array();
        }

        $settings = mc_leads_engine_get_settings();
        $calendar_id = urlencode($settings['gcal_calendar_id'] ?? 'primary');

        $timezone = wp_timezone();
        try {
            $min_dt = new DateTime("$date 00:00:00", $timezone);
            $max_dt = new DateTime("$date 23:59:59", $timezone);
            $time_min = $min_dt->format('c');
            $time_max = $max_dt->format('c');
        } catch (Exception $e) {
            $time_min = date('c', strtotime("$date 00:00:00"));
            $time_max = date('c', strtotime("$date 23:59:59"));
        }

        $url = "https://www.googleapis.com/calendar/v3/calendars/{$calendar_id}/events?timeMin=" . urlencode($time_min) . "&timeMax=" . urlencode($time_max) . "&singleEvents=true&orderBy=startTime";

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            )
        ));

        if (is_wp_error($response)) {
            return array();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['items'])) {
            return array();
        }

        $busy = array();
        foreach ($body['items'] as $item) {
            // Skip cancelled events
            if (isset($item['status']) && $item['status'] === 'cancelled') {
                continue;
            }
            // Skip transparent (free) events
            if (isset($item['transparency']) && $item['transparency'] === 'transparent') {
                continue;
            }

            if (!empty($item['start']['dateTime']) && !empty($item['end']['dateTime'])) {
                $busy[] = array(
                    'start' => strtotime($item['start']['dateTime']),
                    'end'   => strtotime($item['end']['dateTime']),
                );
            } elseif (!empty($item['start']['date']) && !empty($item['end']['date'])) {
                // All day event
                try {
                    $start_dt = new DateTime($item['start']['date'] . ' 00:00:00', $timezone);
                    $end_dt = new DateTime($item['end']['date'] . ' 23:59:59', $timezone);
                    $busy[] = array(
                        'start' => $start_dt->getTimestamp(),
                        'end'   => $end_dt->getTimestamp(),
                    );
                } catch (Exception $e) {
                    $busy[] = array(
                        'start' => strtotime($item['start']['date'] . ' 00:00:00'),
                        'end'   => strtotime($item['end']['date'] . ' 23:59:59'),
                    );
                }
            }
        }

        return $busy;
    }

    public function create_gcal_event($booking_data) {
        $token = $this->get_gcal_access_token();
        if (!$token) {
            return false;
        }

        $settings = mc_leads_engine_get_settings();
        $calendar_id = urlencode($settings['gcal_calendar_id'] ?? 'primary');

        $date = $booking_data['meeting_date'];
        $time = $booking_data['meeting_time'];
        $duration = (int)($settings['booking_duration'] ?? 30);

        $start_timestamp = strtotime("$date $time");
        $end_timestamp = $start_timestamp + ($duration * 60);

        $start_iso = date('c', $start_timestamp);
        $end_iso = date('c', $end_timestamp);

        $type_labels = array(
            'online' => __('Online Video Call', 'mc-leads-engine'),
            'coffee' => __('Coffee Meeting', 'mc-leads-engine'),
            'office' => __('Office Visit (Client\'s Location)', 'mc-leads-engine'),
            'host'   => __('Meeting at predefined host location', 'mc-leads-engine'),
        );
        $type_label = $type_labels[$booking_data['meeting_type']] ?? __('Meeting', 'mc-leads-engine');

        $location = $booking_data['location_address'];
        if (!empty($booking_data['location_name'])) {
            $location = $booking_data['location_name'] . ' (' . $location . ')';
        }

        $summary = sprintf(__('%s with %s', 'mc-leads-engine'), $type_label, $booking_data['client_name'] ?? __('Client', 'mc-leads-engine'));
        $description = sprintf(
            __("Booking details via MC Leads Engine:\n\nClient Name: %s\nClient Email: %s\nClient Phone: %s\n\nMeeting Type: %s\nLocation: %s\nMessage: %s", 'mc-leads-engine'),
            $booking_data['client_name'] ?? 'N/A',
            $booking_data['client_email'] ?? 'N/A',
            $booking_data['client_phone'] ?? 'N/A',
            $type_label,
            $location,
            $booking_data['client_message'] ?? 'N/A'
        );

        $payload = array(
            'summary'     => $summary,
            'description' => $description,
            'start'       => array('dateTime' => $start_iso),
            'end'         => array('dateTime' => $end_iso),
        );

        if (!empty($location)) {
            $payload['location'] = $location;
        }

        $url = "https://www.googleapis.com/calendar/v3/calendars/{$calendar_id}/events";

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode($payload)
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['id'] ?? false;
    }

    public function get_gcal_client_auth_url() {
        $settings = mc_leads_engine_get_settings();
        $client_id = $settings['gcal_client_id'] ?? '';
        if (empty($client_id)) {
            return '';
        }

        $redirect_uri = admin_url('admin.php?page=mc-leads-engine-settings');

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(array(
            'scope'         => 'https://www.googleapis.com/auth/calendar.events',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'response_type' => 'code',
            'state'         => wp_create_nonce('mc_gcal_oauth_state'),
            'redirect_uri'  => $redirect_uri,
            'client_id'     => $client_id,
        ));
    }

    public function get_gcal_access_token() {
        $settings = mc_leads_engine_get_settings();
        $access_token = $settings['gcal_access_token'] ?? '';
        $expires = (int)($settings['gcal_token_expires'] ?? 0);
        $refresh_token = $settings['gcal_refresh_token'] ?? '';

        if (empty($access_token)) {
            return '';
        }

        // If active and not expired (with 1 min buffer)
        if ($expires > time() + 60) {
            return $access_token;
        }

        // Token expired, refresh it
        if (empty($refresh_token)) {
            return '';
        }

        $client_id = $settings['gcal_client_id'] ?? '';
        $client_secret = $settings['gcal_client_secret'] ?? '';

        if (empty($client_id) || empty($client_secret)) {
            return '';
        }

        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body' => array(
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $refresh_token,
                'grant_type'    => 'refresh_token',
            )
        ));

        if (is_wp_error($response)) {
            return '';
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            return '';
        }

        $settings['gcal_access_token'] = sanitize_text_field($body['access_token']);
        if (!empty($body['expires_in'])) {
            $settings['gcal_token_expires'] = time() + (int)$body['expires_in'];
        }
        update_option('mc_leads_engine_settings', $settings);

        return $settings['gcal_access_token'];
    }

    public function handle_gcal_oauth_callback() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (empty($_GET['page']) || $_GET['page'] !== 'mc-leads-engine-settings' || empty($_GET['code'])) {
            return;
        }

        $state = sanitize_text_field($_GET['state'] ?? '');
        if (!wp_verify_nonce($state, 'mc_gcal_oauth_state')) {
            return;
        }

        $code = sanitize_text_field($_GET['code']);
        $settings = mc_leads_engine_get_settings();
        $client_id = $settings['gcal_client_id'] ?? '';
        $client_secret = $settings['gcal_client_secret'] ?? '';
        $redirect_uri = admin_url('admin.php?page=mc-leads-engine-settings');

        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body' => array(
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            )
        ));

        if (is_wp_error($response)) {
            wp_die(__('Failed to retrieve Google Calendar access token.', 'mc-leads-engine'));
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            wp_die(__('Invalid token response from Google OAuth.', 'mc-leads-engine') . ' ' . esc_html(wp_remote_retrieve_body($response)));
        }

        $settings['gcal_access_token'] = sanitize_text_field($body['access_token']);
        $settings['gcal_refresh_token'] = sanitize_text_field($body['refresh_token'] ?? $settings['gcal_refresh_token']);
        $settings['gcal_token_expires'] = time() + (int)($body['expires_in'] ?? 3600);

        update_option('mc_leads_engine_settings', $settings);

        wp_safe_redirect(add_query_arg(array('page' => 'mc-leads-engine-settings', 'gcal_auth_success' => 1), admin_url('admin.php')));
        exit;
    }

    public function save_booking($lead_id, $data) {
        global $wpdb;

        $lead_id = absint($lead_id);
        $meeting_type = sanitize_text_field($data['meeting_type'] ?? 'online');
        $location_type = sanitize_text_field($data['location_type'] ?? 'custom');
        $location_name = sanitize_text_field($data['location_name'] ?? '');
        $location_address = sanitize_text_field($data['location_address'] ?? '');
        $meeting_date = sanitize_text_field($data['meeting_date'] ?? '');
        $meeting_time = sanitize_text_field($data['meeting_time'] ?? '');

        // Add lead scoring impact based on booking options
        $settings = mc_leads_engine_get_settings();
        $score_impact = 10;
        if ($meeting_type === 'online') {
            $score_impact = (int)($settings['booking_score_online'] ?? 10);
        } elseif ($meeting_type === 'coffee') {
            $score_impact = (int)($settings['booking_score_coffee'] ?? 20);
        } elseif ($meeting_type === 'office') {
            $score_impact = (int)($settings['booking_score_office'] ?? 30);
        } elseif ($meeting_type === 'host') {
            $score_impact = (int)($settings['booking_score_host'] ?? 20);
        }

        $lead = mc_leads_engine_leads_repository()->get_lead($lead_id);
        if ($lead) {
            $new_score = (int)$lead['lead_score'] + $score_impact;
            $wpdb->update(
                mc_leads_engine_table('leads'),
                array('lead_score' => $new_score),
                array('id' => $lead_id),
                array('%d'),
                array('%d')
            );
        }

        // Insert into wp_mcle_bookings
        $wpdb->insert(
            mc_leads_engine_table('bookings'),
            array(
                'lead_id'          => $lead_id,
                'meeting_type'     => $meeting_type,
                'location_type'    => $location_type,
                'location_name'    => $location_name,
                'location_address' => $location_address,
                'meeting_date'     => $meeting_date,
                'meeting_time'     => $meeting_time,
                'created_at'       => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        $booking_id = $wpdb->insert_id;

        // Compile client context parameters for calendar
        $booking_record = array(
            'meeting_type'     => $meeting_type,
            'location_type'    => $location_type,
            'location_name'    => $location_name,
            'location_address' => $location_address,
            'meeting_date'     => $meeting_date,
            'meeting_time'     => $meeting_time,
            'client_name'      => $data['client_name'] ?? '',
            'client_email'     => $data['client_email'] ?? '',
            'client_phone'     => $data['client_phone'] ?? '',
            'client_message'   => $data['client_message'] ?? '',
        );

        // Attempt calendar creation
        $gcal_id = $this->create_gcal_event($booking_record);
        if ($gcal_id) {
            $wpdb->update(
                mc_leads_engine_table('bookings'),
                array('calendar_event_id' => $gcal_id),
                array('id' => $booking_id),
                array('%s'),
                array('%d')
            );
        }

        return $booking_id;
    }

    public function get_bookings($args = array()) {
        global $wpdb;

        $defaults = array(
            'limit' => 50,
            'offset' => 0,
        );
        $args = wp_parse_args($args, $defaults);

        $bookings_table = mc_leads_engine_table('bookings');
        $leads_table = mc_leads_engine_table('leads');

        $sql = "SELECT b.*, l.lead_score, l.survey_id
                FROM {$bookings_table} b
                LEFT JOIN {$leads_table} l ON b.lead_id = l.id
                ORDER BY b.meeting_date DESC, b.meeting_time DESC
                LIMIT %d OFFSET %d";

        return $wpdb->get_results($wpdb->prepare($sql, absint($args['limit']), absint($args['offset'])), ARRAY_A);
    }
}
