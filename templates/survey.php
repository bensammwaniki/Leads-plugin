<?php

if (!defined('ABSPATH')) {
    exit;
}

$survey = $bundle['survey'];
$sections = $bundle['sections'];
$total_steps = count($sections) + 1;
$is_cf7 = !empty($cf7_id);
$saved_answers = isset($saved_answers) && is_array($saved_answers) ? $saved_answers : array();
?>
<div class="mc-leads-engine mc-leads-engine-<?php echo esc_attr($is_cf7 ? 'cf7' : 'standard'); ?>" data-survey-id="<?php echo esc_attr($survey_id); ?>" data-session-id="<?php echo esc_attr($session_id); ?>" data-mode="<?php echo esc_attr($is_cf7 ? 'cf7' : 'standard'); ?>" data-total-steps="<?php echo esc_attr($total_steps); ?>" data-current-step="<?php echo esc_attr(max(1, (int) ($current_step ?? 1))); ?>">
    <div class="mc-leads-engine-card">
        <header class="mc-leads-engine-header">
            <h2><?php echo esc_html($survey['title']); ?></h2>
            <?php if (!empty($survey['description'])) : ?>
                <p class="mc-leads-engine-description"><?php echo wp_kses_post($survey['description']); ?></p>
            <?php endif; ?>
        </header>

        <div class="mc-leads-engine-meta">
            <div class="mc-progress">
                <div class="mc-progress-track"><span class="mc-progress-fill" style="width: <?php echo esc_attr((1 / max(1, $total_steps)) * 100); ?>%"></span></div>
                <div class="mc-progress-text">
                    <span class="mc-progress-step"><?php echo esc_html(sprintf(__('Step %d of %d', 'mc-leads-engine'), 1, $total_steps)); ?></span>
                    <span class="mc-progress-price"><?php esc_html_e('Estimated price:', 'mc-leads-engine'); ?> <strong class="mc-live-price"><?php echo esc_html(number_format_i18n((float) ($pricing['total_price'] ?? 0), 2)); ?></strong></span>
                    <span class="mc-progress-score"><?php esc_html_e('Lead score:', 'mc-leads-engine'); ?> <strong class="mc-live-score"><?php echo esc_html((int) ($pricing['lead_score'] ?? 0)); ?></strong></span>
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
                            <?php echo $renderer->render_section($section, $survey, array('saved_answers' => $saved_answers)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="mc-leads-engine-step" data-step="<?php echo esc_attr($total_steps); ?>" hidden>
                        <?php echo $renderer->render_final_step($survey, array('mode' => 'standard', 'pricing' => $pricing, 'session_id' => $session_id, 'cf7_id' => $cf7_id)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                </div>
            </div>
        <?php else : ?>
            <div class="mc-leads-engine-flow">
                <div class="mc-leads-engine-steps">
                    <?php foreach ($sections as $index => $section) : ?>
                        <div class="mc-leads-engine-step" data-step="<?php echo esc_attr($index + 1); ?>" <?php echo $index === 0 ? '' : 'hidden'; ?>>
                            <?php echo $renderer->render_section($section, $survey, array('saved_answers' => $saved_answers)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="mc-leads-engine-step" data-step="<?php echo esc_attr($total_steps); ?>" hidden>
                        <?php echo $renderer->render_final_step($survey, array('mode' => 'cf7', 'pricing' => $pricing, 'session_id' => $session_id, 'cf7_id' => $cf7_id)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
