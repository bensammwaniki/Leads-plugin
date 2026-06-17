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
        <span class="dashicons dashicons-yes-alt mc-thank-you-icon"></span>
        <h2><?php esc_html_e('Thank You!', 'mc-leads-engine'); ?></h2>
        
        <?php if ($booking) : ?>
            <p><?php esc_html_e('Your booking has been scheduled successfully.', 'mc-leads-engine'); ?></p>
            <div class="mc-final-summary">
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
        <?php else : ?>
            <p><?php esc_html_e('Your estimate request has been submitted successfully.', 'mc-leads-engine'); ?></p>
            <?php if ($lead) : ?>
                <div class="mc-final-summary">
                    <p class="mc-summary-id"><strong><?php esc_html_e('Lead ID:', 'mc-leads-engine'); ?></strong> #<?php echo esc_html($lead['id']); ?></p>
                    <p class="mc-summary-price"><strong><?php esc_html_e('Estimated Total:', 'mc-leads-engine'); ?></strong> KES <?php echo esc_html(number_format_i18n((float) $lead['total_price'], 2)); ?></p>
                    <p class="mc-summary-score"><strong><?php esc_html_e('Lead Score:', 'mc-leads-engine'); ?></strong> <?php echo esc_html((int) $lead['lead_score']); ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <p class="mc-thank-you-action">
            <?php
            if ($booking) {
                $book_again_url = add_query_arg('mc_new_booking', '1', $new_estimate_url);
                echo '<a href="' . esc_url($book_again_url) . '" class="button button-primary mc-submit-survey">' . esc_html__('Book Another Meeting', 'mc-leads-engine') . '</a>';
            } else {
                $new_estimate_restart_url = add_query_arg('mc_leads_restart', '1', $new_estimate_url);
                echo '<a href="' . esc_url($new_estimate_restart_url) . '" class="button button-primary mc-submit-survey">' . esc_html__('New Estimate', 'mc-leads-engine') . '</a>';
            }
            ?>
        </p>
    </div>
</div>
