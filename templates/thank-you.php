<?php
if (!defined('ABSPATH')) {
    exit;
}

$lead_id = absint($lead_id ?? 0);
$survey_id = absint($survey_id ?? 0);
$lead = mc_leads_engine_leads_repository()->get_lead($lead_id);
$new_estimate_url = remove_query_arg(array('mc_leads_submitted', 'lead_id'));
?>
<div class="mc-leads-engine mc-leads-engine-thank-you">
    <div class="mc-leads-engine-card mc-thank-you-card">
        <span class="dashicons dashicons-yes-alt mc-thank-you-icon"></span>
        <h2><?php esc_html_e('Thank You!', 'mc-leads-engine'); ?></h2>
        <p><?php esc_html_e('Your estimate request has been submitted successfully.', 'mc-leads-engine'); ?></p>
        
        <?php if ($lead) : ?>
            <div class="mc-final-summary">
                <p class="mc-summary-id"><strong><?php esc_html_e('Lead ID:', 'mc-leads-engine'); ?></strong> #<?php echo esc_html($lead['id']); ?></p>
                <p class="mc-summary-price"><strong><?php esc_html_e('Estimated Total:', 'mc-leads-engine'); ?></strong> KES <?php echo esc_html(number_format_i18n((float) $lead['total_price'], 2)); ?></p>
                <p class="mc-summary-score"><strong><?php esc_html_e('Lead Score:', 'mc-leads-engine'); ?></strong> <?php echo esc_html((int) $lead['lead_score']); ?></p>
            </div>
        <?php endif; ?>
        
        <p class="mc-thank-you-action">
            <a href="<?php echo esc_url($new_estimate_url); ?>" class="button button-primary mc-submit-survey"><?php esc_html_e('New Estimate', 'mc-leads-engine'); ?></a>
        </p>
    </div>
</div>
