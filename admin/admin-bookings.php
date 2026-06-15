<?php

if (!defined('ABSPATH')) {
    exit;
}

function mc_leads_engine_render_bookings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'mc-leads-engine'));
    }

    global $wpdb;

    $filter_type = sanitize_key($_GET['meeting_type'] ?? 'all');
    $filter_status = sanitize_key($_GET['status'] ?? 'all');

    // Retrieve metrics
    $bookings_table = mc_leads_engine_table('bookings');
    $total_bookings = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$bookings_table}");
    $online_count   = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$bookings_table} WHERE meeting_type = %s", 'online'));
    $coffee_count   = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$bookings_table} WHERE meeting_type = %s", 'coffee'));
    $office_count   = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$bookings_table} WHERE meeting_type = %s", 'office'));
    $host_count     = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$bookings_table} WHERE meeting_type = %s", 'host'));

    // Build query conditions
    $where = array('1=1');
    $params = array();

    if ($filter_type !== 'all') {
        $where[] = "b.meeting_type = %s";
        $params[] = $filter_type;
    }

    $today = current_time('mysql');
    if ($filter_status === 'upcoming') {
        $where[] = "CONCAT(b.meeting_date, ' ', b.meeting_time) >= %s";
        $params[] = $today;
    } elseif ($filter_status === 'past') {
        $where[] = "CONCAT(b.meeting_date, ' ', b.meeting_time) < %s";
        $params[] = $today;
    }

    $where_sql = implode(' AND ', $where);
    $leads_table = mc_leads_engine_table('leads');

    $sql = "SELECT b.*, l.survey_id, l.answers_json, l.lead_score
            FROM {$bookings_table} b
            LEFT JOIN {$leads_table} l ON b.lead_id = l.id
            WHERE {$where_sql}
            ORDER BY b.meeting_date DESC, b.meeting_time DESC
            LIMIT 200";

    if (!empty($params)) {
        $sql = $wpdb->prepare($sql, $params);
    }

    $bookings = $wpdb->get_results($sql, ARRAY_A);

    ?>
    <div class="wrap mc-leads-engine-admin">
        <h1 class="wp-heading-inline"><?php esc_html_e('Bookings Dashboard', 'mc-leads-engine'); ?></h1>
        <hr class="wp-header-end">

        <!-- Booking Analytics Cards -->
        <div class="mc-leads-engine-cards" style="margin-top:20px; margin-bottom:20px;">
            <div class="mc-card">
                <strong><?php echo esc_html(number_format_i18n($total_bookings)); ?></strong>
                <span><?php esc_html_e('Total Reservations', 'mc-leads-engine'); ?></span>
            </div>
            <div class="mc-card">
                <strong><?php echo esc_html(number_format_i18n($online_count)); ?></strong>
                <span><?php esc_html_e('Online Video Calls', 'mc-leads-engine'); ?></span>
            </div>
            <div class="mc-card">
                <strong><?php echo esc_html(number_format_i18n($coffee_count)); ?></strong>
                <span><?php esc_html_e('Coffee Meetings', 'mc-leads-engine'); ?></span>
            </div>
            <div class="mc-card">
                <strong><?php echo esc_html(number_format_i18n($office_count)); ?></strong>
                <span><?php esc_html_e('Office Visits', 'mc-leads-engine'); ?></span>
            </div>
            <div class="mc-card">
                <strong><?php echo esc_html(number_format_i18n($host_count)); ?></strong>
                <span><?php esc_html_e('At Our Studio', 'mc-leads-engine'); ?></span>
            </div>
        </div>

        <div class="mc-panel">
            <h2><?php esc_html_e('Scheduled Meetings', 'mc-leads-engine'); ?></h2>

            <form method="get" class="mc-analytics-filter-form" style="margin-bottom: 20px;">
                <input type="hidden" name="page" value="mc-leads-engine-bookings">

                <label>
                    <?php esc_html_e('Meeting Format:', 'mc-leads-engine'); ?>
                    <select name="meeting_type">
                        <option value="all" <?php selected($filter_type, 'all'); ?>><?php esc_html_e('All Formats', 'mc-leads-engine'); ?></option>
                        <option value="online" <?php selected($filter_type, 'online'); ?>><?php esc_html_e('Online Call', 'mc-leads-engine'); ?></option>
                        <option value="coffee" <?php selected($filter_type, 'coffee'); ?>><?php esc_html_e('Coffee Meeting', 'mc-leads-engine'); ?></option>
                        <option value="office" <?php selected($filter_type, 'office'); ?>><?php esc_html_e('Office Visit', 'mc-leads-engine'); ?></option>
                        <option value="host" <?php selected($filter_type, 'host'); ?>><?php esc_html_e('Our Studio', 'mc-leads-engine'); ?></option>
                    </select>
                </label>

                <label>
                    <?php esc_html_e('Date/Status:', 'mc-leads-engine'); ?>
                    <select name="status">
                        <option value="all" <?php selected($filter_status, 'all'); ?>><?php esc_html_e('All Bookings', 'mc-leads-engine'); ?></option>
                        <option value="upcoming" <?php selected($filter_status, 'upcoming'); ?>><?php esc_html_e('Upcoming Meetings', 'mc-leads-engine'); ?></option>
                        <option value="past" <?php selected($filter_status, 'past'); ?>><?php esc_html_e('Past Meetings', 'mc-leads-engine'); ?></option>
                    </select>
                </label>

                <button class="button button-primary" type="submit"><?php esc_html_e('Filter', 'mc-leads-engine'); ?></button>
            </form>

            <div style="overflow-x: auto;">
                <table class="widefat striped mc-analytics-leads-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('ID', 'mc-leads-engine'); ?></th>
                            <th><?php esc_html_e('Date & Time', 'mc-leads-engine'); ?></th>
                            <th><?php esc_html_e('Meeting Type', 'mc-leads-engine'); ?></th>
                            <th><?php esc_html_e('Location details', 'mc-leads-engine'); ?></th>
                            <th><?php esc_html_e('Client Contact', 'mc-leads-engine'); ?></th>
                            <th><?php esc_html_e('Lead Score', 'mc-leads-engine'); ?></th>
                            <th><?php esc_html_e('Google Calendar Link', 'mc-leads-engine'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($bookings)) : ?>
                        <tr>
                            <td colspan="7" style="text-align: center; font-style: italic; color:#94a3b8; padding: 30px;">
                                <?php esc_html_e('No bookings matched your filters.', 'mc-leads-engine'); ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($bookings as $row) : 
                            $lead_id = (int)$row['lead_id'];
                            $name = mc_leads_engine_leads_repository()->find_client_name($lead_id);
                            $email = mc_leads_engine_leads_repository()->find_client_email($lead_id);
                            $phone = mc_leads_engine_leads_repository()->find_client_phone($lead_id);

                            $type_labels = array(
                                'online' => __('Online Call', 'mc-leads-engine'),
                                'coffee' => __('Coffee Meeting', 'mc-leads-engine'),
                                'office' => __('Office Visit', 'mc-leads-engine'),
                                'host'   => __('Our Studio', 'mc-leads-engine'),
                            );
                            $type_lbl = $type_labels[$row['meeting_type']] ?? $row['meeting_type'];

                            // Check status
                            $meeting_dt = strtotime($row['meeting_date'] . ' ' . $row['meeting_time']);
                            $is_past = $meeting_dt < current_time('timestamp');
                            $status_class = $is_past ? 'badge-draft' : 'badge-active';
                            $status_lbl = $is_past ? __('Past', 'mc-leads-engine') : __('Upcoming', 'mc-leads-engine');
                        ?>
                            <tr>
                                <td>#<?php echo esc_html($row['id']); ?></td>
                                <td>
                                    <strong><?php echo esc_html(wp_date('F j, Y', $meeting_dt)); ?></strong><br>
                                    <span><?php echo esc_html(wp_date('g:i A', $meeting_dt)); ?></span><br>
                                    <span class="survey-badge <?php echo esc_attr($status_class); ?>" style="font-size:10px; padding: 1px 6px; margin-top:3px; display:inline-block;"><?php echo esc_html($status_lbl); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($type_lbl); ?></strong>
                                </td>
                                <td>
                                    <?php if (!empty($row['location_name'])) : ?>
                                        <strong><?php echo esc_html($row['location_name']); ?></strong><br>
                                    <?php endif; ?>
                                    <span class="description"><?php echo esc_html($row['location_address'] ?: '-'); ?></span>
                                </td>
                                <td>
                                    <?php if ($name) : ?><strong><?php esc_html_e('Name:', 'mc-leads-engine'); ?></strong> <?php echo esc_html($name); ?><br><?php endif; ?>
                                    <?php if ($email) : ?><strong><?php esc_html_e('Email:', 'mc-leads-engine'); ?></strong> <?php echo esc_html($email); ?><br><?php endif; ?>
                                    <?php if ($phone) : ?><strong><?php esc_html_e('Phone:', 'mc-leads-engine'); ?></strong> <?php echo esc_html($phone); ?><?php endif; ?>
                                    <?php if (!$name && !$email && !$phone) : ?>
                                        <span class="description">-</span>
                                    <?php endif; ?>
                                    <br>
                                    <a style="font-size:11px;" href="<?php echo esc_url(add_query_arg(array('page' => 'mc-leads-engine-leads', 'lead_id' => $lead_id), admin_url('admin.php'))); ?>">
                                        <?php printf(esc_html__('View Lead Details #%d &rarr;', 'mc-leads-engine'), $lead_id); ?>
                                    </a>
                                </td>
                                <td>
                                    <strong style="color:var(--mc-brand);"><?php echo esc_html($row['lead_score'] ?? '0'); ?></strong>
                                </td>
                                <td>
                                    <?php if (!empty($row['calendar_event_id'])) : ?>
                                        <span class="dashicons dashicons-yes" style="color: #16a34a; font-size:18px; margin-right:3px;"></span>
                                        <code style="font-size: 10px;"><?php echo esc_html(substr($row['calendar_event_id'], 0, 12)); ?>...</code>
                                    <?php else : ?>
                                        <span class="description"><?php esc_html_e('Not Sync\'d', 'mc-leads-engine'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
}
