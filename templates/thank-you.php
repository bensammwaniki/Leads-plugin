<?php
if (!defined('ABSPATH')) {
    exit;

}

$lead_id = absint($lead_id ?? 0);
$survey_id = absint($survey_id ?? 0);
$lead = mc_leads_engine_leads_repository()->get_lead($lead_id);

global $wpdb;
$booking = $lead ? $wpdb->get_row($wpdb->prepare("SELECT * FROM " . mc_leads_engine_table('bookings') . " WHERE lead_id = %d", $lead_id), ARRAY_A) : null;
$base_url = !empty($base_url) ? esc_url_raw($base_url) : '';
$new_estimate_url = remove_query_arg(array('mc_leads_submitted', 'lead_id'), $base_url);

$survey_settings = mc_leads_engine_get_survey_settings($survey_id);
$message = !empty($survey_settings['final_message']) ? $survey_settings['final_message'] : __('Your estimate request has been submitted successfully.', 'mc-leads-engine');
$pricing = array(
    'total_price' => $lead ? $lead['total_price'] : 0,
    'lead_score'  => $lead ? $lead['lead_score'] : 0,
);
$message_formatted = mc_leads_engine_format_final_message($message, $pricing, $survey_id);
if ($lead) {
    $message_formatted = str_replace('[lead_id]', $lead['id'], $message_formatted);
}

// A booking-only lead has survey_id = 0 (created from the [mc_booking] shortcode).
// A survey lead has survey_id > 0, even if it later has a booking linked to it.
$has_booking = !empty($booking);
$is_booking_only = ($survey_id === 0 && $has_booking);
?>
<div class="mc-leads-engine mc-leads-engine-thank-you">
    <div class="mc-leads-engine-card mc-thank-you-card">
        
        <?php if ($has_booking) : ?>
            <div class="mc-thank-you-message">
                <p><strong><?php esc_html_e('Your booking has been scheduled successfully.', 'mc-leads-engine'); ?></strong></p>
            </div>
            <div class="mc-final-summary" style="margin-bottom: 25px;">
                <p><strong><?php esc_html_e('Meeting Type:', 'mc-leads-engine'); ?></strong> <?php 
                    $type_labels = array(
                        'online' => __('Online Call', 'mc-leads-engine'),
                        'coffee' => __('Coffee Meeting', 'mc-leads-engine'),
                        'office' => __('Office Visit', 'mc-leads-engine'),
                        'host'   => __('Our Studio', 'mc-leads-engine'),
                    );
                    echo esc_html($type_labels[$booking['meeting_type']] ?? $booking['meeting_type']); 
                ?></p>
                <p><strong><?php esc_html_e('Date & Time:', 'mc-leads-engine'); ?></strong> <?php echo esc_html($booking['meeting_date'] . ' @ ' . $booking['meeting_time']); ?></p>
                <p><strong><?php esc_html_e('Location:', 'mc-leads-engine'); ?></strong> <?php echo esc_html($booking['location_name'] . ($booking['location_address'] ? ' (' . $booking['location_address'] . ')' : '')); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($survey_id > 0) : ?>
            <?php 
                // Only show the survey final message if it's explicitly set, or if it's NOT a booking.
                // This prevents the generic "estimate request" message from showing on a booking.
                $has_custom_message = !empty($survey_settings['final_message']) && $survey_settings['final_message'] !== __('Review your answers and submit the survey to create a lead.', 'mc-leads-engine');
                if ($has_custom_message || !$has_booking) :
            ?>
            <div class="mc-thank-you-message" style="margin-bottom: 25px; line-height: 1.6; color: #4b5563;">
                <?php echo wpautop($message_formatted); ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <p class="mc-thank-you-action">
            <?php
            $button_url = $is_booking_only ? add_query_arg('mc_new_booking', '1', $new_estimate_url) : add_query_arg('mc_leads_restart', '1', $new_estimate_url);
            $button_text = $is_booking_only ? esc_html__('Book Another Meeting', 'mc-leads-engine') : esc_html__('New Estimate', 'mc-leads-engine');
            echo '<a href="' . esc_url($button_url) . '" class="button button-primary mc-submit-survey">' . $button_text . '</a>';
            ?>
        </p>
    </div>
</div>
