<?php

if (!defined('ABSPATH')) {
    exit;
}

$cf7_shortcode = '[contact-form-7 id="' . absint($cf7_id) . '"]';

if (!function_exists('mc_booking_get_svg_icon')) {
    function mc_booking_get_svg_icon($filename) {
        $path = MC_LEADS_ENGINE_PATH . 'assets/svgs/' . $filename . '.svg';
        if (file_exists($path)) {
            $content = file_get_contents($path);
            // Remove XML declaration, DOCTYPE, and comments
            $content = preg_replace('/<\?xml.*?\?>/is', '', $content);
            $content = preg_replace('/<!DOCTYPE.*?>/is', '', $content);
            $content = preg_replace('/<!--.*?-->/is', '', $content);
            return trim($content);
        }
        return '';
    }
}
?>
<div class="mc-booking-engine" data-cf7-id="<?php echo esc_attr($cf7_id); ?>" data-session-id="<?php echo esc_attr($session_id); ?>">
    <div class="mc-booking-card" style="position: relative;">
        <!-- Back Button -->
        <button type="button" class="mc-back-btn-round" aria-label="<?php esc_attr_e('Back', 'mc-leads-engine'); ?>" style="display: none;">
            <?php echo mc_booking_get_svg_icon('arrow-left'); ?>
        </button>
        <!-- Progress Bar -->
        <div class="mc-booking-progress">
            <div class="mc-progress-bar">
                <span class="mc-progress-fill" style="width: 25%;"></span>
            </div>
            <div class="mc-progress-labels">
                <span class="step-label active" data-step="1"><?php esc_html_e('Meeting Type', 'mc-leads-engine'); ?></span>
                <span class="step-label" data-step="2"><?php esc_html_e('Location', 'mc-leads-engine'); ?></span>
                <span class="step-label" data-step="3"><?php esc_html_e('Date & Time', 'mc-leads-engine'); ?></span>
                <span class="step-label" data-step="4"><?php esc_html_e('Confirm Details', 'mc-leads-engine'); ?></span>
            </div>
        </div>

        <div class="mc-booking-steps">
            <!-- Step 1: Meeting Type -->
            <div class="mc-booking-step active" data-step="1">
                <h3><?php esc_html_e('How would you like to meet?', 'mc-leads-engine'); ?></h3>
                <p class="step-description"><?php esc_html_e('Select your preferred meeting format below to begin scheduling.', 'mc-leads-engine'); ?></p>
                
                <div class="mc-type-grid">
                    <div class="mc-type-card" data-value="online">
                        <div class="card-icon">
                            <?php echo mc_booking_get_svg_icon('online-meet'); ?>
                        </div>
                        <h4><?php esc_html_e('Online Call', 'mc-leads-engine'); ?></h4>
                        <p><?php esc_html_e('Video meeting using Google Meet or Zoom.', 'mc-leads-engine'); ?></p>
                    </div>
                    
                    <div class="mc-type-card" data-value="coffee">
                        <div class="card-icon">
                            <?php echo mc_booking_get_svg_icon('coffee-cafe'); ?>
                        </div>
                        <h4><?php esc_html_e('Coffee Meeting', 'mc-leads-engine'); ?></h4>
                        <p><?php esc_html_e('Meet in person at a curated café/hub.', 'mc-leads-engine'); ?></p>
                    </div>

                    <div class="mc-type-card" data-value="office">
                        <div class="card-icon">
                            <?php echo mc_booking_get_svg_icon('our-office'); ?>
                        </div>
                        <h4><?php esc_html_e('Office Visit', 'mc-leads-engine'); ?></h4>
                        <p><?php esc_html_e('We visit you at your office or workplace.', 'mc-leads-engine'); ?></p>
                    </div>

                    <div class="mc-type-card" data-value="host">
                        <div class="card-icon">
                            <?php echo mc_booking_get_svg_icon('our-studio'); ?>
                        </div>
                        <h4><?php esc_html_e('Our Studio', 'mc-leads-engine'); ?></h4>
                        <p><?php esc_html_e('Visit us at our primary host studio space.', 'mc-leads-engine'); ?></p>
                    </div>
                </div>

                <div class="mc-step-nav">
                    <button type="button" class="btn primary mc-next-btn" disabled><?php esc_html_e('Continue', 'mc-leads-engine'); ?></button>
                </div>
            </div>

            <!-- Step 2: Location Selector -->
            <div class="mc-booking-step" data-step="2" hidden>
                <h3><?php esc_html_e('Set Meeting Location', 'mc-leads-engine'); ?></h3>
                
                <!-- Online Info -->
                <div class="mc-loc-pane" data-type="online" hidden>
                    <div class="mc-info-banner">
                        <span class="dashicons dashicons-info"></span>
                        <p><?php esc_html_e('This meeting will be hosted online. A video conference link will be automatically generated and sent to your email and WhatsApp before the meeting start time.', 'mc-leads-engine'); ?></p>
                    </div>
                </div>

                <!-- Coffee Predefined -->
                <div class="mc-loc-pane" data-type="coffee" hidden>
                    <label class="field-label"><?php esc_html_e('Coffee Spot / Meeting Venue', 'mc-leads-engine'); ?></label>
                    <input
                        type="text"
                        class="field-input mc-custom-address"
                        list="mc-predefined-locations-<?php echo esc_attr($cf7_id); ?>"
                        placeholder="<?php esc_attr_e('e.g. Java House, Westlands — or type to search', 'mc-leads-engine'); ?>"
                        autocomplete="off"
                    >
                    <datalist id="mc-predefined-locations-<?php echo esc_attr($cf7_id); ?>">
                        <?php foreach ($predefined as $loc) : ?>
                            <option value="<?php echo esc_attr($loc); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <span class="field-desc"><?php esc_html_e('Select a suggested spot or start typing to search for any café, restaurant, or business hub.', 'mc-leads-engine'); ?></span>
                </div>

                <!-- Custom Address (Office) -->
                <div class="mc-loc-pane" data-type="office" hidden>
                    <label class="field-label"><?php esc_html_e('Enter Your Office / Workplace Address', 'mc-leads-engine'); ?></label>
                    <input type="text" class="field-input mc-custom-address" placeholder="<?php esc_attr_e('e.g. 5th Floor, Nairobi Garage, Westlands', 'mc-leads-engine'); ?>">
                    <span class="field-desc"><?php esc_html_e('Enter full business address details so our team can coordinate transport.', 'mc-leads-engine'); ?></span>
                </div>

                <!-- Predefined Host Location -->
                <div class="mc-loc-pane" data-type="host" hidden>
                    <div class="mc-info-banner">
                        <span class="dashicons dashicons-location-alt"></span>
                        <div>
                            <h4><?php esc_html_e('Memories Creative Studio', 'mc-leads-engine'); ?></h4>
                            <p><?php esc_html_e('General Suite 104, Prestige Plaza, Ngong Road, Nairobi.', 'mc-leads-engine'); ?></p>
                        </div>
                    </div>
                </div>

                <div class="mc-step-nav">
                    <button type="button" class="btn primary mc-next-btn"><?php esc_html_e('Continue', 'mc-leads-engine'); ?></button>
                </div>
            </div>

            <!-- Step 3: Date & Time -->
            <div class="mc-booking-step" data-step="3" hidden>
                <h3><?php esc_html_e('Choose Date & Time', 'mc-leads-engine'); ?></h3>
                
                <div class="mc-datetime-grid">
                    <div class="mc-calendar-container">
                        <!-- Custom Inline Calendar -->
                        <div class="mc-calendar-header">
                            <button type="button" class="mc-cal-nav prev">&lt;</button>
                            <span class="mc-cal-month-year">June 2026</span>
                            <button type="button" class="mc-cal-nav next">&gt;</button>
                        </div>
                        <div class="mc-calendar-weekdays">
                            <span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span><span>S</span>
                        </div>
                        <div class="mc-calendar-days">
                            <!-- Populated by JS -->
                        </div>
                    </div>

                    <div class="mc-slots-container">
                        <h4><?php esc_html_e('Available Slots', 'mc-leads-engine'); ?></h4>
                        <div class="mc-slots-grid">
                            <p class="no-slots-msg"><?php esc_html_e('Select a date on the calendar to see available slots.', 'mc-leads-engine'); ?></p>
                        </div>
                    </div>
                </div>

                <div class="mc-step-nav">
                    <button type="button" class="btn primary mc-next-btn" disabled><?php esc_html_e('Continue', 'mc-leads-engine'); ?></button>
                </div>
            </div>

            <!-- Step 4: Booking Details form -->
            <div class="mc-booking-step" data-step="4" hidden>
                <h3><?php esc_html_e('Complete Booking Details', 'mc-leads-engine'); ?></h3>
                <p class="step-description"><?php esc_html_e('Provide your contact info below to complete and secure your appointment reservation.', 'mc-leads-engine'); ?></p>
                
                <div class="mc-booking-summary-banner">
                    <div class="summary-item">
                        <span class="dashicons dashicons-calendar-alt"></span>
                        <span class="summary-text mc-summary-date-time">N/A</span>
                    </div>
                    <div class="summary-item">
                        <span class="dashicons dashicons-location"></span>
                        <span class="summary-text mc-summary-location">N/A</span>
                    </div>
                </div>

                <div class="mc-cf7-wrapper">
                    <?php if (shortcode_exists('contact-form-7')) : ?>
                        <?php echo do_shortcode($cf7_shortcode); ?>
                    <?php else : ?>
                        <div class="mc-leads-engine-notice"><?php esc_html_e('Contact Form 7 plugin is required but not active.', 'mc-leads-engine'); ?></div>
                    <?php endif; ?>
                </div>

                <!-- Back button is positioned at the top-left of the card -->
            </div>
        </div>
    </div>
</div>
