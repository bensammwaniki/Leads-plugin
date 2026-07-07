<?php

if (!defined('ABSPATH')) {
    exit;
}

function mc_leads_engine_render_leads_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'mc-leads-engine'));
    }

    $lead_id = absint($_GET['lead_id'] ?? 0);
    global $wpdb;

    // Load surveys & questions for name mapping
    $questions_rows = $wpdb->get_results("SELECT id, question_text FROM " . mc_leads_engine_table('survey_questions'), ARRAY_A);
    $questions_map  = array();
    if (is_array($questions_rows)) {
        foreach ($questions_rows as $q) {
            $questions_map[(int) $q['id']] = $q['question_text'];
        }
    }

    $lead = null;
    $lead_cf7 = array();
    $booking_row = null;

    // Dummy fallback array if database has no leads or if requested ID is a dummy lead
    $dummy_leads = array(
        39 => array(
            'id' => 39,
            'created_at' => '2026-07-02 10:15:00',
            'survey_id' => 1,
            'status' => 'new',
            'lead_score' => 0,
            'total_price' => 10000,
            'answers_json' => json_encode(array('source' => 'estimate')),
            'client_name' => 'bensam',
            'client_email' => 'bensammwaniki@gmail.com',
            'client_phone' => '743491012',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'is_demo' => true
        ),
        38 => array(
            'id' => 38,
            'created_at' => '2026-07-01 16:24:00',
            'survey_id' => 1,
            'status' => 'new',
            'lead_score' => 80,
            'total_price' => 62500,
            'answers_json' => json_encode(array('org' => 'school', 'goal' => 'branding', 'size' => 'medium')),
            'client_name' => 'bensammwaniki',
            'client_email' => 'bensammwaniki@gmail.com',
            'client_phone' => '',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'is_demo' => true
        ),
        37 => array(
            'id' => 37,
            'created_at' => '2026-06-29 11:33:00',
            'survey_id' => 1,
            'status' => 'contacted',
            'lead_score' => 105,
            'total_price' => 49000,
            'answers_json' => json_encode(array('org' => 'Small Business', 'goal' => 'leads, branding, payments')),
            'client_name' => 'bensam',
            'client_email' => 'bensammwaniki@gmail.com',
            'client_phone' => '743491012',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'is_demo' => true
        ),
        36 => array(
            'id' => 36,
            'created_at' => '2026-06-25 20:29:00',
            'survey_id' => 1,
            'status' => 'new',
            'lead_score' => 0,
            'total_price' => 10000,
            'answers_json' => json_encode(array('source' => 'estimate')),
            'client_name' => 'bensam',
            'client_email' => 'bnm@gma.com',
            'client_phone' => '',
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            'is_demo' => true
        ),
        35 => array(
            'id' => 35,
            'created_at' => '2026-06-25 20:27:00',
            'survey_id' => 1,
            'status' => 'new',
            'lead_score' => 75,
            'total_price' => 26000,
            'answers_json' => json_encode(array('org' => 'Startup', 'goal' => 'branding')),
            'client_name' => 'bensam',
            'client_email' => 'bensammwaniki@gmail.com',
            'client_phone' => '743491012',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'is_demo' => true
        ),
        34 => array(
            'id' => 34,
            'created_at' => '2026-06-25 20:14:00',
            'survey_id' => 1,
            'status' => 'new',
            'lead_score' => 120,
            'total_price' => 65000,
            'answers_json' => json_encode(array('org' => 'corporate', 'goal' => 'payments')),
            'client_name' => 'bensammwaniki',
            'client_email' => 'bensammwaniki@gmail.com',
            'client_phone' => '743491012',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'is_demo' => true
        ),
        33 => array(
            'id' => 33,
            'created_at' => '2026-06-25 19:59:00',
            'survey_id' => 1,
            'status' => 'new',
            'lead_score' => 185,
            'total_price' => 102000,
            'answers_json' => json_encode(array('org' => 'school, ngo', 'goal' => 'leads, branding, payments…')),
            'client_name' => 'bensam',
            'client_email' => 'bensammwaniki@gmail.com',
            'client_phone' => '743491012',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'is_demo' => true
        ),
        32 => array(
            'id' => 32,
            'created_at' => '2026-06-25 18:38:00',
            'survey_id' => 2,
            'status' => 'new',
            'lead_score' => 260,
            'total_price' => 119500,
            'answers_json' => json_encode(array('org' => 'school, SB, ngo', 'msg' => 'my special tool')),
            'client_name' => 'bensam',
            'client_email' => 'bensammwaniki@gmail.com',
            'client_phone' => '743491012',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'is_demo' => true
        ),
        31 => array(
            'id' => 31,
            'created_at' => '2026-06-25 18:11:00',
            'survey_id' => 2,
            'status' => 'new',
            'lead_score' => 10,
            'total_price' => 10000,
            'answers_json' => json_encode(array()),
            'client_name' => 'Bensam',
            'client_email' => 'bensammwaniki@gmail.com',
            'client_phone' => '743491012',
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_4 like Mac OS X)',
            'is_demo' => true
        ),
        30 => array(
            'id' => 30,
            'created_at' => '2026-06-25 17:38:00',
            'survey_id' => 2,
            'status' => 'new',
            'lead_score' => 10,
            'total_price' => 10000,
            'answers_json' => json_encode(array('msg' => 'Would love to meet')),
            'client_name' => 'Bensam',
            'client_email' => 'bensammwaniki@gmail.com',
            'client_phone' => '743491012',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'is_demo' => true
        ),
    );

    if ($lead_id) {
        $lead = mc_leads_engine_leads_repository()->get_lead($lead_id);
        if (!$lead && isset($dummy_leads[$lead_id])) {
            $lead = $dummy_leads[$lead_id];
        }
        
        if ($lead) {
            $lead_cf7 = empty($lead['is_demo']) ? mc_leads_engine_leads_repository()->get_cf7_data($lead_id) : array();
            
            if (!empty($lead['is_demo'])) {
                if ($lead_id == 32) {
                    $booking_row = array(
                        'id' => 28,
                        'meeting_date' => '2026-06-26',
                        'meeting_time' => '13:30:00',
                        'meeting_type' => 'online',
                        'location_name' => 'Google Meet / Zoom',
                        'location_address' => 'Online Call Link',
                        'calendar_event_id' => '1gk5k5e9kcjq...',
                    );
                } elseif ($lead_id == 31) {
                    $booking_row = array(
                        'id' => 27,
                        'meeting_date' => '2026-06-26',
                        'meeting_time' => '12:45:00',
                        'meeting_type' => 'online',
                        'location_name' => 'Google Meet / Zoom',
                        'location_address' => 'Online Call Link',
                        'calendar_event_id' => 'jdjdlf6odcff...',
                    );
                } elseif ($lead_id == 30) {
                    $booking_row = array(
                        'id' => 26,
                        'meeting_date' => '2026-06-26',
                        'meeting_time' => '12:00:00',
                        'meeting_type' => 'online',
                        'location_name' => 'Google Meet / Zoom',
                        'location_address' => 'Online Call Link',
                        'calendar_event_id' => 'hbpedse5eunb...',
                    );
                }
            } else {
                $booking_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM " . mc_leads_engine_table('bookings') . " WHERE lead_id = %d LIMIT 1",
                    $lead_id
                ), ARRAY_A);
            }
        }
    }

    ?>
    <div class="wrap mc-leads-engine-admin">
        <?php if ($lead) :
            $repo          = mc_leads_engine_leads_repository();
            $name          = !empty($lead['is_demo']) ? $lead['client_name']  : $repo->find_client_name($lead_id);
            $email         = !empty($lead['is_demo']) ? $lead['client_email'] : $repo->find_client_email($lead_id);
            $phone         = !empty($lead['is_demo']) ? $lead['client_phone'] : $repo->find_client_phone($lead_id);
            $is_booking    = mc_leads_is_booking($lead);
            $survey_row    = mc_leads_engine_survey_repository()->get_survey($lead['survey_id']);
            $band          = mc_leads_score_band($lead['lead_score']);
            $device        = mc_leads_parse_device($lead['user_agent'] ?? '');
            $status        = $lead['status'] ?? 'new';

            $activity_log = !empty($lead['is_demo']) ? array(
                array(
                    'activity_type' => 'creation',
                    'created_at' => $lead['created_at'],
                    'body' => __('Lead submission received and score analyzed.', 'mc-leads-engine'),
                ),
            ) : MC_Leads_Activity::get_log($lead_id);

            // Build clean phone for WhatsApp link
            $clean_phone = preg_replace('/[^0-9]/', '', $phone ?? '');
            $wa_link     = $clean_phone ? 'https://wa.me/' . $clean_phone : '';
        ?>
        <div class="mc-lead-profile" style="margin-bottom: 24px;">

            <!-- Back navigation / action top bar -->
            <div class="topbar">
                <a class="back-link" href="<?php echo esc_url(admin_url('admin.php?page=mc-leads-engine-analytics')); ?>">← <?php esc_html_e('Back to Analytics', 'mc-leads-engine'); ?></a>
                <a class="btn primary" href="<?php echo esc_url($wa_link ?: ($email ? 'mailto:' . $email : '#')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Contact lead', 'mc-leads-engine'); ?></a>
            </div>

            <?php if (!empty($lead['is_demo'])) : ?>
                <div style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e3a8a; padding:12px 16px; margin-bottom:20px; border-radius:var(--radius); font-size:12.5px; display:flex; align-items:center; gap:8px;">
                    <span style="font-size:16px;">💡</span>
                    <span><?php esc_html_e('Showing demo details for this lead.', 'mc-leads-engine'); ?></span>
                </div>
            <?php endif; ?>

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
                                    if (is_numeric($q_id)) {
                                        $q_text  = $questions_map[(int) $q_id] ?? sprintf(__('Question #%d', 'mc-leads-engine'), $q_id);
                                    } else {
                                        // Humanize string keys for dummy data
                                        $friendly_map = array(
                                            'org' => __('Organization Type', 'mc-leads-engine'),
                                            'goal' => __('Project Goal', 'mc-leads-engine'),
                                            'size' => __('Project Size', 'mc-leads-engine'),
                                            'source' => __('Lead Source', 'mc-leads-engine'),
                                            'msg' => __('Client Message', 'mc-leads-engine'),
                                        );
                                        $q_text = $friendly_map[$q_id] ?? ucfirst($q_id);
                                    }
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
                                $type = MC_Leads_Activity::get_type_label($entry['activity_type'] ?? 'note');
                                
                                $emoji = '📝';
                                $act_type = $entry['activity_type'] ?? 'note';
                                if ($act_type === 'status') {
                                    $emoji = '🔄';
                                } elseif ($act_type === 'booking') {
                                    $emoji = '📅';
                                } elseif ($act_type === 'creation') {
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
        <?php else : ?>
            <!-- Empty state notice when no lead is selected -->
            <div class="panel" style="padding: 48px 32px; text-align: center; max-width: 600px; margin: 40px auto; border-radius: var(--radius);">
                <div style="font-size: 48px; margin-bottom: 20px;">🔍</div>
                <h3 style="font-size: 16px; font-weight: 800; color: var(--text); margin: 0 0 10px;"><?php esc_html_e('No Lead Selected', 'mc-leads-engine'); ?></h3>
                <p style="font-size: 12.5px; color: var(--muted); line-height: 1.6; margin: 0 0 24px;">
                    <?php esc_html_e('To view a lead details profile, please select and click "View" on any entry from either the Analytics or Bookings page.', 'mc-leads-engine'); ?>
                </p>
                <div style="display: flex; justify-content: center; gap: 12px;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=mc-leads-engine-analytics')); ?>" class="btn primary"><?php esc_html_e('Go to Analytics & Leads', 'mc-leads-engine'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=mc-leads-engine-bookings')); ?>" class="btn"><?php esc_html_e('Go to Bookings', 'mc-leads-engine'); ?></a>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
