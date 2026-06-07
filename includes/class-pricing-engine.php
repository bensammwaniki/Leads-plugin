<?php

if (!defined('ABSPATH')) {
    exit;
}

class MC_Leads_Engine_Pricing_Engine {
    public function calculate_survey_price($answers, $survey_id = 0, $settings = array()) {
        $survey_id = absint($survey_id);
        $settings = wp_parse_args(is_array($settings) ? $settings : array(), mc_leads_engine_get_settings());

        $result = array(
            'base_price' => isset($settings['default_base_price']) ? (float) $settings['default_base_price'] : 0,
            'total_price' => 0,
            'lead_score' => 0,
            'breakdown' => array(),
        );

        $result['total_price'] = (float) $result['base_price'];
        $result['breakdown'][] = array(
            'label' => __('Base price', 'mc-leads-engine'),
            'amount' => (float) $result['base_price'],
            'score' => 0,
            'rule_type' => 'base',
        );

        $bundle = $survey_id ? mc_leads_engine_survey_repository()->get_survey_bundle($survey_id) : null;
        if ($bundle && !empty($bundle['sections'])) {
            foreach ($bundle['sections'] as $section) {
                foreach ($section['questions'] as $question) {
                    $applied = $this->apply_rules($question, $answers[$question['id']] ?? null, $settings);
                    $result['total_price'] += $applied['price'];
                    $result['lead_score'] += $applied['score'];
                    $result['breakdown'] = array_merge($result['breakdown'], $applied['breakdown']);
                }
            }
        }

        $global_rule_results = $this->apply_global_rules($answers, $bundle, $settings);
        $result['total_price'] += $global_rule_results['price'];
        $result['lead_score'] += $global_rule_results['score'];
        $result['breakdown'] = array_merge($result['breakdown'], $global_rule_results['breakdown']);

        $result['total_price'] = round((float) $result['total_price'], 2);

        return $result;
    }

    public function apply_rules($question, $answer, $settings = array()) {
        $result = array(
            'price' => 0,
            'score' => 0,
            'breakdown' => array(),
        );

        $question_type = $question['type'] ?? 'text';
        $options = isset($question['options']) && is_array($question['options']) ? $question['options'] : array();

        if ($question_type === 'checkbox' && is_array($answer)) {
            foreach ($answer as $selected_value) {
                $matched = $this->match_option($options, $selected_value);
                if ($matched) {
                    $amount = (float) $matched['price_impact'];
                    $score = (int) $matched['score_impact'];
                    $result['price'] += $amount;
                    $result['score'] += $score;
                    $result['breakdown'][] = $this->make_breakdown_row($question, $matched, $amount, $score, 'option');
                }
            }

            return $result;
        }

        if ($question_type === 'number') {
            $numeric_answer = is_numeric($answer) ? (float) $answer : 0;
            $matched = $this->match_numeric_option($options, $answer);

            if ($matched) {
                $is_unit_rule = $this->is_unit_rule($matched);
                $amount = $is_unit_rule ? $numeric_answer * (float) $matched['price_impact'] : (float) $matched['price_impact'];
                $score = (int) $matched['score_impact'];
                $result['price'] += $amount;
                $result['score'] += $score;
                $result['breakdown'][] = $this->make_breakdown_row($question, $matched, $amount, $score, $is_unit_rule ? 'per_unit' : 'fixed');
            }

            return $result;
        }

        $matched = $this->match_option($options, $answer);
        if ($matched) {
            $amount = (float) $matched['price_impact'];
            $score = (int) $matched['score_impact'];
            $result['price'] += $amount;
            $result['score'] += $score;
            $result['breakdown'][] = $this->make_breakdown_row($question, $matched, $amount, $score, 'option');
        }

        return $result;
    }

    public function return_breakdown($result) {
        return isset($result['breakdown']) ? $result['breakdown'] : array();
    }

    protected function apply_global_rules($answers, $bundle, $settings) {
        $result = array(
            'price' => 0,
            'score' => 0,
            'breakdown' => array(),
        );

        $rules_json = isset($settings['default_pricing_rules_json']) ? trim((string) $settings['default_pricing_rules_json']) : '';
        if ($rules_json === '') {
            return $result;
        }

        $rules = json_decode($rules_json, true);
        if (!is_array($rules)) {
            return $result;
        }

        $questions = array();
        if ($bundle && !empty($bundle['sections'])) {
            foreach ($bundle['sections'] as $section) {
                foreach ($section['questions'] as $question) {
                    $questions[] = $question;
                }
            }
        }

        foreach ($rules as $rule) {
            $match = strtolower(trim((string) ($rule['match'] ?? '')));
            if ($match === '') {
                continue;
            }

            $rule_type = sanitize_key($rule['type'] ?? 'fixed');
            $amount = isset($rule['amount']) ? (float) $rule['amount'] : 0;

            foreach ($questions as $question) {
                $answer = $answers[$question['id']] ?? null;
                $haystack = strtolower($question['question_text'] . ' ' . $this->flatten_answer($answer));
                if (strpos($haystack, $match) === false) {
                    continue;
                }

                $numeric_answer = is_numeric($answer) ? (float) $answer : 0;
                $applied_amount = $rule_type === 'per_unit' ? $numeric_answer * $amount : $amount;

                if ($applied_amount === 0) {
                    continue;
                }

                $result['price'] += $applied_amount;
                $result['breakdown'][] = array(
                    'label' => sprintf('%s (%s)', $question['question_text'], $match),
                    'amount' => $applied_amount,
                    'score' => 0,
                    'rule_type' => $rule_type,
                );
            }
        }

        return $result;
    }

    protected function match_option($options, $answer) {
        if (!is_array($options)) {
            return null;
        }

        $answer = is_scalar($answer) ? (string) $answer : '';
        foreach ($options as $option) {
            $value = (string) ($option['value'] ?? '');
            $label = (string) ($option['label'] ?? '');

            if ($answer !== '' && (strcasecmp($answer, $value) === 0 || strcasecmp($answer, $label) === 0)) {
                return $option;
            }
        }

        return null;
    }

    protected function match_numeric_option($options, $answer) {
        if (!is_array($options)) {
            return null;
        }

        $numeric_answer = is_numeric($answer) ? (float) $answer : 0;
        foreach ($options as $option) {
            $value = strtolower(trim((string) ($option['value'] ?? '')));
            if (in_array($value, array('per_unit', 'unit', 'each', '*'), true)) {
                return $option;
            }

            if ($value !== '' && is_numeric($value) && (float) $value === $numeric_answer) {
                return $option;
            }
        }

        return !empty($options) ? $options[0] : null;
    }

    protected function is_unit_rule($option) {
        $value = strtolower(trim((string) ($option['value'] ?? '')));
        return in_array($value, array('per_unit', 'unit', 'each', '*'), true);
    }

    protected function make_breakdown_row($question, $option, $amount, $score, $rule_type) {
        return array(
            'label' => sprintf('%s — %s', $question['question_text'], $option['label'] ?? $option['value'] ?? ''),
            'amount' => round((float) $amount, 2),
            'score' => (int) $score,
            'rule_type' => $rule_type,
        );
    }

    protected function flatten_answer($answer) {
        if (is_array($answer)) {
            return implode(' ', array_map('strval', $answer));
        }

        return (string) $answer;
    }
}
