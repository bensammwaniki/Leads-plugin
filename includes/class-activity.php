<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the lead activity / audit log.
 * All methods are static — no instantiation required.
 *
 * Supported activity types:
 *   'note'           – Manual admin note
 *   'email_sent'     – Automated or manual email
 *   'whatsapp_sent'  – WhatsApp notification
 *   'status_change'  – Pipeline status updated
 *   'booking_created'– Booking linked to this lead
 */
class MC_Leads_Activity {

    /**
     * Log an activity against a lead.
     *
     * @param int    $lead_id
     * @param string $type      One of the supported activity types
     * @param string $body      Optional human-readable description
     * @param int    $user_id   WordPress user ID (0 = system)
     * @return bool
     */
    public static function log($lead_id, $type, $body = '', $user_id = 0) {
        global $wpdb;

        $lead_id = absint($lead_id);
        $type    = sanitize_key($type);
        $body    = sanitize_textarea_field($body);
        $user_id = absint($user_id);

        if (!$lead_id || !$type) {
            return false;
        }

        $result = $wpdb->insert(
            mc_leads_engine_table('lead_activity'),
            array(
                'lead_id'       => $lead_id,
                'user_id'       => $user_id ?: null,
                'activity_type' => $type,
                'body'          => $body,
                'created_at'    => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%s', '%s')
        );

        return (bool) $result;
    }

    /**
     * Retrieve the activity log for a lead, newest first.
     *
     * @param int $lead_id
     * @param int $limit
     * @return array
     */
    public static function get_log($lead_id, $limit = 50) {
        global $wpdb;

        $lead_id = absint($lead_id);
        $limit   = absint($limit) ?: 50;

        if (!$lead_id) {
            return array();
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT la.*, u.display_name AS user_name
                 FROM " . mc_leads_engine_table('lead_activity') . " la
                 LEFT JOIN {$wpdb->users} u ON u.ID = la.user_id
                 WHERE la.lead_id = %d
                 ORDER BY la.created_at DESC
                 LIMIT %d",
                $lead_id,
                $limit
            ),
            ARRAY_A
        );
    }

    /**
     * Return a dashicon class for a given activity type.
     *
     * @param string $type
     * @return string
     */
    public static function get_icon($type) {
        $icons = array(
            'note'            => 'dashicons-edit-page',
            'email_sent'      => 'dashicons-email-alt',
            'whatsapp_sent'   => 'dashicons-phone',
            'status_change'   => 'dashicons-update',
            'booking_created' => 'dashicons-calendar-alt',
        );
        return $icons[$type] ?? 'dashicons-marker';
    }

    /**
     * Return a human-readable label for an activity type.
     *
     * @param string $type
     * @return string
     */
    public static function get_type_label($type) {
        $labels = array(
            'note'            => __('Note', 'mc-leads-engine'),
            'email_sent'      => __('Email Sent', 'mc-leads-engine'),
            'whatsapp_sent'   => __('WhatsApp Sent', 'mc-leads-engine'),
            'status_change'   => __('Status Changed', 'mc-leads-engine'),
            'booking_created' => __('Booking Created', 'mc-leads-engine'),
        );
        return $labels[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }
}
