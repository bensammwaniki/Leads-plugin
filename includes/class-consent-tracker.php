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
            <div class="mc-cookie-banner-content">
                <div class="mc-cookie-banner-text">
                    <h4 class="mc-cookie-banner-title"><?php echo esc_html($title); ?></h4>
                    <p class="mc-cookie-banner-desc"><?php echo esc_html($message); ?></p>
                </div>
                <div class="mc-cookie-banner-actions">
                    <button type="button" id="mc-btn-reject-cookies" class="mc-cookie-btn mc-btn-reject"><?php echo esc_html($btn_reject); ?></button>
                    <button type="button" id="mc-btn-settings-cookies" class="mc-cookie-btn mc-btn-settings"><?php echo esc_html($btn_settings); ?></button>
                    <button type="button" id="mc-btn-accept-cookies" class="mc-cookie-btn mc-btn-accept"><?php echo esc_html($btn_accept); ?></button>
                </div>
            </div>
        </div>

        <!-- Cookie Preferences Modal -->
        <div id="mc-cookie-modal" class="mc-cookie-modal" style="display: none;">
            <div class="mc-cookie-modal-overlay"></div>
            <div class="mc-cookie-modal-container mc-theme-<?php echo esc_attr($theme); ?>">
                <div class="mc-cookie-modal-header">
                    <h3 class="mc-cookie-modal-title"><?php esc_html_e('Cookie Preferences', 'mc-leads-engine'); ?></h3>
                    <button type="button" id="mc-cookie-modal-close" class="mc-cookie-modal-close">&times;</button>
                </div>
                <div class="mc-cookie-modal-body">
                    <p class="mc-cookie-modal-desc">
                        <?php esc_html_e('Customize how tracking and cookies are applied on this site. Your selections only control passive tracking options.', 'mc-leads-engine'); ?>
                    </p>
                    
                    <!-- Preference Groups -->
                    <div class="mc-preference-item">
                        <div class="mc-preference-info">
                            <h5 class="mc-preference-title">
                                <?php esc_html_e('Essential Cookies', 'mc-leads-engine'); ?>
                                <span class="mc-badge-required"><?php esc_html_e('Required', 'mc-leads-engine'); ?></span>
                            </h5>
                            <p class="mc-preference-desc"><?php esc_html_e('Necessary to store progress in your surveys, handle selected booking slots, and keep UI navigation running.', 'mc-leads-engine'); ?></p>
                        </div>
                        <div class="mc-preference-toggle">
                            <label class="mc-switch">
                                <input type="checkbox" checked disabled>
                                <span class="mc-slider round"></span>
                            </label>
                        </div>
                    </div>

                    <div class="mc-preference-item">
                        <div class="mc-preference-info">
                            <h5 class="mc-preference-title"><?php esc_html_e('Analytics Tracking', 'mc-leads-engine'); ?></h5>
                            <p class="mc-preference-desc"><?php esc_html_e('Enables Google Analytics tracking to help us understand how users interact with our estimators, surveys, and flow pages.', 'mc-leads-engine'); ?></p>
                        </div>
                        <div class="mc-preference-toggle">
                            <label class="mc-switch">
                                <input type="checkbox" id="mc-toggle-analytics">
                                <span class="mc-slider round"></span>
                            </label>
                        </div>
                    </div>

                    <div class="mc-preference-item">
                        <div class="mc-preference-info">
                            <h5 class="mc-preference-title"><?php esc_html_e('Marketing & Pixels', 'mc-leads-engine'); ?></h5>
                            <p class="mc-preference-desc"><?php esc_html_e('Enables Meta Pixel, UTM campaign attribution tracking, and WhatsApp click analytics to measure and optimize our marketing campaigns.', 'mc-leads-engine'); ?></p>
                        </div>
                        <div class="mc-preference-toggle">
                            <label class="mc-switch">
                                <input type="checkbox" id="mc-toggle-marketing">
                                <span class="mc-slider round"></span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="mc-cookie-modal-footer">
                    <button type="button" id="mc-btn-save-cookie-preferences" class="mc-cookie-btn mc-btn-save-prefs"><?php esc_html_e('Save Preferences', 'mc-leads-engine'); ?></button>
                </div>
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
