<?php
if (!defined('ABSPATH')) {
    exit;
}

$lead_id = absint($lead_id ?? 0);
$survey_id = absint($survey_id ?? 0);
$lead = mc_leads_engine_leads_repository()->get_lead($lead_id);

global $wpdb;
$booking = $lead ? $wpdb->get_row($wpdb->prepare("SELECT * FROM " . mc_leads_engine_table('bookings') . " WHERE lead_id = %d", $lead_id), ARRAY_A) : null;
$new_estimate_url = remove_query_arg(array('mc_leads_submitted', 'lead_id'));
?>
<div class="mc-leads-engine mc-leads-engine-thank-you">
    <div class="mc-leads-engine-card mc-thank-you-card">
        <p class="mc-thank-you-action">
            <?php
            $button_url = ($survey_id === 0 && $booking) ? add_query_arg('mc_new_booking', '1', $new_estimate_url) : add_query_arg('mc_leads_restart', '1', $new_estimate_url);
            $button_text = ($survey_id === 0 && $booking) ? esc_html__('Book Another Meeting', 'mc-leads-engine') : esc_html__('New Estimate', 'mc-leads-engine');
            echo '<a href="' . esc_url($button_url) . '" class="button button-primary mc-submit-survey">' . $button_text . '</a>';
            ?>
        </p>
    </div>
</div>
