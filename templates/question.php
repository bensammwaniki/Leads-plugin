<?php

if (!defined('ABSPATH')) {
    exit;
}

$saved_answers = isset($context['saved_answers']) && is_array($context['saved_answers']) ? $context['saved_answers'] : array();
$saved_value = $saved_answers[$question['id']] ?? '';
$question_type = $question['type'];
$options = isset($question['options']) && is_array($question['options']) ? $question['options'] : array();
$required = !empty($question['required']) ? 'required' : '';

// Split question text into title and description if delimiter || is present
$question_text = $question['question_text'];
$question_title = $question_text;
$question_desc = '';
if (strpos($question_text, '||') !== false) {
    $parts = explode('||', $question_text, 2);
    $question_title = trim($parts[0]);
    $question_desc = trim($parts[1]);
}

// Check if the question text or type is CF7 and extract shortcode if present
$cf7_shortcode = '';
$question_title_text = $question_title;

if ($question_type === 'cf7' || strpos($question_title, '[contact-form-7') !== false) {
    if (preg_match('/\[contact-form-7[^\]]*\]/', $question_title, $matches)) {
        $cf7_shortcode = $matches[0];
        $question_title_text = trim(str_replace($cf7_shortcode, '', $question_title));
    } else if ($question_type === 'cf7') {
        $cf7_shortcode = $question_title;
        $question_title_text = '';
    }
}
?>
<div class="mc-question" data-question-id="<?php echo esc_attr($question['id']); ?>" data-question-type="<?php echo esc_attr($question_type); ?>">
    <div class="mc-question-label">
        <?php if (!empty($question_title_text)) : ?>
            <span class="mc-question-title"><?php echo esc_html($question_title_text); ?><?php echo $required ? ' *' : ''; ?></span>
        <?php endif; ?>
        <?php if (!empty($question_desc)) : ?>
            <span class="mc-question-desc"><?php echo esc_html($question_desc); ?></span>
        <?php endif; ?>

        <?php if (!empty($cf7_shortcode)) : ?>
            <div class="mc-cf7-form-wrapper">
                <?php echo do_shortcode($cf7_shortcode); ?>
            </div>
        <?php endif; ?>

        <?php if ($question_type === 'text') : ?>
            <input type="text" name="mc_answers[<?php echo esc_attr($question['id']); ?>]" value="<?php echo esc_attr(is_array($saved_value) ? '' : $saved_value); ?>" <?php echo esc_attr($required); ?> data-question-id="<?php echo esc_attr($question['id']); ?>">

        <?php elseif ($question_type === 'number') : ?>
            <input type="number" name="mc_answers[<?php echo esc_attr($question['id']); ?>]" value="<?php echo esc_attr(is_array($saved_value) ? '' : $saved_value); ?>" <?php echo esc_attr($required); ?> data-question-id="<?php echo esc_attr($question['id']); ?>">

        <?php elseif ($question_type === 'radio') : ?>
            <span class="mc-option-list<?php echo count($options) > 3 ? ' mc-option-grid-2' : ''; ?>">
                <?php foreach ($options as $option) : 
                    $opt_label = $option['label'];
                    $opt_title = $opt_label;
                    $opt_desc = '';
                    if (strpos($opt_label, '||') !== false) {
                        $parts = explode('||', $opt_label, 2);
                        $opt_title = trim($parts[0]);
                        $opt_desc = trim($parts[1]);
                    }
                ?>
                    <label class="mc-option">
                        <input type="radio" name="mc_answers[<?php echo esc_attr($question['id']); ?>]" value="<?php echo esc_attr($option['value']); ?>" <?php checked((string) $saved_value, (string) $option['value']); ?> <?php echo esc_attr($required); ?> data-question-id="<?php echo esc_attr($question['id']); ?>" data-price-impact="<?php echo esc_attr($option['price_impact']); ?>" data-score-impact="<?php echo esc_attr($option['score_impact']); ?>">
                        <span class="mc-option-meta">
                            <span class="mc-option-title"><?php echo esc_html($opt_title); ?></span>
                            <?php if (!empty($opt_desc)) : ?>
                                <span class="mc-option-desc"><?php echo esc_html($opt_desc); ?></span>
                            <?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </span>

        <?php elseif ($question_type === 'checkbox') : ?>
            <?php $saved_value = is_array($saved_value) ? $saved_value : array(); ?>
            <span class="mc-option-list<?php echo count($options) > 3 ? ' mc-option-grid-2' : ''; ?>">
                <?php foreach ($options as $option) : 
                    $opt_label = $option['label'];
                    $opt_title = $opt_label;
                    $opt_desc = '';
                    if (strpos($opt_label, '||') !== false) {
                        $parts = explode('||', $opt_label, 2);
                        $opt_title = trim($parts[0]);
                        $opt_desc = trim($parts[1]);
                    }
                ?>
                    <label class="mc-option">
                        <input type="checkbox" name="mc_answers[<?php echo esc_attr($question['id']); ?>][]" value="<?php echo esc_attr($option['value']); ?>" <?php checked(in_array((string) $option['value'], array_map('strval', $saved_value), true)); ?> data-question-id="<?php echo esc_attr($question['id']); ?>" data-price-impact="<?php echo esc_attr($option['price_impact']); ?>" data-score-impact="<?php echo esc_attr($option['score_impact']); ?>">
                        <span class="mc-option-meta">
                            <span class="mc-option-title"><?php echo esc_html($opt_title); ?></span>
                            <?php if (!empty($opt_desc)) : ?>
                                <span class="mc-option-desc"><?php echo esc_html($opt_desc); ?></span>
                            <?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </span>
        <?php endif; ?>
    </div>
</div>
