<?php

if (!defined('ABSPATH')) {
    exit;
}

function mc_leads_engine_install() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    $tables = array();

    $tables[] = "CREATE TABLE " . mc_leads_engine_table('surveys') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(255) NOT NULL,
        description longtext NULL,
        status varchar(20) NOT NULL DEFAULT 'draft',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    $tables[] = "CREATE TABLE " . mc_leads_engine_table('survey_sections') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        survey_id bigint(20) unsigned NOT NULL,
        title varchar(255) NOT NULL,
        description longtext NULL,
        order_index int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY survey_id (survey_id)
    ) $charset_collate;";

    $tables[] = "CREATE TABLE " . mc_leads_engine_table('survey_questions') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        section_id bigint(20) unsigned NOT NULL,
        question_text text NOT NULL,
        type varchar(20) NOT NULL,
        required tinyint(1) NOT NULL DEFAULT 0,
        order_index int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY section_id (section_id)
    ) $charset_collate;";

    $tables[] = "CREATE TABLE " . mc_leads_engine_table('question_options') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        question_id bigint(20) unsigned NOT NULL,
        label varchar(255) NOT NULL,
        value varchar(255) NOT NULL,
        description text NULL,
        price_impact decimal(12,2) NOT NULL DEFAULT 0,
        score_impact int(11) NOT NULL DEFAULT 0,
        order_index int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY question_id (question_id)
    ) $charset_collate;";

    $tables[] = "CREATE TABLE " . mc_leads_engine_table('leads') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        survey_id bigint(20) unsigned NOT NULL,
        session_id varchar(64) NOT NULL,
        total_price decimal(12,2) NOT NULL DEFAULT 0,
        lead_score int(11) NOT NULL DEFAULT 0,
        answers_json longtext NULL,
        pricing_json longtext NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY survey_id (survey_id),
        KEY session_id (session_id)
    ) $charset_collate;";

    $tables[] = "CREATE TABLE " . mc_leads_engine_table('lead_answers') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        lead_id bigint(20) unsigned NOT NULL,
        question_id bigint(20) unsigned NOT NULL,
        answer_value longtext NULL,
        PRIMARY KEY  (id),
        KEY lead_id (lead_id),
        KEY question_id (question_id)
    ) $charset_collate;";

    $tables[] = "CREATE TABLE " . mc_leads_engine_table('cf7_integrations') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        survey_id bigint(20) unsigned NOT NULL,
        cf7_form_id bigint(20) unsigned NOT NULL,
        mapping_rules longtext NULL,
        PRIMARY KEY  (id),
        KEY survey_id (survey_id),
        KEY cf7_form_id (cf7_form_id)
    ) $charset_collate;";

    $tables[] = "CREATE TABLE " . mc_leads_engine_table('lead_cf7_data') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        lead_id bigint(20) unsigned NOT NULL,
        cf7_form_id bigint(20) unsigned NOT NULL,
        data_json longtext NULL,
        PRIMARY KEY  (id),
        KEY lead_id (lead_id),
        KEY cf7_form_id (cf7_form_id)
    ) $charset_collate;";

    $tables[] = "CREATE TABLE " . mc_leads_engine_table('step_events') . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        survey_id bigint(20) unsigned NOT NULL,
        event_type varchar(20) NOT NULL DEFAULT 'step',
        step int(11) NOT NULL DEFAULT 1,
        session_id varchar(64) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY survey_id (survey_id),
        KEY event_type (event_type),
        KEY session_id (session_id),
        KEY created_at (created_at)
    ) $charset_collate;";

    foreach ($tables as $sql) {
        dbDelta($sql);
    }

    if (!get_option('mc_leads_engine_settings')) {
        add_option('mc_leads_engine_settings', mc_leads_engine_get_settings());
    }

    mc_leads_engine_seed_demo_data();
}

function mc_leads_engine_seed_demo_data() {
    global $wpdb;

    $survey_table = mc_leads_engine_table('surveys');
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$survey_table}");
    if ($count > 0) {
        return;
    }

    $survey_repo = mc_leads_engine_survey_repository();
    $section_repo = new MC_Leads_Engine_Section();
    $question_repo = new MC_Leads_Engine_Question();

    $survey_id = $survey_repo->create_survey(array(
        'title' => 'Website Project Estimate',
        'description' => 'A starter survey for agencies collecting project scope, feature needs, and pricing signals.',
        'status' => 'published',
    ));

    if (!$survey_id) {
        return;
    }

    $basics_section_id = $section_repo->save_section(array(
        'survey_id' => $survey_id,
        'title' => 'Project Basics',
        'description' => 'Capture the core project details and business type.',
        'order_index' => 0,
    ));

    $features_section_id = $section_repo->save_section(array(
        'survey_id' => $survey_id,
        'title' => 'Features & Scope',
        'description' => 'Collect the requested features to estimate scope and price.',
        'order_index' => 1,
    ));

    $question_repo->save_question(array(
        'section_id' => $basics_section_id,
        'question_text' => 'What is your business name?',
        'type' => 'text',
        'required' => 1,
        'order_index' => 0,
        'options' => array(),
    ));

    $question_repo->save_question(array(
        'section_id' => $basics_section_id,
        'question_text' => 'What type of organization are you?',
        'type' => 'radio',
        'required' => 1,
        'order_index' => 1,
        'options' => array(
            array('label' => 'Startup', 'value' => 'startup', 'price_impact' => 0, 'score_impact' => 10, 'order_index' => 0),
            array('label' => 'NGO', 'value' => 'ngo', 'price_impact' => 0, 'score_impact' => 12, 'order_index' => 1),
            array('label' => 'Corporate', 'value' => 'corporate', 'price_impact' => 0, 'score_impact' => 20, 'order_index' => 2),
        ),
    ));

    $question_repo->save_question(array(
        'section_id' => $basics_section_id,
        'question_text' => 'How many pages do you need?',
        'type' => 'number',
        'required' => 1,
        'order_index' => 2,
        'options' => array(
            array('label' => 'Per page', 'value' => 'per_unit', 'price_impact' => 1000, 'score_impact' => 0, 'order_index' => 0),
        ),
    ));

    $question_repo->save_question(array(
        'section_id' => $features_section_id,
        'question_text' => 'Which features do you want?',
        'type' => 'checkbox',
        'required' => 0,
        'order_index' => 0,
        'options' => array(
            array('label' => 'Booking system', 'value' => 'booking', 'price_impact' => 3000, 'score_impact' => 15, 'order_index' => 0),
            array('label' => 'Payment gateway', 'value' => 'payments', 'price_impact' => 4000, 'score_impact' => 18, 'order_index' => 1),
            array('label' => 'Blog / news', 'value' => 'blog', 'price_impact' => 1500, 'score_impact' => 6, 'order_index' => 2),
        ),
    ));

    update_option('mc_leads_engine_demo_survey_id', $survey_id);
}
