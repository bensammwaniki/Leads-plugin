<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the weekly admin digest email.
 * Registered as a WP-Cron job from mc-leads-engine.php.
 */
class MC_Leads_Digest {

    /**
     * Build and send the weekly digest to the notification email address.
     * Called by the 'mc_leads_engine_weekly_digest' cron hook.
     */
    public static function send() {
        $settings = mc_leads_engine_get_settings();

        // Only send if digest is enabled
        if (empty($settings['digest_email_enable'])) {
            return;
        }

        $recipient = !empty($settings['notification_email'])
            ? $settings['notification_email']
            : get_option('admin_email');

        if (!is_email($recipient)) {
            return;
        }

        $repo       = mc_leads_engine_leads_repository();
        $metrics    = $repo->get_dashboard_metrics();
        $daily      = $repo->get_daily_lead_stats(7);

        // Totals for last 7 days
        $week_leads   = 0;
        $week_revenue = 0.0;
        foreach ($daily as $day) {
            $week_leads   += (int) $day['lead_count'];
            $week_revenue += (float) $day['revenue'];
        }

        // Hot leads needing follow-up (status = 'new', score >= hot threshold)
        $hot_threshold = (int) ($settings['score_hot_threshold'] ?? 80);
        global $wpdb;
        $hot_leads_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM " . mc_leads_engine_table('leads') .
                " WHERE status = 'new' AND lead_score >= %d",
                $hot_threshold
            )
        );

        $total_leads = (int) $metrics['total_leads'];
        $pipeline    = (float) $metrics['revenue'];
        $conv_rate   = (float) $metrics['conversion_rate'];

        $subject = sprintf(
            __('[MC Leads Engine] Weekly Digest — %s', 'mc-leads-engine'),
            wp_date('M j, Y')
        );

        $site_name = get_bloginfo('name');
        $admin_url = admin_url('admin.php?page=mc-leads-engine-analytics');

        $body  = '<div style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;border:1px solid #e2e8f0;border-radius:8px;background:#fff">';
        $body .= '<h2 style="color:#2563eb;border-bottom:2px solid #2563eb;padding-bottom:10px;margin-top:0">';
        $body .= esc_html(sprintf(__('Weekly Summary — %s', 'mc-leads-engine'), $site_name));
        $body .= '</h2>';

        // Summary cards
        $body .= '<table style="width:100%;border-collapse:collapse;margin:16px 0">';
        $body .= '<tr>';
        $body .= self::stat_cell(__('New Leads (7d)', 'mc-leads-engine'), $week_leads, '#2563eb');
        $body .= self::stat_cell(__('Revenue (7d)', 'mc-leads-engine'), 'KES ' . number_format($week_revenue, 2), '#22c55e');
        $body .= self::stat_cell(__('Hot Leads (uncontacted)', 'mc-leads-engine'), $hot_leads_count, '#ef4444');
        $body .= '</tr>';
        $body .= '<tr>';
        $body .= self::stat_cell(__('All-time Leads', 'mc-leads-engine'), $total_leads, '#6366f1');
        $body .= self::stat_cell(__('Pipeline Value', 'mc-leads-engine'), 'KES ' . number_format($pipeline, 2), '#0891b2');
        $body .= self::stat_cell(__('Conversion Rate', 'mc-leads-engine'), number_format($conv_rate, 1) . '%', '#f59e0b');
        $body .= '</tr>';
        $body .= '</table>';

        // CTA
        $body .= '<p style="text-align:center;margin-top:24px">';
        $body .= '<a href="' . esc_url($admin_url) . '" style="background:#2563eb;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold">';
        $body .= esc_html__('View Full Analytics', 'mc-leads-engine');
        $body .= '</a>';
        $body .= '</p>';

        $body .= '<p style="color:#64748b;font-size:12px;margin-top:24px;border-top:1px solid #e2e8f0;padding-top:10px">';
        $body .= esc_html__('This digest is sent weekly by MC Leads Engine. You can disable it in plugin settings.', 'mc-leads-engine');
        $body .= '</p>';
        $body .= '</div>';

        wp_mail($recipient, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
    }

    /**
     * Helper: build a table cell for a stat.
     */
    private static function stat_cell($label, $value, $color) {
        return sprintf(
            '<td style="padding:12px;text-align:center;border:1px solid #e2e8f0;border-radius:6px">'
            . '<div style="font-size:22px;font-weight:bold;color:%s">%s</div>'
            . '<div style="font-size:12px;color:#64748b;margin-top:4px">%s</div>'
            . '</td>',
            esc_attr($color),
            esc_html($value),
            esc_html($label)
        );
    }
}
