<?php

if (!defined('ABSPATH')) {
    exit;
}

function mc_leads_engine_admin_assets($hook) {
    if (strpos($hook, 'mc-leads-engine') === false) {
        return;
    }

    wp_enqueue_style('mc-leads-engine-admin', MC_LEADS_ENGINE_URL . 'assets/css/admin.css', array(), MC_LEADS_ENGINE_VERSION);
    wp_enqueue_script('mc-leads-engine-admin', MC_LEADS_ENGINE_URL . 'assets/js/admin.js', array(), MC_LEADS_ENGINE_VERSION, true);
    wp_localize_script('mc-leads-engine-admin', 'mcLeadsEngine', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('mc_leads_engine_nonce'),
    ));
}
add_action('admin_enqueue_scripts', 'mc_leads_engine_admin_assets');

function mc_leads_engine_register_admin_menu() {
    add_menu_page(
        __('MC Leads Engine', 'mc-leads-engine'),
        __('MC Leads Engine', 'mc-leads-engine'),
        'manage_options',
        'mc-leads-engine',
        'mc_leads_engine_render_dashboard_page',
        'dashicons-chart-area',
        26
    );

    add_submenu_page(
        'mc-leads-engine',
        __('Dashboard', 'mc-leads-engine'),
        __('Dashboard', 'mc-leads-engine'),
        'manage_options',
        'mc-leads-engine',
        'mc_leads_engine_render_dashboard_page'
    );

    add_submenu_page(
        'mc-leads-engine',
        __('Surveys', 'mc-leads-engine'),
        __('Surveys', 'mc-leads-engine'),
        'manage_options',
        'mc-leads-engine-surveys',
        'mc_leads_engine_render_surveys_page'
    );

    add_submenu_page(
        'mc-leads-engine',
        __('Builder', 'mc-leads-engine'),
        __('Builder', 'mc-leads-engine'),
        'manage_options',
        'mc-leads-engine-builder',
        'mc_leads_engine_render_builder_page'
    );

    add_submenu_page(
        'mc-leads-engine',
        __('Analytics & Leads', 'mc-leads-engine'),
        __('Analytics & Leads', 'mc-leads-engine'),
        'manage_options',
        'mc-leads-engine-analytics',
        'mc_leads_engine_render_analytics_page'
    );

    add_submenu_page(
        'mc-leads-engine',
        __('Pricing Rules', 'mc-leads-engine'),
        __('Pricing Rules', 'mc-leads-engine'),
        'manage_options',
        'mc-leads-engine-pricing',
        'mc_leads_engine_render_pricing_page'
    );


    add_submenu_page(
        'mc-leads-engine',
        __('Settings', 'mc-leads-engine'),
        __('Settings', 'mc-leads-engine'),
        'manage_options',
        'mc-leads-engine-settings',
        'mc_leads_engine_render_settings_page'
    );

    add_submenu_page(
        null,
        __('Leads', 'mc-leads-engine'),
        __('Leads', 'mc-leads-engine'),
        'manage_options',
        'mc-leads-engine-leads',
        'mc_leads_engine_render_leads_page'
    );
}
add_action('admin_menu', 'mc_leads_engine_register_admin_menu');

function mc_leads_engine_render_dashboard_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'mc-leads-engine'));
    }

    mc_leads_engine_render_admin_app('dashboard');
}

function mc_leads_engine_render_builder_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'mc-leads-engine'));
    }

    mc_leads_engine_render_admin_app('builder');
}

function mc_leads_engine_render_pricing_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'mc-leads-engine'));
    }

    mc_leads_engine_render_admin_app('pricing');
}


