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

    $is_showing_demo = false;
    if (empty($bookings) && $filter_type === 'all' && $filter_status === 'all') {
        $is_showing_demo = true;
        $dummy_bookings = array(
            array(
                'id' => 28,
                'lead_id' => 32,
                'meeting_date' => '2026-06-26',
                'meeting_time' => '13:30:00',
                'meeting_type' => 'online',
                'location_name' => 'Google Meet / Zoom',
                'location_address' => 'Online Call Link',
                'calendar_event_id' => '1gk5k5e9kcjq...',
                'lead_score' => 260,
                'client_name' => 'bensam',
                'client_email' => 'bensammwaniki@gmail.com',
                'client_phone' => '743491012',
                'is_demo' => true
            ),
            array(
                'id' => 27,
                'lead_id' => 31,
                'meeting_date' => '2026-06-26',
                'meeting_time' => '12:45:00',
                'meeting_type' => 'online',
                'location_name' => 'Google Meet / Zoom',
                'location_address' => 'Online Call Link',
                'calendar_event_id' => 'jdjdlf6odcff...',
                'lead_score' => 10,
                'client_name' => 'Bensam',
                'client_email' => 'bensammwaniki@gmail.com',
                'client_phone' => '743491012',
                'is_demo' => true
            ),
            array(
                'id' => 26,
                'lead_id' => 30,
                'meeting_date' => '2026-06-26',
                'meeting_time' => '12:00:00',
                'meeting_type' => 'online',
                'location_name' => 'Google Meet / Zoom',
                'location_address' => 'Online Call Link',
                'calendar_event_id' => 'hbpedse5eunb...',
                'lead_score' => 10,
                'client_name' => 'Bensam',
                'client_email' => 'bensammwaniki@gmail.com',
                'client_phone' => '743491012',
                'is_demo' => true
            )
        );
        $bookings = $dummy_bookings;
        $total_bookings = 3;
        $online_count = 3;
        $coffee_count = 0;
        $office_count = 0;
        $host_count = 0;
    }
    ?>
    <div class="wrap mc-leads-engine-admin">
        <!-- Top Bar -->
        <div class="topbar">
            <div>
                <div class="topbar-title"><?php esc_html_e('Bookings', 'mc-leads-engine'); ?></div>
                <div class="topbar-sub"><?php esc_html_e('Scheduled meetings · synced with Google Calendar', 'mc-leads-engine'); ?></div>
            </div>
            <div>
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
        </div>

        <!-- Booking Analytics Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <span class="kicon">☰</span>
                <div class="kpi-value"><?php echo esc_html(number_format_i18n($total_bookings)); ?></div>
                <div class="kpi-label"><?php esc_html_e('Total Reservations', 'mc-leads-engine'); ?></div>
                <div class="kpi-delta"><?php esc_html_e('All-time meetings', 'mc-leads-engine'); ?></div>
            </div>
            <div class="kpi-card">
                <span class="kicon">🖥</span>
                <div class="kpi-value"><?php echo esc_html(number_format_i18n($online_count)); ?></div>
                <div class="kpi-label"><?php esc_html_e('Online Video Calls', 'mc-leads-engine'); ?></div>
                <div class="kpi-delta"><?php esc_html_e('Google Meet / Zoom', 'mc-leads-engine'); ?></div>
            </div>
            <div class="kpi-card money">
                <span class="kicon">☕</span>
                <div class="kpi-value"><?php echo esc_html(number_format_i18n($coffee_count)); ?></div>
                <div class="kpi-label"><?php esc_html_e('Coffee Meetings', 'mc-leads-engine'); ?></div>
                <div class="kpi-delta"><?php esc_html_e('Out-of-office meetings', 'mc-leads-engine'); ?></div>
            </div>
            <div class="kpi-card accent">
                <span class="kicon">🏢</span>
                <div class="kpi-value"><?php echo esc_html(number_format_i18n($office_count)); ?></div>
                <div class="kpi-label"><?php esc_html_e('Office / Studio', 'mc-leads-engine'); ?></div>
                <div class="kpi-delta"><?php echo esc_html(sprintf(__('Studio: %d', 'mc-leads-engine'), $host_count)); ?></div>
            </div>
        </div>

        <!-- Filter Bar -->
        <form method="get" class="filter-bar">
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

        <?php if ($is_showing_demo) : ?>
            <div style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e3a8a; padding:12px 16px; margin-bottom:20px; border-radius:var(--radius); font-size:12.5px; display:flex; align-items:center; gap:8px;">
                <span style="font-size:16px;">💡</span>
                <span><?php esc_html_e('Showing demo bookings since no scheduled reservations have been made yet.', 'mc-leads-engine'); ?></span>
            </div>
        <?php endif; ?>

        <!-- Table Panel -->
        <div class="panel">
            <div class="panel-header">
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
                <table class="dtable">
                    <thead>
                        <tr>
                            <th><a href="<?php echo esc_url($sort_id_url); ?>">
                                <?php esc_html_e('ID', 'mc-leads-engine'); ?>
                                <?php if ($orderby === 'id') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:12px; width:12px; height:12px; vertical-align:middle;"></span><?php endif; ?>
                            </a></th>
                            <th><a href="<?php echo esc_url($sort_date_url); ?>">
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
                            <td colspan="7" style="text-align: center; font-style: italic; color: var(--muted); padding: 30px;">
                                <?php esc_html_e('No bookings matched your filters.', 'mc-leads-engine'); ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($bookings as $row) : 
                            $lead_id = (int)$row['lead_id'];
                            $name = !empty($row['is_demo']) ? $row['client_name']  : mc_leads_engine_leads_repository()->find_client_name($lead_id);
                            $email = !empty($row['is_demo']) ? $row['client_email'] : mc_leads_engine_leads_repository()->find_client_email($lead_id);
                            $phone = !empty($row['is_demo']) ? $row['client_phone'] : mc_leads_engine_leads_repository()->find_client_phone($lead_id);

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
                            $pill_lbl = $is_past ? __('Past', 'mc-leads-engine') : __('Upcoming', 'mc-leads-engine');
                            
                            // Score band
                            $score = $row['lead_score'] !== null ? (int)$row['lead_score'] : 0;
                            $score_band = mc_leads_score_band($score);
                        ?>
                            <tr>
                                <td class="mono-id">#<?php echo esc_html($row['id']); ?></td>
                                <td class="cell-date">
                                    <?php echo esc_html(wp_date('Y-m-d', $meeting_dt)); ?><br>
                                    <?php echo esc_html(wp_date('H:i', $meeting_dt)); ?> 
                                    <span style="font-size:10px; opacity:0.8; padding:1px 5px; border-radius:10px; font-weight:700; margin-left:4px;" class="<?php echo $is_past ? 'status-pill status-lost' : 'status-pill status-new'; ?>"><?php echo esc_html($pill_lbl); ?></span>
                                </td>
                                <td>
                                    <span style="font-size:13px; font-weight:600; display:inline-flex; align-items:center; gap:5px;">
                                        <span style="font-size:14px;"><?php echo esc_html($emoji); ?></span> 
                                        <?php echo esc_html($type_lbl); ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-weight:700; color:var(--text);"><?php echo esc_html($row['location_name'] ?: __('No location name', 'mc-leads-engine')); ?></div>
                                    <div style="font-size:11px; color:var(--muted);"><?php echo esc_html($row['location_address'] ?: '—'); ?></div>
                                </td>
                                <td>
                                    <div class="client-name"><?php echo esc_html($name ?: __('(No name)', 'mc-leads-engine')); ?></div>
                                    <?php if ($email) : ?><div class="client-line"><?php echo esc_html($email); ?></div><?php endif; ?>
                                    <?php if ($phone) : ?><div class="client-line"><?php echo esc_html($phone); ?></div><?php endif; ?>
                                    <a style="font-size:11px; color:var(--coral); font-weight:700; text-decoration:none;" href="<?php echo esc_url(add_query_arg(array('page' => 'mc-leads-engine-leads', 'lead_id' => $lead_id), admin_url('admin.php'))); ?>"><?php esc_html_e('View lead →', 'mc-leads-engine'); ?></a>
                                </td>
                                <td><span class="score-badge score-<?php echo esc_attr($score_band); ?>"><?php echo esc_html($score); ?></span></td>
                                <td>
                                    <?php if (!empty($row['calendar_event_id'])) : ?>
                                        <span style="color:#22c55e; font-weight:700; font-size:11.5px;">✓ Synced</span><br>
                                        <code style="font-size: 10px;"><?php echo esc_html(substr($row['calendar_event_id'], 0, 12)); ?>...</code>
                                    <?php else : ?>
                                        <span class="description" style="color:var(--muted);"><?php esc_html_e('Not Synced', 'mc-leads-engine'); ?></span>
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
