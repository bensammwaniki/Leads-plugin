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
    $orderby = sanitize_key($_GET['orderby'] ?? 'date');
    $order = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

    if ($orderby === 'id') {
        $order_sql = "b.id {$order}";
    } else {
        $orderby = 'date';
        $order_sql = "b.meeting_date {$order}, b.meeting_time {$order}";
    }

    $bookings_table = mc_leads_engine_table('bookings');
    $leads_table = mc_leads_engine_table('leads');

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

    // Handle Excel Bookings Export
    if (!empty($_GET['export_bookings'])) {
        check_admin_referer('mc_leads_engine_export_bookings');

        $sql = "SELECT b.*, l.survey_id, l.answers_json, l.lead_score
                FROM {$bookings_table} b
                LEFT JOIN {$leads_table} l ON b.lead_id = l.id
                WHERE {$where_sql}
                ORDER BY {$order_sql}";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        $bookings_rows = $wpdb->get_results($sql, ARRAY_A) ?: array();

        $headers = array(
            __('Booking ID', 'mc-leads-engine'),
            __('Date & Time', 'mc-leads-engine'),
            __('Meeting Type', 'mc-leads-engine'),
            __('Location Details', 'mc-leads-engine'),
            __('Client Name', 'mc-leads-engine'),
            __('Client Email', 'mc-leads-engine'),
            __('Client Phone', 'mc-leads-engine'),
            __('Lead Score', 'mc-leads-engine'),
            __('Status', 'mc-leads-engine'),
        );

        $col_types = array('text', 'text', 'text', 'text', 'text', 'text', 'text', 'score', 'text');
        $col_alignments = array('center', 'left', 'left', 'left', 'left', 'left', 'left', 'center', 'center');

        $export_data = array();
        foreach ($bookings_rows as $row) {
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

            $meeting_dt = strtotime($row['meeting_date'] . ' ' . $row['meeting_time']);
            $is_past = $meeting_dt < current_time('timestamp');
            $status_lbl = $is_past ? __('Past', 'mc-leads-engine') : __('Upcoming', 'mc-leads-engine');

            $location_details = ($row['location_name'] ? $row['location_name'] . "\n" : '') . ($row['location_address'] ?: '');

            $export_data[] = array(
                $row['id'],
                wp_date('F j, Y g:i A', $meeting_dt),
                $type_lbl,
                $location_details,
                $name,
                $email,
                $phone,
                $row['lead_score'] !== null ? (int)$row['lead_score'] : 0,
                $status_lbl,
            );
        }

        $writer = new MC_Leads_Engine_XLSX_Writer('Bookings');
        $writer->set_headers($headers);
        $writer->set_rows($export_data);
        $writer->set_col_types($col_types);
        $writer->set_col_alignments($col_alignments);
        $writer->write_to_output('mc-leads-engine-bookings.xlsx');
    }

    // Retrieve metrics
    $total_bookings = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$bookings_table}");
    $online_count   = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$bookings_table} WHERE meeting_type = %s", 'online'));
    $coffee_count   = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$bookings_table} WHERE meeting_type = %s", 'coffee'));
    $office_count   = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$bookings_table} WHERE meeting_type = %s", 'office'));
    $host_count     = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$bookings_table} WHERE meeting_type = %s", 'host'));

    $sql = "SELECT b.*, l.survey_id, l.answers_json, l.lead_score
            FROM {$bookings_table} b
            LEFT JOIN {$leads_table} l ON b.lead_id = l.id
            WHERE {$where_sql}
            ORDER BY {$order_sql}
            LIMIT 200";

    if (!empty($params)) {
        $sql = $wpdb->prepare($sql, $params);
    }

    $bookings = $wpdb->get_results($sql, ARRAY_A) ?: array();

    ?>
    <div class="wrap mc-leads-engine-admin">
        <!-- Top Bar -->
        <div class="main" style="background: transparent; box-shadow: none; border-radius: 0;">
            <div class="topbar" style="padding: 14px 0; background: transparent; border-bottom: 1px solid var(--mc-border); margin-bottom: 20px;">
                <div>
                    <div class="topbar-title" style="font-size: 20px; font-weight: 800;"><?php esc_html_e('Bookings', 'mc-leads-engine'); ?></div>
                    <div class="topbar-sub" style="font-size: 11px; color: var(--mc-muted);"><?php esc_html_e('Scheduled meetings · synced with Google Calendar', 'mc-leads-engine'); ?></div>
                </div>
                <?php 
                $export_url = add_query_arg(array(
                    'page' => 'mc-leads-engine-bookings',
                    'meeting_type' => $filter_type,
                    'status' => $filter_status,
                    'orderby' => $orderby,
                    'order' => $order,
                    'export_bookings' => 1,
                    '_wpnonce' => wp_create_nonce('mc_leads_engine_export_bookings')
                ), admin_url('admin.php'));
                ?>
                <a class="btn" href="<?php echo esc_url($export_url); ?>">
                    <span class="dashicons dashicons-media-spreadsheet" style="vertical-align:middle; font-size:16px; margin-right:4px;"></span>
                    <?php esc_html_e('Export to Excel', 'mc-leads-engine'); ?>
                </a>
            </div>

            <!-- Booking Analytics Cards -->
            <div class="stat-grid" style="margin-bottom:20px;">
                <div class="stat-card">
                    <div class="stat-label"><span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e('Total Reservations', 'mc-leads-engine'); ?></div>
                    <div class="stat-value"><?php echo esc_html(number_format_i18n($total_bookings)); ?></div>
                    <div class="stat-delta"><?php esc_html_e('All-time meetings', 'mc-leads-engine'); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><span class="dashicons dashicons-desktop"></span> <?php esc_html_e('Online Video Calls', 'mc-leads-engine'); ?></div>
                    <div class="stat-value"><?php echo esc_html(number_format_i18n($online_count)); ?></div>
                    <div class="stat-delta"><?php esc_html_e('Google Meet / Zoom', 'mc-leads-engine'); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><span class="dashicons dashicons-coffee"></span> <?php esc_html_e('Coffee Meetings', 'mc-leads-engine'); ?></div>
                    <div class="stat-value"><?php echo esc_html(number_format_i18n($coffee_count)); ?></div>
                    <div class="stat-delta"><?php esc_html_e('Out-of-office meetings', 'mc-leads-engine'); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><span class="dashicons dashicons-building"></span> <?php esc_html_e('Office Visits', 'mc-leads-engine'); ?></div>
                    <div class="stat-value"><?php echo esc_html(number_format_i18n($office_count)); ?></div>
                    <div class="stat-delta"><?php esc_html_e('Client office visits', 'mc-leads-engine'); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><span class="dashicons dashicons-admin-home"></span> <?php esc_html_e('At Our Studio', 'mc-leads-engine'); ?></div>
                    <div class="stat-value"><?php echo esc_html(number_format_i18n($host_count)); ?></div>
                    <div class="stat-delta"><?php esc_html_e('Memories Creative Studio', 'mc-leads-engine'); ?></div>
                </div>
            </div>

            <!-- Filter Bar -->
            <form method="get" class="filter-bar" style="margin-bottom: 20px;">
                <input type="hidden" name="page" value="mc-leads-engine-bookings">
                <input type="hidden" name="orderby" value="<?php echo esc_attr($orderby); ?>">
                <input type="hidden" name="order" value="<?php echo esc_attr($order); ?>">

                <div class="filter-field">
                    <label><?php esc_html_e('Meeting format', 'mc-leads-engine'); ?></label>
                    <select class="filter-select" name="meeting_type">
                        <option value="all" <?php selected($filter_type, 'all'); ?>><?php esc_html_e('All Formats', 'mc-leads-engine'); ?></option>
                        <option value="online" <?php selected($filter_type, 'online'); ?>><?php esc_html_e('Online Call', 'mc-leads-engine'); ?></option>
                        <option value="coffee" <?php selected($filter_type, 'coffee'); ?>><?php esc_html_e('Coffee Meeting', 'mc-leads-engine'); ?></option>
                        <option value="office" <?php selected($filter_type, 'office'); ?>><?php esc_html_e('Office Visit', 'mc-leads-engine'); ?></option>
                        <option value="host" <?php selected($filter_type, 'host'); ?>><?php esc_html_e('Our Studio', 'mc-leads-engine'); ?></option>
                    </select>
                </div>

                <div class="filter-field">
                    <label><?php esc_html_e('Date / status', 'mc-leads-engine'); ?></label>
                    <select class="filter-select" name="status">
                        <option value="all" <?php selected($filter_status, 'all'); ?>><?php esc_html_e('All Bookings', 'mc-leads-engine'); ?></option>
                        <option value="upcoming" <?php selected($filter_status, 'upcoming'); ?>><?php esc_html_e('Upcoming Meetings', 'mc-leads-engine'); ?></option>
                        <option value="past" <?php selected($filter_status, 'past'); ?>><?php esc_html_e('Past Meetings', 'mc-leads-engine'); ?></option>
                    </select>
                </div>

                <div class="filter-spacer"></div>
                <button class="btn primary" type="submit"><?php esc_html_e('Apply Filters', 'mc-leads-engine'); ?></button>
            </form>

            <!-- Table Panel -->
            <div class="panel">
                <div class="panel-header" style="display:flex; justify-content:space-between; align-items:baseline; padding: 15px 20px;">
                    <div class="panel-title"><?php esc_html_e('Scheduled meetings', 'mc-leads-engine'); ?></div>
                    <div class="panel-sub"><?php echo esc_html(sprintf(_n('%d booking', '%d bookings', count($bookings), 'mc-leads-engine'), count($bookings))); ?></div>
                </div>

                <?php
                $sort_id_url = add_query_arg(array(
                    'orderby' => 'id',
                    'order'   => ($orderby === 'id' && $order === 'DESC') ? 'ASC' : 'DESC',
                ));
                $sort_date_url = add_query_arg(array(
                    'orderby' => 'date',
                    'order'   => ($orderby === 'date' && $order === 'DESC') ? 'ASC' : 'DESC',
                ));
                ?>

                <div class="table-wrap">
                    <table class="book-table">
                        <thead>
                            <tr>
                                <th><a href="<?php echo esc_url($sort_id_url); ?>" style="text-decoration:none; color:inherit;">
                                    <?php esc_html_e('ID', 'mc-leads-engine'); ?>
                                    <?php if ($orderby === 'id') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:12px; width:12px; height:12px; vertical-align:middle;"></span><?php endif; ?>
                                </a></th>
                                <th><a href="<?php echo esc_url($sort_date_url); ?>" style="text-decoration:none; color:inherit;">
                                    <?php esc_html_e('Date & time', 'mc-leads-engine'); ?>
                                    <?php if ($orderby === 'date') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:12px; width:12px; height:12px; vertical-align:middle;"></span><?php endif; ?>
                                </a></th>
                                <th><?php esc_html_e('Format', 'mc-leads-engine'); ?></th>
                                <th><?php esc_html_e('Location', 'mc-leads-engine'); ?></th>
                                <th><?php esc_html_e('Client', 'mc-leads-engine'); ?></th>
                                <th><?php esc_html_e('Score', 'mc-leads-engine'); ?></th>
                                <th><?php esc_html_e('Calendar sync', 'mc-leads-engine'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($bookings)) : ?>
                            <tr>
                                <td colspan="7" style="text-align: center; font-style: italic; color: var(--mc-muted); padding: 30px;">
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
                                
                                $emojis = array(
                                    'online' => '💻',
                                    'coffee' => '☕',
                                    'office' => '🏢',
                                    'host'   => '🎨',
                                );
                                $emoji = $emojis[$row['meeting_type']] ?? '📅';

                                // Check status
                                $meeting_dt = strtotime($row['meeting_date'] . ' ' . $row['meeting_time']);
                                $is_past = $meeting_dt < current_time('timestamp');
                                $pill_class = $is_past ? 'past' : 'upcoming';
                                $pill_lbl = $is_past ? __('Past', 'mc-leads-engine') : __('Upcoming', 'mc-leads-engine');
                                
                                // Score band
                                $score = $row['lead_score'] !== null ? (int)$row['lead_score'] : 0;
                                $score_class = $score >= 80 ? 'score-hot' : 'score-cold';
                            ?>
                                <tr>
                                    <td><span class="book-id">#<?php echo esc_html($row['id']); ?></span></td>
                                    <td>
                                        <div class="dt-date"><?php echo esc_html(wp_date('M j, Y', $meeting_dt)); ?></div>
                                        <div class="dt-time"><?php echo esc_html(wp_date('g:i A', $meeting_dt)); ?></div>
                                        <span class="time-pill <?php echo esc_attr($pill_class); ?>"><?php echo esc_html($pill_lbl); ?></span>
                                    </td>
                                    <td><span class="meeting-type"><span class="type-emoji"><?php echo esc_html($emoji); ?></span> <?php echo esc_html($type_lbl); ?></span></td>
                                    <td>
                                        <div class="location-name"><?php echo esc_html($row['location_name'] ?: __('No location name', 'mc-leads-engine')); ?></div>
                                        <div class="location-sub"><?php echo esc_html($row['location_address'] ?: '—'); ?></div>
                                    </td>
                                    <td>
                                        <div class="client-name"><?php echo esc_html($name ?: __('(No name)', 'mc-leads-engine')); ?></div>
                                        <?php if ($email) : ?><div class="client-line"><?php echo esc_html($email); ?></div><?php endif; ?>
                                        <?php if ($phone) : ?><div class="client-line"><?php echo esc_html($phone); ?></div><?php endif; ?>
                                        <a class="view-lead-link" href="<?php echo esc_url(add_query_arg(array('page' => 'mc-leads-engine-leads', 'lead_id' => $lead_id), admin_url('admin.php'))); ?>"><?php esc_html_e('View lead', 'mc-leads-engine'); ?></a>
                                    </td>
                                    <td><span class="score-badge <?php echo esc_attr($score_class); ?>"><?php echo esc_html($score); ?></span></td>
                                    <td>
                                        <?php if (!empty($row['calendar_event_id'])) : ?>
                                            <div class="cal-sync"><span class="cal-check">✓</span> <?php esc_html_e('Synced', 'mc-leads-engine'); ?></div>
                                            <code style="font-size: 10px;"><?php echo esc_html(substr($row['calendar_event_id'], 0, 12)); ?>...</code>
                                        <?php else : ?>
                                            <span class="description" style="color:var(--mc-muted);"><?php esc_html_e('Not Synced', 'mc-leads-engine'); ?></span>
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
    </div>
    <?php
}
