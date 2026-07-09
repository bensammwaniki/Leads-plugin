<?php

if (!defined('ABSPATH')) {
    exit;
}

class MC_Leads_Engine_Consent_Tracker {
    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_footer', array($this, 'render_cookie_banner'));
    }

    /**
     * Enqueue tracking styles and scripts.
     */
    public function enqueue_assets() {
        $settings = mc_leads_engine_get_settings();
        
        wp_register_style('mc-leads-engine-tracking', MC_LEADS_ENGINE_URL . 'assets/css/tracking.css', array(), MC_LEADS_ENGINE_VERSION);
        wp_register_script('mc-leads-engine-tracking', MC_LEADS_ENGINE_URL . 'assets/js/tracking.js', array(), MC_LEADS_ENGINE_VERSION, true);

        // Always enqueue tracking assets, their execution is controlled client-side by consent state
        wp_enqueue_style('mc-leads-engine-tracking');
        wp_enqueue_script('mc-leads-engine-tracking');

        // Localize settings for the tracking JS
        wp_localize_script(
            'mc-leads-engine-tracking',
            'MCLeadsTracking',
            array(
                'bannerEnabled'      => (int) ($settings['cookie_banner_enable'] ?? 0),
                'bannerTitle'        => $settings['cookie_banner_title'] ?? __('We value your privacy', 'mc-leads-engine'),
                'bannerMessage'      => $settings['cookie_banner_message'] ?? '',
                'btnAccept'          => $settings['cookie_banner_btn_accept'] ?? __('Accept All', 'mc-leads-engine'),
                'btnReject'          => $settings['cookie_banner_btn_reject'] ?? __('Reject All', 'mc-leads-engine'),
                'btnSettings'        => $settings['cookie_banner_btn_settings'] ?? __('Customize', 'mc-leads-engine'),
                'bannerTheme'        => $settings['cookie_banner_theme'] ?? 'glassmorphism',
                'gaEnabled'          => (int) ($settings['tracking_ga_enable'] ?? 0),
                'gaId'               => sanitize_text_field($settings['tracking_ga_id'] ?? ''),
                'pixelEnabled'       => (int) ($settings['tracking_pixel_enable'] ?? 0),
                'pixelId'            => sanitize_text_field($settings['tracking_pixel_id'] ?? ''),
                'whatsappClickTrack' => (int) ($settings['tracking_whatsapp_click'] ?? 0),
            )
        );
    }

    /**
     * Render the premium Cookie Consent Banner in the footer.
     */
    public function render_cookie_banner() {
        $settings = mc_leads_engine_get_settings();
        
        // Return early if cookie banner is disabled
        if (empty($settings['cookie_banner_enable'])) {
            return;
        }

        $theme = $settings['cookie_banner_theme'] ?? 'glassmorphism';
        $title = $settings['cookie_banner_title'] ?? __('We value your privacy', 'mc-leads-engine');
        $message = $settings['cookie_banner_message'] ?? '';
        $btn_accept = $settings['cookie_banner_btn_accept'] ?? __('Accept All', 'mc-leads-engine');
        $btn_reject = $settings['cookie_banner_btn_reject'] ?? __('Reject All', 'mc-leads-engine');
        $btn_settings = $settings['cookie_banner_btn_settings'] ?? __('Customize', 'mc-leads-engine');
        ?>
        <div id="mc-cookie-banner" class="mc-cookie-banner mc-theme-<?php echo esc_attr($theme); ?>" style="display: none;">
            <div class="mc-cookie-title"><?php echo esc_html($title); ?></div>
            <div class="mc-cookie-body">
                <?php echo wp_kses_post($message); ?>
            </div>

            <div class="mc-cookie-actions">
                <div class="mc-cookie-actions-row">
                    <button type="button" id="mc-btn-reject-cookies" class="mc-cbtn mc-btn-reject"><?php echo esc_html($btn_reject); ?></button>
                    <button type="button" id="mc-btn-accept-cookies" class="mc-cbtn mc-btn-accept"><?php echo esc_html($btn_accept); ?></button>
                </div>
                <div class="mc-cookie-footer-row">
                    <button type="button" id="mc-btn-settings-cookies" class="mc-cbtn mc-text-btn"><?php echo esc_html($btn_settings); ?></button>
                </div>
            </div>

            <!-- Customize preferences panel, hidden until clicked -->
            <div class="mc-customize-panel" id="mc-customize-panel">
                <div class="mc-cat-row">
                    <div class="mc-cat-info">
                        <b><?php esc_html_e('Essential Cookies', 'mc-leads-engine'); ?></b>
                        <span><?php esc_html_e('Required to store progress in your surveys, handle selected booking slots, and keep UI navigation running.', 'mc-leads-engine'); ?></span>
                    </div>
                    <span class="mc-cat-locked"><?php esc_html_e('Always on', 'mc-leads-engine'); ?></span>
                </div>
                <div class="mc-cat-row">
                    <div class="mc-cat-info">
                        <b><?php esc_html_e('Analytics Tracking', 'mc-leads-engine'); ?></b>
                        <span><?php esc_html_e('Enables Google Analytics tracking to help us understand how users interact with our estimators, surveys, and flow pages.', 'mc-leads-engine'); ?></span>
                    </div>
                    <label class="mc-switch">
                        <input type="checkbox" id="mc-toggle-analytics" checked>
                        <span class="mc-track"></span>
                    </label>
                </div>
                <div class="mc-cat-row">
                    <div class="mc-cat-info">
                        <b><?php esc_html_e('Marketing & Pixels', 'mc-leads-engine'); ?></b>
                        <span><?php esc_html_e('Enables Meta Pixel, UTM campaign attribution tracking, and WhatsApp click analytics to measure and optimize our marketing campaigns.', 'mc-leads-engine'); ?></span>
                    </div>
                    <label class="mc-switch">
                        <input type="checkbox" id="mc-toggle-marketing">
                        <span class="mc-track"></span>
                    </label>
                </div>
                <button type="button" id="mc-btn-save-cookie-preferences" class="mc-cbtn mc-btn-accept mc-customize-save" style="width: 100%; margin-top: 14px;"><?php esc_html_e('Save Preferences', 'mc-leads-engine'); ?></button>
            </div>
        </div>
        <?php
    }

    /**
     * Check if a specific tracking category has consent.
     * Can be called from PHP during lead processing or notification triggers.
     *
     * @param string $category 'analytics' or 'marketing'
     * @return bool
     */
    public static function has_consent($category) {
        if (empty($_COOKIE['mc_leads_consent'])) {
            return false;
        }

        $consent = json_decode(wp_unslash($_COOKIE['mc_leads_consent']), true);
        if (!is_array($consent)) {
            return false;
        }

        return !empty($consent[$category]);
    }
}
