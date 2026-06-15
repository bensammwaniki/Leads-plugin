<?php

if (!defined('ABSPATH')) {
    exit;
}

function mc_leads_engine_render_leads_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'mc-leads-engine'));
    }

    $survey_id = absint($_GET['survey_id'] ?? 0);
    $min_score = absint($_GET['min_score'] ?? 0);
    $lead_id = absint($_GET['lead_id'] ?? 0);

    if (!empty($_GET['export'])) {
        check_admin_referer('mc_leads_engine_export_leads');
        $rows = mc_leads_engine_leads_repository()->export_rows(array(
            'survey_id' => $survey_id,
            'min_score' => $min_score,
            'limit' => 10000,
        ));

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=mc-leads-engine-leads.csv');

        $handle = fopen('php://output', 'w');
        fputcsv($handle, array('Lead ID', 'Survey ID', 'Session ID', 'Price', 'Score', 'Created', 'Answers JSON', 'CF7 JSON'));
        foreach ($rows as $row) {
            fputcsv($handle, array(
                $row['id'],
                $row['survey_id'],
                $row['session_id'],
                $row['total_price'],
                $row['lead_score'],
                $row['created_at'],
                $row['answers_json'],
                !empty($row['cf7']) ? wp_json_encode($row['cf7']) : '',
            ));
        }
        fclose($handle);
        exit;
    }

    $leads = mc_leads_engine_leads_repository()->get_leads(array(
        'survey_id' => $survey_id,
        'min_score' => $min_score,
        'limit' => 100,
    ));
    $lead = $lead_id ? mc_leads_engine_leads_repository()->get_lead($lead_id) : null;
    $lead_answers = $lead_id ? mc_leads_engine_leads_repository()->get_lead_answers($lead_id) : array();
    $lead_cf7 = $lead_id ? mc_leads_engine_leads_repository()->get_cf7_data($lead_id) : array();
    $surveys = mc_leads_engine_survey_repository()->get_surveys(array('limit' => 100));
    ?>
    <div class="wrap mc-leads-engine-admin">
        <h1><?php esc_html_e('Leads', 'mc-leads-engine'); ?></h1>

        <form method="get" class="mc-inline-form">
            <input type="hidden" name="page" value="mc-leads-engine-leads">
            <label><?php esc_html_e('Survey', 'mc-leads-engine'); ?>
                <select name="survey_id">
                    <option value="0"><?php esc_html_e('All surveys', 'mc-leads-engine'); ?></option>
                    <?php foreach ($surveys as $survey) : ?>
                        <option value="<?php echo esc_attr($survey['id']); ?>" <?php selected($survey_id, $survey['id']); ?>><?php echo esc_html($survey['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php esc_html_e('Min Score', 'mc-leads-engine'); ?><input type="number" name="min_score" value="<?php echo esc_attr($min_score); ?>"></label>
            <button class="button" type="submit"><?php esc_html_e('Filter', 'mc-leads-engine'); ?></button>
            <?php wp_nonce_field('mc_leads_engine_export_leads'); ?>
            <a class="button button-secondary" href="<?php echo esc_url(add_query_arg(array('page' => 'mc-leads-engine-leads', 'survey_id' => $survey_id, 'min_score' => $min_score, 'export' => 1, '_wpnonce' => wp_create_nonce('mc_leads_engine_export_leads')), admin_url('admin.php'))); ?>"><?php esc_html_e('Export CSV', 'mc-leads-engine'); ?></a>
        </form>

        <?php if ($lead) : ?>
            <div class="mc-panel">
                <h2><?php echo esc_html(sprintf(__('Lead #%d', 'mc-leads-engine'), $lead['id'])); ?></h2>
                <p><?php echo esc_html(sprintf(__('Survey ID: %d | Session: %s | Price: %s | Score: %d', 'mc-leads-engine'), $lead['survey_id'], $lead['session_id'], number_format_i18n((float) $lead['total_price'], 2), $lead['lead_score'])); ?></p>
                <h3><?php esc_html_e('Answers', 'mc-leads-engine'); ?></h3>
                <pre><?php echo esc_html(wp_json_encode($lead_answers, JSON_PRETTY_PRINT)); ?></pre>
                <h3><?php esc_html_e('CF7 Data', 'mc-leads-engine'); ?></h3>
                <pre><?php echo esc_html(wp_json_encode($lead_cf7, JSON_PRETTY_PRINT)); ?></pre>
            </div>
        <?php endif; ?>

        <div class="mc-panel">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'mc-leads-engine'); ?></th>
                        <th><?php esc_html_e('Survey', 'mc-leads-engine'); ?></th>
                        <th><?php esc_html_e('Session', 'mc-leads-engine'); ?></th>
                        <th><?php esc_html_e('Price', 'mc-leads-engine'); ?></th>
                        <th><?php esc_html_e('Score', 'mc-leads-engine'); ?></th>
                        <th><?php esc_html_e('Created', 'mc-leads-engine'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($leads as $row) : ?>
                    <?php 
                    global $wpdb;
                    $survey_row = mc_leads_engine_survey_repository()->get_survey($row['survey_id']);
                    $is_booking = (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . mc_leads_engine_table('bookings') . " WHERE lead_id = %d", $row['id']));
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url(add_query_arg(array('page' => 'mc-leads-engine-leads', 'lead_id' => $row['id'], 'survey_id' => $survey_id, 'min_score' => $min_score), admin_url('admin.php'))); ?>"><?php echo esc_html($row['id']); ?></a></td>
                        <td><?php echo $is_booking ? esc_html__('Bookings', 'mc-leads-engine') : esc_html($survey_row['title'] ?? $row['survey_id']); ?></td>
                        <td><?php echo esc_html($row['session_id']); ?></td>
                        <td><?php echo esc_html(number_format_i18n((float) $row['total_price'], 2)); ?></td>
                        <td><?php echo esc_html($row['lead_score']); ?></td>
                        <td><?php echo esc_html($row['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
