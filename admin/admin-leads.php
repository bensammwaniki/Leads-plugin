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

    global $wpdb;
    $questions_rows = $wpdb->get_results("SELECT id, question_text FROM " . mc_leads_engine_table('survey_questions'), ARRAY_A);
    $questions_map  = array();
    if (is_array($questions_rows)) {
        foreach ($questions_rows as $q) {
            $questions_map[(int) $q['id']] = $q['question_text'];
        }
    }
    ?>
    <div class="wrap mc-leads-engine-admin">

        <!-- Filter / Search Form -->
        <form method="get" class="filter-bar">
            <input type="hidden" name="page"    value="mc-leads-engine-leads">
            <input type="hidden" name="orderby" value="<?php echo esc_attr($orderby); ?>">
            <input type="hidden" name="order"   value="<?php echo esc_attr($order); ?>">
            <input type="hidden" name="paged"   value="1">

            <div class="filter-field">
                <label><?php esc_html_e('Survey', 'mc-leads-engine'); ?></label>
                <select name="survey_id" class="filter-select">
                    <option value="0"><?php esc_html_e('All Surveys', 'mc-leads-engine'); ?></option>
                    <?php foreach ($surveys as $survey) : ?>
                        <option value="<?php echo esc_attr($survey['id']); ?>" <?php selected($survey_id, $survey['id']); ?>>
                            <?php echo esc_html($survey['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label><?php esc_html_e('Min Score', 'mc-leads-engine'); ?></label>
                <input type="number" name="min_score" value="<?php echo esc_attr($min_score); ?>" min="0" class="filter-select" style="width:80px; padding:0 10px;">
            </div>

            <div class="filter-field">
                <label><?php esc_html_e('Search', 'mc-leads-engine'); ?></label>
                <input type="text" name="search" value="<?php echo esc_attr($search); ?>"
                       placeholder="<?php esc_attr_e('Name, email or phone…', 'mc-leads-engine'); ?>"
                       class="filter-select" style="min-width:200px; padding:0 10px;">
            </div>

            <div class="filter-spacer"></div>

            <button class="btn primary" type="submit"><?php esc_html_e('Apply Filters', 'mc-leads-engine'); ?></button>

            <?php wp_nonce_field('mc_leads_engine_export_leads'); ?>
            <a class="btn"
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
                <span class="dashicons dashicons-media-spreadsheet" style="vertical-align:middle; font-size:16px; margin-right:4px;"></span>
                <?php esc_html_e('Export', 'mc-leads-engine'); ?>
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
            $status        = $lead['status'] ?? 'new';
            $activity_log  = MC_Leads_Activity::get_log($lead_id);

            // Build clean phone for WhatsApp link
            $clean_phone = preg_replace('/[^0-9]/', '', $phone ?? '');
            $wa_link     = $clean_phone ? 'https://wa.me/' . $clean_phone : '';

            $booking_row  = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . mc_leads_engine_table('bookings') . " WHERE lead_id = %d LIMIT 1",
                $lead_id
            ), ARRAY_A);
        ?>
        <div class="mc-lead-profile" style="margin-bottom: 24px;">

            <!-- Back navigation / action top bar -->
            <div class="topbar">
                <a class="back-link" href="<?php echo esc_url(add_query_arg(array('page' => 'mc-leads-engine-leads', 'survey_id' => $survey_id, 'min_score' => $min_score, 'search' => $search, 'orderby' => $orderby, 'order' => $order, 'paged' => $paged), admin_url('admin.php'))); ?>">← <?php esc_html_e('Back to all leads', 'mc-leads-engine'); ?></a>
                <a class="btn primary" href="<?php echo esc_url($wa_link ?: ($email ? 'mailto:' . $email : '#')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Contact lead', 'mc-leads-engine'); ?></a>
            </div>

            <!-- Profile header panel -->
            <div class="panel profile-header">
                <div class="avatar-lg"><?php echo esc_html(strtoupper(substr($name ?: 'L', 0, 1))); ?></div>
                <div style="flex:1;">
                    <div class="profile-name-row" style="display:flex; align-items:center; gap:9px;">
                        <span class="profile-name"><?php echo esc_html($name ?: __('(No name)', 'mc-leads-engine')); ?></span>
                        <span class="profile-id">#<?php echo esc_html($lead_id); ?></span>
                    </div>
                    <div class="profile-contact" style="display:flex; gap:14px; margin-top:5px;">
                        <?php if ($email) : ?>
                            <a href="mailto:<?php echo esc_attr($email); ?>" style="color:var(--muted); text-decoration:none; font-size:12px;">✉ <?php echo esc_html($email); ?></a>
                        <?php endif; ?>
                        <?php if ($phone) : ?>
                            <a href="tel:<?php echo esc_attr($phone); ?>" style="color:var(--muted); text-decoration:none; font-size:12px;">📞 <?php echo esc_html($phone); ?></a>
                            <?php if ($wa_link) : ?>
                                <a href="<?php echo esc_url($wa_link); ?>" target="_blank" rel="noopener" style="color:var(--muted); text-decoration:none; font-size:12px;">💬 WhatsApp</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="profile-meta" style="font-size:11.5px; color:var(--muted-2); margin-top:6px;">
                        <span>🖥 <?php echo esc_html(ucfirst($device)); ?></span>
                        <span class="sep">•</span>
                        <span><?php echo esc_html(sprintf(__('Submitted %s', 'mc-leads-engine'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($lead['created_at'])))); ?></span>
                        <span class="sep">•</span>
                        <span style="font-weight:700; color:var(--text);"><?php echo esc_html($is_booking ? __('Booking', 'mc-leads-engine') : ($survey_row['title'] ?? __('Survey', 'mc-leads-engine'))); ?></span>
                    </div>
                </div>
                <div class="profile-badges">
                    <span class="score-badge-lg <?php echo esc_attr($band); ?>">
                        <?php echo $band === 'hot' ? '🔥' : ($band === 'warm' ? '⚡' : '❄'); ?> 
                        <?php echo esc_html(ucfirst($band)); ?> · <?php echo esc_html($lead['lead_score']); ?>
                    </span>
                    <span class="price-badge-lg">KES <?php echo esc_html(number_format((float) $lead['total_price'])); ?></span>
                </div>
            </div>

            <!-- Body columns -->
            <div class="lead-grid">

                <!-- Main Column -->
                <div class="lead-main">

                    <!-- Pipeline status stepper -->
                    <div class="panel panel-pad">
                        <div class="panel-title"><?php esc_html_e('Pipeline status', 'mc-leads-engine'); ?></div>
                        <div class="stepper">
                            <?php 
                            $statuses = mc_leads_get_statuses();
                            $found_active = false;
                            $step_num = 1;
                            foreach ($statuses as $s) :
                                $is_active = ($s === $status);
                                $btn_class = '';
                                if ($is_active) {
                                    $btn_class = 'active';
                                    $found_active = true;
                                } elseif (!$found_active) {
                                    $btn_class = 'done';
                                }
                            ?>
                                <button type="button" 
                                    class="step-btn mc-status-btn <?php echo esc_attr($btn_class); ?>"
                                    data-status="<?php echo esc_attr($s); ?>"
                                    data-lead="<?php echo esc_attr($lead_id); ?>">
                                    <span class="num"><?php echo $step_num++; ?></span>
                                    <?php echo esc_html(mc_leads_status_label($s)); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($lead['status_notes'])) : ?>
                            <p class="mc-status-note" style="margin-top: 10px; font-size: 11.5px; color: var(--mc-muted);"><?php echo esc_html($lead['status_notes']); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Survey Answers Grid -->
                    <div class="panel panel-pad">
                        <div class="panel-title"><?php esc_html_e('Survey answers', 'mc-leads-engine'); ?></div>
                        <div class="qa-grid">
                            <?php 
                            $has_answers = false;
                            $answers = json_decode($lead['answers_json'] ?? '[]', true);
                            if (is_array($answers)) {
                                foreach ($answers as $q_id => $val) {
                                    $q_text  = $questions_map[(int) $q_id] ?? sprintf(__('Question #%d', 'mc-leads-engine'), $q_id);
                                    $val_str = is_array($val) ? implode(', ', $val) : (string) $val;
                                    if ($val_str !== '') {
                                        $has_answers = true;
                                        ?>
                                        <div class="qa-row">
                                            <div class="qa-q"><?php echo esc_html($q_text); ?></div>
                                            <div class="qa-a"><span class="tag"><?php echo esc_html($val_str); ?></span></div>
                                        </div>
                                        <?php
                                    }
                                }
                            }
                            
                            // CF7 non-contact fields
                            if (!empty($lead_cf7)) {
                                $cf7_data = json_decode($lead_cf7[0]['data_json'] ?? '{}', true);
                                if (is_array($cf7_data)) {
                                    $skip_keys = array('cf7_form_id', 'mc_session_id', 'mc_survey_id', 'survey_data', 'pricing');
                                    foreach ($cf7_data as $key => $val) {
                                        if (empty($val) || in_array($key, $skip_keys, true)) {
                                            continue;
                                        }
                                        $is_booking_key = (strpos($key, 'mc_booking_') === 0 || $key === 'mc_leads_session_id');
                                        $lkey = strtolower($key);
                                        $is_contact = strpos($lkey, 'name') !== false
                                                   || strpos($lkey, 'email') !== false
                                                   || strpos($lkey, 'phone') !== false
                                                   || strpos($lkey, 'tel') !== false
                                                   || strpos($lkey, 'whatsapp') !== false;

                                        if (!$is_booking_key && !$is_contact) {
                                            $val_str = is_array($val) ? implode(', ', $val) : (string) $val;
                                            $has_answers = true;
                                            ?>
                                            <div class="qa-row">
                                                <div class="qa-q meta"><?php echo esc_html($key); ?></div>
                                                <div class="qa-a"><span class="tag"><?php echo esc_html($val_str); ?></span></div>
                                            </div>
                                            <?php
                                        }
                                    }
                                }
                            }

                            if (!$has_answers) : ?>
                                <div style="padding: 20px; text-align: center; color: var(--mc-muted); font-style: italic;"><?php esc_html_e('No answers recorded.', 'mc-leads-engine'); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Booking Details panel if present -->
                    <?php if ($booking_row) : ?>
                    <div class="panel panel-pad">
                        <div class="panel-title"><?php esc_html_e('Booking details', 'mc-leads-engine'); ?></div>
                        <div class="qa-grid">
                            <div class="qa-row">
                                <div class="qa-q"><?php esc_html_e('Meeting Format', 'mc-leads-engine'); ?></div>
                                <div class="qa-a"><span class="tag"><?php echo esc_html(ucfirst($booking_row['meeting_type'])); ?></span></div>
                            </div>
                            <div class="qa-row">
                                <div class="qa-q"><?php esc_html_e('Date & Time', 'mc-leads-engine'); ?></div>
                                <div class="qa-a"><span class="tag"><?php echo esc_html(wp_date('M j, Y', strtotime($booking_row['meeting_date']))); ?> @ <?php echo esc_html($booking_row['meeting_time']); ?></span></div>
                            </div>
                            <div class="qa-row">
                                <div class="qa-q"><?php esc_html_e('Location Name', 'mc-leads-engine'); ?></div>
                                <div class="qa-a"><span class="tag"><?php echo esc_html($booking_row['location_name'] ?: __('No location name', 'mc-leads-engine')); ?></span></div>
                            </div>
                            <div class="qa-row">
                                <div class="qa-q"><?php esc_html_e('Address / Link', 'mc-leads-engine'); ?></div>
                                <div class="qa-a"><span class="tag"><?php echo esc_html($booking_row['location_address'] ?: '—'); ?></span></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>

                <!-- Sidebar column -->
                <div class="lead-sidebar">

                    <!-- Traffic Attribution panel -->
                    <?php if (!empty($lead['utm_source']) || !empty($lead['referrer'])) : ?>
                    <div class="panel panel-pad">
                        <div class="panel-title"><?php esc_html_e('Traffic attribution', 'mc-leads-engine'); ?></div>
                        <?php if (!empty($lead['utm_source'])) : ?>
                            <div class="attr-row">
                                <span class="attr-label"><?php esc_html_e('Source', 'mc-leads-engine'); ?></span>
                                <span class="attr-value"><?php echo esc_html($lead['utm_source']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($lead['utm_medium'])) : ?>
                            <div class="attr-row">
                                <span class="attr-label"><?php esc_html_e('Medium', 'mc-leads-engine'); ?></span>
                                <span class="attr-value"><?php echo esc_html($lead['utm_medium']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($lead['utm_campaign'])) : ?>
                            <div class="attr-row">
                                <span class="attr-label"><?php esc_html_e('Campaign', 'mc-leads-engine'); ?></span>
                                <span class="attr-value"><?php echo esc_html($lead['utm_campaign']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($lead['referrer'])) : ?>
                            <div class="attr-row">
                                <span class="attr-label"><?php esc_html_e('Referrer', 'mc-leads-engine'); ?></span>
                                <span class="attr-value"><a href="<?php echo esc_url($lead['referrer']); ?>" target="_blank" rel="noopener"><?php echo esc_html(parse_url($lead['referrer'], PHP_URL_HOST) ?: $lead['referrer']); ?></a></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Activity Log & notes panel -->
                    <div class="panel panel-pad">
                        <div class="panel-title"><?php esc_html_e('Activity log', 'mc-leads-engine'); ?></div>
                        <div class="note-box">
                            <textarea id="mc-note-input" rows="2" placeholder="<?php esc_attr_e('Add a note…', 'mc-leads-engine'); ?>"></textarea>
                            <button type="button" class="btn primary" id="mc-add-note-btn" data-lead="<?php echo esc_attr($lead_id); ?>"><?php esc_html_e('Add note', 'mc-leads-engine'); ?></button>
                        </div>
                        
                        <ul class="timeline" id="mc-activity-timeline">
                            <?php foreach ($activity_log as $entry) :
                                $type = MC_Leads_Activity::get_type_label($entry['activity_type']);
                                
                                $emoji = '📝';
                                if ($entry['activity_type'] === 'status') {
                                    $emoji = '🔄';
                                } elseif ($entry['activity_type'] === 'booking') {
                                    $emoji = '📅';
                                } elseif ($entry['activity_type'] === 'creation') {
                                    $emoji = '✨';
                                }
                            ?>
                                <li class="timeline-item">
                                    <span class="ic"><?php echo esc_html($emoji); ?></span>
                                    <div class="content">
                                        <div class="head">
                                            <?php echo esc_html($type); ?>
                                            <span class="date"><?php echo esc_html(date_i18n(get_option('date_format') . ' H:i', strtotime($entry['created_at']))); ?></span>
                                        </div>
                                        <?php if (!empty($entry['body'])) : ?>
                                            <p><?php echo esc_html($entry['body']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                            
                            <?php if (empty($activity_log)) : ?>
                                <li class="timeline-empty">
                                    <span class="ic">🕓</span>
                                    <span><?php esc_html_e('No activity recorded yet', 'mc-leads-engine'); ?></span>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                </div>

            </div>

        </div>
        <?php endif; ?>

        <!-- ─── Leads Table ──────────────────────────────────────────────────── -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><?php esc_html_e('All leads', 'mc-leads-engine'); ?></div>
                <div class="panel-sub"><?php echo esc_html(sprintf(_n('%d lead', '%d leads', $total_leads, 'mc-leads-engine'), $total_leads)); ?></div>
            </div>
            <div class="table-wrap">
                <table class="dtable">
                    <thead>
                        <tr>
                            <th><a href="<?php echo esc_url($sort_id_url); ?>">
                                <?php esc_html_e('Lead ID', 'mc-leads-engine'); ?>
                                <?php if ($orderby === 'id') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:16px;width:16px;height:16px;vertical-align:middle"></span><?php endif; ?>
                            </a></th>
                            <th><a href="<?php echo esc_url($sort_date_url); ?>">
                                <?php esc_html_e('Date', 'mc-leads-engine'); ?>
                                <?php if ($orderby === 'created_at') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:16px;width:16px;height:16px;vertical-align:middle"></span><?php endif; ?>
                            </a></th>
                            <th><?php esc_html_e('Survey', 'mc-leads-engine'); ?></th>
                            <th><?php esc_html_e('Status', 'mc-leads-engine'); ?></th>
                            <th><?php esc_html_e('Client', 'mc-leads-engine'); ?></th>
                            <th><?php esc_html_e('Answers', 'mc-leads-engine'); ?></th>
                            <th><a href="<?php echo esc_url($sort_price_url); ?>">
                                <?php esc_html_e('Price', 'mc-leads-engine'); ?>
                                <?php if ($orderby === 'total_price') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:16px;width:16px;height:16px;vertical-align:middle"></span><?php endif; ?>
                            </a></th>
                            <th><a href="<?php echo esc_url($sort_score_url); ?>">
                                <?php esc_html_e('Score', 'mc-leads-engine'); ?>
                                <?php if ($orderby === 'lead_score') : ?><span class="dashicons dashicons-arrow-<?php echo $order === 'ASC' ? 'up' : 'down'; ?>-alt2" style="font-size:16px;width:16px;height:16px;vertical-align:middle"></span><?php endif; ?>
                            </a></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($leads)) : ?>
                        <tr><td colspan="9" style="text-align:center;padding:24px; color: var(--mc-muted);">
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

                        // Extract answers for chips
                        $row_answers = json_decode($row['answers_json'] ?? '[]', true);
                        $chip_count = 0;
                        $max_chips = 2;
                        $chips_html = '';
                        if (is_array($row_answers)) {
                            foreach ($row_answers as $q_id => $val) {
                                $q_text = $questions_map[(int) $q_id] ?? '';
                                if (!$q_text) continue;
                                $val_str = is_array($val) ? implode(', ', $val) : (string) $val;
                                if ($val_str === '') continue;

                                $words = explode(' ', preg_replace('/[^a-z0-9 ]/i', '', strtolower($q_text)));
                                $label_key = !empty($words[0]) ? $words[0] : 'q';
                                if (strlen($label_key) > 8) $label_key = substr($label_key, 0, 7) . '.';

                                if ($chip_count < $max_chips) {
                                    $chips_html .= '<span class="answer-chip"><b>' . esc_html($label_key) . '</b>' . esc_html(strlen($val_str) > 15 ? substr($val_str, 0, 13) . '...' : $val_str) . '</span>';
                                }
                                $chip_count++;
                            }
                        }
                        if ($chip_count > $max_chips) {
                            $chips_html .= '<span class="answers-more">+' . ($chip_count - $max_chips) . ' more</span>';
                        }
                    ?>
                        <tr>
                            <td class="mono-id"><a href="<?php echo esc_url($detail_url); ?>">#<?php echo esc_html($row['id']); ?></a></td>
                            <td class="cell-date"><?php echo esc_html(wp_date('Y-m-d', strtotime($row['created_at']))); ?><br><?php echo esc_html(wp_date('H:i', strtotime($row['created_at']))); ?></td>
                            <td>
                                <span class="dashicons <?php echo $is_booking ? 'dashicons-calendar-alt' : 'dashicons-media-document'; ?>" style="font-size:14px; width:14px; height:14px; vertical-align:middle; margin-right:4px; color:var(--mc-muted);"></span>
                                <?php echo $is_booking ? esc_html__('Bookings', 'mc-leads-engine') : esc_html($survey_row['title'] ?? $row['survey_id']); ?>
                            </td>
                            <td><span class="status-pill status-<?php echo esc_attr($row_status); ?>"><?php echo esc_html(mc_leads_status_label($row_status)); ?></span></td>
                            <td>
                                <div class="client-name"><?php echo esc_html($row_name ?: __('(No name)', 'mc-leads-engine')); ?></div>
                                <?php if ($row_email) : ?>
                                    <div class="client-line"><?php echo esc_html($row_email); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><div class="answers-chips"><?php echo $chips_html; ?></div></td>
                            <td class="mono-val"><?php echo esc_html(number_format_i18n((float) $row['total_price'])); ?></td>
                            <td><span class="score-badge score-<?php echo esc_attr($row_band); ?>"><?php echo esc_html(ucfirst($row_band)); ?> · <?php echo (int) $row['lead_score']; ?></span></td>
                            <td><a href="<?php echo esc_url($detail_url); ?>" class="view-link"><?php esc_html_e('View', 'mc-leads-engine'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1) : ?>
            <div class="mc-pagination" style="padding: 14px; display: flex; justify-content: space-between; align-items: center; background: var(--paper); border-top: 1px solid var(--line);">
                <div>
                    <?php if ($paged > 1) : ?>
                        <a class="btn" href="<?php echo esc_url(add_query_arg('paged', $paged - 1)); ?>">&laquo; <?php esc_html_e('Previous', 'mc-leads-engine'); ?></a>
                    <?php endif; ?>
                </div>
                <span style="font-weight: 600; font-size: 12px; color: var(--muted);"><?php echo esc_html(sprintf(__('Page %d of %d', 'mc-leads-engine'), $paged, $total_pages)); ?></span>
                <div>
                    <?php if ($paged < $total_pages) : ?>
                        <a class="btn" href="<?php echo esc_url(add_query_arg('paged', $paged + 1)); ?>"><?php esc_html_e('Next', 'mc-leads-engine'); ?> &raquo;</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
    </div>
    <?php
}
