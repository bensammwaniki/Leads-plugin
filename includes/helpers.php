<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns the lead score band ('hot', 'warm', or 'cold') based on
 * the configured thresholds in plugin settings.
 *
 * @param int $score
 * @return string 'hot' | 'warm' | 'cold'
 */
function mc_leads_score_band($score) {
    $settings = mc_leads_engine_get_settings();
    $hot  = (int) ($settings['score_hot_threshold']  ?? 80);
    $warm = (int) ($settings['score_warm_threshold'] ?? 50);

    if ((int) $score >= $hot)  return 'hot';
    if ((int) $score >= $warm) return 'warm';
    return 'cold';
}

/**
 * Returns a human-readable label for a score band.
 *
 * @param string $band 'hot' | 'warm' | 'cold'
 * @return string
 */
function mc_leads_score_band_label($band) {
    $labels = array(
        'hot'  => __('Hot', 'mc-leads-engine'),
        'warm' => __('Warm', 'mc-leads-engine'),
        'cold' => __('Cold', 'mc-leads-engine'),
    );
    return $labels[$band] ?? ucfirst($band);
}

/**
 * Renders a score band badge as an HTML span.
 * Safe to output directly — all values are escaped.
 *
 * @param int $score
 * @return string HTML
 */
function mc_leads_score_badge($score) {
    $band  = mc_leads_score_band($score);
    $label = mc_leads_score_band_label($band);
    return sprintf(
        '<span class="mc-score-badge mc-score-%s">%s &bull; %d</span>',
        esc_attr($band),
        esc_html($label),
        (int) $score
    );
}

/**
 * Detect a rough device category from a user-agent string.
 * No external library — regex only.
 *
 * @param string $user_agent
 * @return string 'mobile' | 'tablet' | 'desktop'
 */
function mc_leads_parse_device($user_agent) {
    $ua = (string) $user_agent;
    if (preg_match('/tablet|ipad|playbook|silk/i', $ua))   return 'tablet';
    if (preg_match('/mobi|android|iphone|ipod|blackberry|opera mini|iemobile/i', $ua)) return 'mobile';
    return 'desktop';
}

/**
 * Returns a dashicon class for a device type.
 *
 * @param string $device 'mobile' | 'tablet' | 'desktop'
 * @return string dashicon class
 */
function mc_leads_device_icon($device) {
    $icons = array(
        'mobile'  => 'dashicons-smartphone',
        'tablet'  => 'dashicons-tablet',
        'desktop' => 'dashicons-desktop',
    );
    return $icons[$device] ?? 'dashicons-desktop';
}

/**
 * Checks whether the given lead is a booking lead.
 * Accepts the full lead array from get_lead().
 *
 * @param array $lead
 * @return bool
 */
function mc_leads_is_booking($lead) {
    global $wpdb;
    $lead_id = absint($lead['id'] ?? 0);
    if (!$lead_id) return false;

    $in_bookings = (bool) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM " . mc_leads_engine_table('bookings') . " WHERE lead_id = %d",
            $lead_id
        )
    );
    if ($in_bookings) return true;

    // Fallback: check CF7 data for booking marker
    $cf7_rows = mc_leads_engine_leads_repository()->get_cf7_data($lead_id);
    if (!empty($cf7_rows)) {
        $cf7_data = json_decode($cf7_rows[0]['data_json'] ?? '{}', true);
        if (is_array($cf7_data) && isset($cf7_data['mc_booking_date'])) {
            return true;
        }
    }

    return false;
}

/**
 * Returns a lead pipeline status label.
 *
 * @param string $status
 * @return string
 */
function mc_leads_status_label($status) {
    $labels = array(
        'new'           => __('New', 'mc-leads-engine'),
        'contacted'     => __('Contacted', 'mc-leads-engine'),
        'qualified'     => __('Qualified', 'mc-leads-engine'),
        'proposal_sent' => __('Proposal Sent', 'mc-leads-engine'),
        'won'           => __('Won', 'mc-leads-engine'),
        'lost'          => __('Lost', 'mc-leads-engine'),
    );
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/**
 * Returns all allowed lead pipeline statuses.
 *
 * @return array
 */
function mc_leads_get_statuses() {
    return array('new', 'contacted', 'qualified', 'proposal_sent', 'won', 'lost');
}
