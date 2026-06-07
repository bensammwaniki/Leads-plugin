<?php

if (!defined('ABSPATH')) {
    exit;
}

$mode = $context['mode'] ?? 'standard';
$pricing = isset($context['pricing']) && is_array($context['pricing']) ? $context['pricing'] : array();
$cf7_id = absint($context['cf7_id'] ?? 0);
$cf7_shortcode = trim((string) ($context['cf7_shortcode'] ?? ''));
$session_id = sanitize_text_field($context['session_id'] ?? '');

$survey_id = absint($survey['id'] ?? 0);
$survey_settings = mc_leads_engine_get_survey_settings($survey_id);

// Parse the message with placeholders
$message = $survey_settings['final_message'];
$message_formatted = mc_leads_engine_format_final_message($message, $pricing, $survey_id);
?>
<section class="mc-leads-engine-final" data-final-step="1">
    <h3><?php echo esc_html($survey_settings['final_step_title']); ?></h3>
    
    <?php if (!empty($survey_settings['show_final_price']) || !empty($survey_settings['show_final_score'])) : ?>
        <div class="mc-final-summary">
            <?php if (!empty($survey_settings['show_final_price'])) : ?>
                <p class="mc-summary-price">
                    <?php echo esc_html(__('Estimated total:', 'mc-leads-engine')); ?> 
                    <span class="mc-live-price"><?php echo esc_html(number_format_i18n((float) ($pricing['total_price'] ?? 0), 2)); ?></span>
                </p>
            <?php endif; ?>
            <?php if (!empty($survey_settings['show_final_score'])) : ?>
                <p class="mc-summary-score">
                    <?php echo esc_html(__('Lead score:', 'mc-leads-engine')); ?> 
                    <span class="mc-live-score"><?php echo esc_html((int) ($pricing['lead_score'] ?? 0)); ?></span>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($mode === 'cf7' && $cf7_shortcode && shortcode_exists('contact-form-7')) : ?>
        <div class="mc-final-cf7">
            <?php echo do_shortcode($cf7_shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <div class="mc-final-message description"><?php echo $message_formatted; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
            <div class="mc-leads-engine-nav">
                <button type="button" class="button mc-step-prev" data-direction="prev"><?php esc_html_e('Previous', 'mc-leads-engine'); ?></button>
            </div>
            <div class="mc-restart-container">
                <a href="<?php echo esc_url(add_query_arg('mc_leads_restart', 1)); ?>" class="mc-restart-link"><?php esc_html_e('Start New Estimate', 'mc-leads-engine'); ?></a>
            </div>
        </div>
    <?php else : ?>
        <div class="mc-final-standard">
            <?php if ($mode === 'cf7' && ($cf7_shortcode || $cf7_id)) : ?>
                <p><?php esc_html_e('The linked CF7 form is not available, so the survey will use the standard submit flow.', 'mc-leads-engine'); ?></p>
            <?php endif; ?>
            <div class="mc-final-message"><?php echo $message_formatted; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
            <div class="mc-leads-engine-nav">
                <button type="button" class="button mc-step-prev" data-direction="prev"><?php esc_html_e('Previous', 'mc-leads-engine'); ?></button>
                <button type="submit" class="button button-primary mc-submit-survey"><?php echo esc_html($survey_settings['final_button_text']); ?></button>
            </div>
            <div class="mc-restart-container">
                <a href="<?php echo esc_url(add_query_arg('mc_leads_restart', 1)); ?>" class="mc-restart-link"><?php esc_html_e('Start New Estimate', 'mc-leads-engine'); ?></a>
            </div>
        </div>
    <?php endif; ?>

    <input type="hidden" class="mc-final-session-id" value="<?php echo esc_attr($session_id); ?>">
</section>
