<?php

if (!defined('ABSPATH')) {
    exit;
}

$saved_answers = isset($context['saved_answers']) && is_array($context['saved_answers']) ? $context['saved_answers'] : array();
?>
<section class="mc-leads-engine-section" data-section-id="<?php echo esc_attr($section['id']); ?>">
    <div class="mc-section-header">
        <h3><?php echo esc_html($section['title']); ?></h3>
        <?php if (!empty($section['description'])) : ?>
            <p><?php echo wp_kses_post($section['description']); ?></p>
        <?php endif; ?>
    </div>

    <div class="mc-section-questions">
        <?php foreach ($section['questions'] as $question) : ?>
            <?php echo $renderer->render_question($question, array('saved_answers' => $saved_answers)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <?php endforeach; ?>
    </div>

    <div class="mc-leads-engine-nav">
        <?php if ((int) $section['order_index'] > 0) : ?>
            <button type="button" class="button mc-step-prev" data-direction="prev"><?php esc_html_e('Previous', 'mc-leads-engine'); ?></button>
        <?php endif; ?>
        <?php if (!empty($context['is_last'])) : ?>
            <button type="submit" class="button button-primary mc-submit-survey"><?php echo esc_html($context['final_button_text'] ?? __('Get Your Estimate', 'mc-leads-engine')); ?></button>
        <?php else : ?>
            <button type="button" class="button button-primary mc-step-next" data-direction="next"><?php esc_html_e('Next', 'mc-leads-engine'); ?></button>
        <?php endif; ?>
    </div>
</section>
