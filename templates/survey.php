<?php

if (!defined('ABSPATH')) {
    exit;
}

$survey = $bundle['survey'];
$sections = $bundle['sections'];
$is_cf7 = !empty($cf7_id);
// Both standard and CF7 modes will now submit directly from the last section.
$total_steps = count($sections);
$survey_settings = mc_leads_engine_get_survey_settings($survey_id);
$show_title = !isset($survey_settings['show_title']) || !empty($survey_settings['show_title']);
$show_description = !isset($survey_settings['show_description']) || !empty($survey_settings['show_description']);
?>
<div class="mc-leads-engine mc-leads-engine-<?php echo esc_attr($is_cf7 ? 'cf7' : 'standard'); ?>" data-survey-id="<?php echo esc_attr($survey_id); ?>" data-session-id="<?php echo esc_attr($session_id); ?>" data-mode="<?php echo esc_attr($is_cf7 ? 'cf7' : 'standard'); ?>" data-total-steps="<?php echo esc_attr($total_steps); ?>" data-current-step="<?php echo esc_attr(max(1, (int) ($current_step ?? 1))); ?>" data-clear-on-load="<?php echo empty($saved_answers) ? '1' : '0'; ?>">
    <div role="button" tabindex="0" class="mc-step-prev mc-back-btn" aria-label="<?php esc_attr_e('Previous Step', 'mc-leads-engine'); ?>" style="display: none;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
    </div>
    <div class="mc-leads-engine-card">
        <?php if ($show_title || ($show_description && !empty($survey['description']))) : ?>
        <header class="mc-leads-engine-header">
            <?php if ($show_title) : ?>
                <h2><?php echo esc_html($survey['title']); ?></h2>
            <?php endif; ?>
            <?php if ($show_description && !empty($survey['description'])) : ?>
                <p class="mc-leads-engine-description"><?php echo wp_kses_post($survey['description']); ?></p>
            <?php endif; ?>
        </header>
        <?php endif; ?>

        <div class="mc-leads-engine-meta">
            <div class="mc-progress">
                <div class="mc-progress-track"><span class="mc-progress-fill" style="width: <?php echo esc_attr((1 / max(1, $total_steps)) * 100); ?>%"></span></div>
                <div class="mc-progress-text">
                    <span class="mc-progress-step"><?php echo esc_html(sprintf(__('Step %d of %d', 'mc-leads-engine'), 1, $total_steps)); ?></span>
                </div>
            </div>
        </div>

        <?php if (!$is_cf7) : ?>
            <div class="mc-leads-engine-form" data-action="<?php echo esc_url(home_url('/')); ?>">
                <?php wp_nonce_field('mc_leads_engine_submit_survey', 'mc_leads_engine_nonce'); ?>
                <input type="hidden" name="action" value="mc_leads_engine_submit_survey">
                <input type="hidden" name="survey_id" value="<?php echo esc_attr($survey_id); ?>">
                <input type="hidden" name="mc_leads_engine_session_id" value="<?php echo esc_attr($session_id); ?>">
                <input type="hidden" name="mc_leads_engine_redirect_to" value="<?php echo esc_attr(get_permalink() ?: home_url('/')); ?>">
                <input type="hidden" name="mc_answers_json" value="">
                <div class="mc-leads-engine-steps">
                    <?php foreach ($sections as $index => $section) : ?>
                        <div class="mc-leads-engine-step" data-step="<?php echo esc_attr($index + 1); ?>" <?php echo $index === 0 ? '' : 'hidden'; ?>>
                            <?php 
                            $is_last = ($index === count($sections) - 1);
                            echo $renderer->render_section($section, $survey, array(
                                'saved_answers' => $saved_answers,
                                'is_last' => $is_last,
                                'final_button_text' => $survey_settings['final_button_text'] ?? __('Get Your Estimate', 'mc-leads-engine')
                            )); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
                            ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else : ?>
            <div class="mc-leads-engine-flow">
                <div class="mc-leads-engine-steps">
                    <?php foreach ($sections as $index => $section) : ?>
                        <div class="mc-leads-engine-step" data-step="<?php echo esc_attr($index + 1); ?>" <?php echo $index === 0 ? '' : 'hidden'; ?>>
                            <?php 
                            $is_last = ($index === count($sections) - 1);
                            echo $renderer->render_section($section, $survey, array(
                                'saved_answers' => $saved_answers,
                                'is_last' => $is_last,
                                'final_button_text' => $survey_settings['final_button_text'] ?? __('Get Your Estimate', 'mc-leads-engine')
                            )); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
                            ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div style="display: none;">
                <?php 
                $cf7_shortcode = trim((string) ($bundle['cf7_shortcode'] ?? ''));
                if ($cf7_shortcode && shortcode_exists('contact-form-7')) {
                    echo do_shortcode($cf7_shortcode);
                }
                ?>
            </div>
        <?php endif; ?>
    </div>
</div>
