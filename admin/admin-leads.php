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
    $lead_id   = absint($_GET['lead_id'] ?? 0);
    $search    = sanitize_text_field($_GET['search'] ?? '');
    $paged     = max(1, absint($_GET['paged'] ?? 1));
    $per_page  = 50;
    $offset    = ($paged - 1) * $per_page;
    $orderby   = sanitize_key($_GET['orderby'] ?? 'created_at');
    $order     = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

    $allowed_orderby = array('id', 'created_at', 'lead_score', 'total_price');
    if (!in_array($orderby, $allowed_orderby, true)) {
        $orderby = 'created_at';
    }

    // ─── XLSX Export ─────────────────────────────────────────────────────────
    if (!empty($_GET['export'])) {
        check_admin_referer('mc_leads_engine_export_leads');
        $rows = mc_leads_engine_leads_repository()->export_rows(array(
            'survey_id' => $survey_id,
            'min_score' => $min_score,
            'search'    => $search,
            'limit'     => 10000,
            'orderby'   => $orderby,
            'order'     => $order,
        ));

        global $wpdb;
        $questions_rows = $wpdb->get_results("SELECT id, question_text FROM " . mc_leads_engine_table('survey_questions'), ARRAY_A);
        $questions_map  = array();
        if (is_array($questions_rows)) {
            foreach ($questions_rows as $q) {
                $questions_map[(int) $q['id']] = $q['question_text'];
            }
        }

        $headers = array(
            __('Lead ID', 'mc-leads-engine'),
            __('Created Date', 'mc-leads-engine'),
            __('Status', 'mc-leads-engine'),
            __('Survey Title', 'mc-leads-engine'),
            __('Client Name', 'mc-leads-engine'),
            __('Client Email', 'mc-leads-engine'),
            __('Client Phone', 'mc-leads-engine'),
            __('Submitted Answers', 'mc-leads-engine'),
            __('Estimated Price', 'mc-leads-engine'),
            __('Lead Score', 'mc-leads-engine'),
        );

        $col_types      = array('text', 'text', 'text', 'text', 'text', 'text', 'text', 'text', 'price', 'score');
        $col_alignments = array('center', 'left', 'left', 'left', 'left', 'left', 'left', 'left', 'right', 'center');

        $export_data = array();
        foreach ($rows as $row) {
            $survey_row   = mc_leads_engine_survey_repository()->get_survey($row['survey_id']);
            $is_booking   = mc_leads_is_booking($row);
            $survey_title = $is_booking ? __('Bookings', 'mc-leads-engine') : ($survey_row['title'] ?? $row['survey_id']);

            $name  = mc_leads_engine_leads_repository()->find_client_name($row['id']);
            $email = mc_leads_engine_leads_repository()->find_client_email($row['id']);
            $phone = mc_leads_engine_leads_repository()->find_client_phone($row['id']);

            $answer_items    = mc_leads_engine_leads_repository()->build_answers_summary($row, $questions_map);
            $answers_summary = implode("\n", $answer_items);

            $export_data[] = array(
                $row['id'],
                $row['created_at'],
                mc_leads_status_label($row['status'] ?? 'new'),
                $survey_title,
                $name,
                $email,
                $phone,
                $answers_summary,
                (float) $row['total_price'],
                (int) $row['lead_score'],
            );
        }

        $writer = new MC_Leads_Engine_XLSX_Writer('Leads');
        $writer->set_headers($headers);
        $writer->set_rows($export_data);
        $writer->set_col_types($col_types);
        $writer->set_col_alignments($col_alignments);
        $writer->write_to_output('mc-leads-engine-leads.xlsx');
    }

    // ─── Data ─────────────────────────────────────────────────────────────────
    $leads   = mc_leads_engine_leads_repository()->get_leads(array(
        'survey_id' => $survey_id,
        'min_score' => $min_score,
        'search'    => $search,
        'limit'     => $per_page,
        'offset'    => $offset,
        'orderby'   => $orderby,
        'order'     => $order,
    ));
    $total_leads = mc_leads_engine_leads_repository()->count_leads(array(
        'survey_id' => $survey_id,
        'min_score' => $min_score,
        'search'    => $search,
    ));
    $total_pages = (int) ceil($total_leads / $per_page);

    $lead        = $lead_id ? mc_leads_engine_leads_repository()->get_lead($lead_id) : null;
    $lead_cf7    = $lead_id ? mc_leads_engine_leads_repository()->get_cf7_data($lead_id) : array();
    $surveys     = mc_leads_engine_survey_repository()->get_surveys(array('limit' => 100));

    // ─── Sort URL helpers ─────────────────────────────────────────────────────
    $sort_id_url    = add_query_arg(array('orderby' => 'id',         'order' => ($orderby === 'id'         && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
    $sort_price_url = add_query_arg(array('orderby' => 'total_price','order' => ($orderby === 'total_price' && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
    $sort_score_url = add_query_arg(array('orderby' => 'lead_score', 'order' => ($orderby === 'lead_score'  && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
    $sort_date_url  = add_query_arg(array('orderby' => 'created_at', 'order' => ($orderby === 'created_at'  && $order === 'DESC') ? 'ASC' : 'DESC', 'paged' => 1));
    ?>
    <div class="wrap mc-leads-engine-admin">
        <h1><?php esc_html_e('Leads', 'mc-leads-engine'); ?></h1>

        <!-- Filter / Search Form -->
        <form method="get" class="mc-inline-form">
            <input type="hidden" name="page"    value="mc-leads-engine-leads">
            <input type="hidden" name="orderby" value="<?php echo esc_attr($orderby); ?>">
            <input type="hidden" name="order"   value="<?php echo esc_attr($order); ?>">
            <input type="hidden" name="paged"   value="1">

            <label>
                <?php esc_html_e('Survey', 'mc-leads-engine'); ?>
                <select name="survey_id">
                    <option value="0"><?php esc_html_e('All surveys', 'mc-leads-engine'); ?></option>
                    <?php foreach ($surveys as $survey) : ?>
                        <option value="<?php echo esc_attr($survey['id']); ?>" <?php selected($survey_id, $survey['id']); ?>>
                            <?php echo esc_html($survey['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <?php esc_html_e('Min Score', 'mc-leads-engine'); ?>
                <input type="number" name="min_score" value="<?php echo esc_attr($min_score); ?>" min="0" style="width:70px">
            </label>

            <label>
                <?php esc_html_e('Search', 'mc-leads-engine'); ?>
                <input type="text" name="search" value="<?php echo esc_attr($search); ?>"
                       placeholder="<?php esc_attr_e('Name, email or phone…', 'mc-leads-engine'); ?>"
                       style="width:200px">
            </label>

            <button class="button" type="submit"><?php esc_html_e('Filter', 'mc-leads-engine'); ?></button>

            <?php wp_nonce_field('mc_leads_engine_export_leads'); ?>
            <a class="button button-secondary"
               href="<?php echo esc_url(add_query_arg(array(
                   'page'       => 'mc-leads-engine-leads',
                   'survey_id'  => $survey_id,
                   'min_score'  => $min_score,
                   'search'     => $search,
                   'orderby'    => $orderby,
                   'order'      => $order,
                   'export'     => 1,
                   '_wpnonce'   => wp_create_nonce('mc_leads_engine_export_leads'),
               ), admin_url('admin.php'))); ?>">
                <?php esc_html_e('Export to Excel', 'mc-leads-engine'); ?>
            </a>
        </form>

        <!-- ─── Lead Profile (detail view) ──────────────────────────────────── -->
        <?php if ($lead) :
            $repo          = mc_leads_engine_leads_repository();
            $name          = $repo->find_client_name($lead_id);
            $email         = $repo->find_client_email($lead_id);
            $phone         = $repo->find_client_phone($lead_id);
            $is_booking    = mc_leads_is_booking($lead);
            $survey_row    = mc_leads_engine_survey_repository()->get_survey($lead['survey_id']);
            $band          = mc_leads_score_band($lead['lead_score']);
            $device        = mc_leads_parse_device($lead['user_agent'] ?? '');
            $device_icon   = mc_leads_device_icon($device);
            $status        = $lead['status'] ?? 'new';
            $activity_log  = MC_Leads_Activity::get_log($lead_id);

            // Build clean phone for WhatsApp link
            $clean_phone = preg_replace('/[^0-9]/', '', $phone ?? '');
            $wa_link     = $clean_phone ? 'https://wa.me/' . $clean_phone : '';

            global $wpdb;
            $questions_rows = $wpdb->get_results("SELECT id, question_text FROM " . mc_leads_engine_table('survey_questions'), ARRAY_A);
            $questions_map  = array();
            if (is_array($questions_rows)) {
                foreach ($questions_rows as $q) {
                    $questions_map[(int) $q['id']] = $q['question_text'];
                }
            }
            $answer_items = $repo->build_answers_summary($lead, $questions_map, true);
            $booking_row  = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . mc_leads_engine_table('bookings') . " WHERE lead_id = %d LIMIT 1",
                $lead_id
            ), ARRAY_A);
        ?>
        <div class="mc-lead-profile">

            <!-- Header -->
            <div class="mc-lead-profile-header mc-panel">
                <div class="mc-lead-profile-avatar">
                    <span class="dashicons dashicons-admin-users"></span>
                </div>
                <div class="mc-lead-profile-info">
                    <h2 class="mc-lead-profile-name">
                        <?php echo esc_html($name ?: __('(No name)', 'mc-leads-engine')); ?>
                        <span class="mc-lead-id">#<?php echo esc_html($lead_id); ?></span>
                    </h2>
                    <div class="mc-lead-profile-contacts">
                        <?php if ($email) : ?>
                            <span><span class="dashicons dashicons-email-alt"></span>
                            <a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a></span>
                        <?php endif; ?>
                        <?php if ($phone) : ?>
                            <span><span class="dashicons dashicons-phone"></span> <?php echo esc_html($phone); ?></span>
                            <?php if ($wa_link) : ?>
                                <a class="mc-wa-btn" href="<?php echo esc_url($wa_link); ?>" target="_blank" rel="noopener">
                                    <?php esc_html_e('WhatsApp', 'mc-leads-engine'); ?> &rarr;
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="mc-lead-profile-meta">
                        <span class="dashicons <?php echo esc_attr($device_icon); ?>"></span> <?php echo esc_html(ucfirst($device)); ?>
                        &nbsp;&bull;&nbsp;
                        <?php echo esc_html(sprintf(__('Submitted %s', 'mc-leads-engine'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($lead['created_at'])))); ?>
                        &nbsp;&bull;&nbsp;
                        <?php echo esc_html($is_booking ? __('Booking', 'mc-leads-engine') : ($survey_row['title'] ?? __('Survey', 'mc-leads-engine'))); ?>
                    </div>
                </div>
                <div class="mc-lead-profile-badges">
                    <?php echo mc_leads_score_badge($lead['lead_score']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <div class="mc-lead-price-badge">KES <?php echo esc_html(number_format((float) $lead['total_price'], 2)); ?></div>
                </div>
            </div>

            <div class="mc-lead-profile-body">

                <!-- Left column -->
                <div class="mc-lead-profile-main">

                    <!-- Pipeline status -->
                    <div class="mc-panel mc-lead-status-panel">
                        <h3><?php esc_html_e('Pipeline Status', 'mc-leads-engine'); ?></h3>
                        <div class="mc-status-selector">
                            <?php foreach (mc_leads_get_statuses() as $s) : ?>
                                <button type="button"
                                    class="mc-status-btn<?php echo $s === $status ? ' active' : ''; ?>"
                                    data-status="<?php echo esc_attr($s); ?>"
                                    data-lead="<?php echo esc_attr($lead_id); ?>">
                                    <?php echo esc_html(mc_leads_status_label($s)); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($lead['status_notes'])) : ?>
                            <p class="mc-status-note"><?php echo esc_html($lead['status_notes']); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Survey answers -->
                    <div class="mc-panel">
                        <h3><?php esc_html_e('Survey Answers', 'mc-leads-engine'); ?></h3>
                        <?php if (!empty($answer_items)) : ?>
                            <ul class="mc-answers-list">
                                <?php foreach ($answer_items as $item) : ?>
                                    <li><?php echo $item; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else : ?>
                            <p class="description"><?php esc_html_e('No answers recorded.', 'mc-leads-engine'); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Booking details -->
                    <?php if ($booking_row) : ?>
                    <div class="mc-panel">
                        <h3><?php esc_html_e('Booking Details', 'mc-leads-engine'); ?></h3>
                        <table class="widefat striped">
                            <tr><th><?php esc_html_e('Type', 'mc-leads-engine'); ?></th><td><?php echo esc_html($booking_row['meeting_type']); ?></td></tr>
                            <tr><th><?php esc_html_e('Date', 'mc-leads-engine'); ?></th><td><?php echo esc_html($booking_row['meeting_date']); ?></td></tr>
                            <tr><th><?php esc_html_e('Time', 'mc-leads-engine'); ?></th><td><?php echo esc_html($booking_row['meeting_time']); ?></td></tr>
                            <tr><th><?php esc_html_e('Location', 'mc-leads-engine'); ?></th><td><?php echo esc_html($booking_row['location_name'] . ' ' . $booking_row['location_address']); ?></td></tr>
                        </table>
                    </div>
                    <?php endif; ?>

                </div>

                <!-- Right column -->
                <div class="mc-lead-profile-sidebar">

                    <!-- Attribution -->
                    <?php if (!empty($lead['utm_source']) || !empty($lead['referrer'])) : ?>
                    <div class="mc-panel">
                        <h3><?php esc_html_e('Traffic Attribution', 'mc-leads-engine'); ?></h3>
                        <table class="widefat">
                            <?php if (!empty($lead['utm_source'])) : ?>
                                <tr><th><?php esc_html_e('Source', 'mc-leads-engine'); ?></th><td><?php echo esc_html($lead['utm_source']); ?></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($lead['utm_medium'])) : ?>
                                <tr><th><?php esc_html_e('Medium', 'mc-leads-engine'); ?></th><td><?php echo esc_html($lead['utm_medium']); ?></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($lead['utm_campaign'])) : ?>
                                <tr><th><?php esc_html_e('Campaign', 'mc-leads-engine'); ?></th><td><?php echo esc_html($lead['utm_campaign']); ?></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($lead['referrer'])) : ?>
                                <tr><th><?php esc_html_e('Referrer', 'mc-leads-engine'); ?></th>
                                    <td><a href="<?php echo esc_url($lead['referrer']); ?>" target="_blank" rel="noopener"><?php echo esc_html($lead['referrer']); ?></a></td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                    <?php endif; ?>

                    <!-- Activity log + notes -->
                    <div class="mc-panel mc-activity-panel">
                        <h3><?php esc_html_e('Activity Log', 'mc-leads-engine'); ?></h3>

                        <!-- Add note form -->
                        <div class="mc-add-note-form">
                            <textarea id="mc-note-input" rows="2"
                                placeholder="<?php esc_attr_e('Add a note…', 'mc-leads-engine'); ?>"></textarea>
                            <button type="button" class="button button-primary" id="mc-add-note-btn"
                                data-lead="<?php echo esc_attr($lead_id); ?>">
                                <?php esc_html_e('Add Note', 'mc-leads-engine'); ?>
                            </button>
                        </div>

                        <!-- Timeline -->
                        <ul class="mc-activity-timeline" id="mc-activity-timeline">
                            <?php foreach ($activity_log as $entry) :
                                $icon = MC_Leads_Activity::get_icon($entry['activity_type']);
                                $type = MC_Leads_Activity::get_type_label($entry['activity_type']);
                            ?>
                                <li class="mc-activity-item">
                                    <span class="mc-activity-icon dashicons <?php echo esc_attr($icon); ?>"></span>
                                    <div class="mc-activity-content">
                                        <span class="mc-activity-type"><?php echo esc_html($type); ?></span>
                                        <span class="mc-activity-time">
                                            <?php echo esc_html(date_i18n(get_option('date_format') . ' H:i', strtotime($entry['created_at']))); ?>
                                            <?php if (!empty($entry['user_name'])) : ?>
                                                &bull; <?php echo esc_html($entry['user_name']); ?>
                                            <?php endif; ?>
                                        </span>
                                        <?php if (!empty($entry['body'])) : ?>
                                            <p class="mc-activity-body"><?php echo esc_html($entry['body']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                            <?php if (empty($activity_log)) : ?>
                                <li class="mc-activity-empty">
                                    <span class="description"><?php esc_html_e('No activity recorded yet.', 'mc-leads-engine'); ?></span>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                </div><!-- /sidebar -->
            </div><!-- /body -->

            <p><a href="<?php echo esc_url(add_query_arg(array('page' => 'mc-leads-engine-leads', 'survey_id' => $survey_id, 'min_score' => $min_score, 'search' => $search, 'orderby' => $orderby, 'order' => $order, 'paged' => $paged), admin_url('admin.php'))); ?>">&larr; <?php esc_html_e('Back to all leads', 'mc-leads-engine'); ?></a></p>
        </div>
        <?php endif; ?>

        <!-- ─── Leads Table ──────────────────────────────────────────────────── -->
        <div class="mc-panel">
            <table class="widefat striped mc-leads-table">
                <thead>
                    <tr>
                        <th><a href="<?php echo esc_url($sort_id_url); ?>" style="text-decoration:none">
                            <?php esc_html_e('ID', 'mc-leads-engine'); ?>
                            <?php if ($orderby === 'id') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:16px;width:16px;height:16px;vertical-align:middle"></span><?php endif; ?>
                        </a></th>
                        <th><?php esc_html_e('Client', 'mc-leads-engine'); ?></th>
                        <th><?php esc_html_e('Survey', 'mc-leads-engine'); ?></th>
                        <th><?php esc_html_e('Status', 'mc-leads-engine'); ?></th>
                        <th><a href="<?php echo esc_url($sort_price_url); ?>" style="text-decoration:none">
                            <?php esc_html_e('Price', 'mc-leads-engine'); ?>
                            <?php if ($orderby === 'total_price') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:16px;width:16px;height:16px;vertical-align:middle"></span><?php endif; ?>
                        </a></th>
                        <th><a href="<?php echo esc_url($sort_score_url); ?>" style="text-decoration:none">
                            <?php esc_html_e('Score', 'mc-leads-engine'); ?>
                            <?php if ($orderby === 'lead_score') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:16px;width:16px;height:16px;vertical-align:middle"></span><?php endif; ?>
                        </a></th>
                        <th><a href="<?php echo esc_url($sort_date_url); ?>" style="text-decoration:none">
                            <?php esc_html_e('Date', 'mc-leads-engine'); ?>
                            <?php if ($orderby === 'created_at') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:16px;width:16px;height:16px;vertical-align:middle"></span><?php endif; ?>
                        </a></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($leads)) : ?>
                    <tr><td colspan="7" style="text-align:center;padding:24px">
                        <span class="description"><?php esc_html_e('No leads found. Try adjusting your filters.', 'mc-leads-engine'); ?></span>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($leads as $row) :
                    $is_booking  = mc_leads_is_booking($row);
                    $survey_row  = mc_leads_engine_survey_repository()->get_survey($row['survey_id']);
                    $row_name    = mc_leads_engine_leads_repository()->find_client_name($row['id']);
                    $row_email   = mc_leads_engine_leads_repository()->find_client_email($row['id']);
                    $row_status  = $row['status'] ?? 'new';
                    $row_band    = mc_leads_score_band($row['lead_score']);
                    $row_device  = mc_leads_parse_device($row['user_agent'] ?? '');
                    $detail_url  = add_query_arg(array(
                        'page'      => 'mc-leads-engine-leads',
                        'lead_id'   => $row['id'],
                        'survey_id' => $survey_id,
                        'min_score' => $min_score,
                        'search'    => $search,
                        'orderby'   => $orderby,
                        'order'     => $order,
                        'paged'     => $paged,
                    ), admin_url('admin.php'));
                ?>
                    <tr>
                        <td><a href="<?php echo esc_url($detail_url); ?>">#<?php echo esc_html($row['id']); ?></a></td>
                        <td>
                            <?php if ($row_name) : ?>
                                <strong><?php echo esc_html($row_name); ?></strong><br>
                            <?php endif; ?>
                            <?php if ($row_email) : ?>
                                <small><?php echo esc_html($row_email); ?></small>
                            <?php endif; ?>
                            <span class="dashicons <?php echo esc_attr(mc_leads_device_icon($row_device)); ?>" title="<?php echo esc_attr(ucfirst($row_device)); ?>" style="font-size:14px;width:14px;height:14px;vertical-align:middle;color:#94a3b8"></span>
                        </td>
                        <td><?php echo $is_booking ? esc_html__('Bookings', 'mc-leads-engine') : esc_html($survey_row['title'] ?? $row['survey_id']); ?></td>
                        <td><span class="mc-status-pill mc-status-<?php echo esc_attr($row_status); ?>"><?php echo esc_html(mc_leads_status_label($row_status)); ?></span></td>
                        <td><?php echo esc_html(number_format_i18n((float) $row['total_price'], 2)); ?></td>
                        <td><?php echo mc_leads_score_badge($row['lead_score']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                        <td><?php echo esc_html($row['created_at']); ?></td>
                        <td><a href="<?php echo esc_url($detail_url); ?>" class="mc-db-view-btn"><?php esc_html_e('View', 'mc-leads-engine'); ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1) : ?>
            <div class="mc-pagination">
                <?php if ($paged > 1) : ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg('paged', $paged - 1)); ?>">&laquo; <?php esc_html_e('Previous', 'mc-leads-engine'); ?></a>
                <?php endif; ?>
                <span><?php echo esc_html(sprintf(__('Page %d of %d', 'mc-leads-engine'), $paged, $total_pages)); ?></span>
                <?php if ($paged < $total_pages) : ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg('paged', $paged + 1)); ?>"><?php esc_html_e('Next', 'mc-leads-engine'); ?> &raquo;</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
