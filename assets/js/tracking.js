/**
 * MC LEADS — PASSIVE OBSERVER TRACKING LAYER
 */

(function () {
  const settings = window.MCLeadsTracking || {};

  document.addEventListener('DOMContentLoaded', () => {
    initConsentBanner();
    initTracking();
  });

  /**
   * Helper to set cookie
   */
  function setCookie(name, value, days) {
    let expires = '';
    if (days) {
      const date = new Date();
      date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
      expires = '; expires=' + date.toUTCString();
    }
    const isSecure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/' + isSecure + '; SameSite=Lax';
  }

  /**
   * Helper to get cookie
   */
  function getCookie(name) {
    const nameEQ = name + '=';
    const ca = document.cookie.split(';');
    for (let i = 0; i < ca.length; i++) {
      let c = ca[i];
      while (c.charAt(0) === ' ') c = c.substring(1, c.length);
      if (c.indexOf(nameEQ) === 0) return decodeURIComponent(c.substring(nameEQ.length, c.length));
    }
    return null;
  }

  /**
   * Helper to delete cookie
   */
  function eraseCookie(name) {
    document.cookie = name + '=; Max-Age=-99999999; path=/; SameSite=Lax';
  }

  /**
   * Cookie Consent Banner Controller
   */
  function initConsentBanner() {
    if (parseInt(settings.bannerEnabled, 10) !== 1) return;

    const consent = getCookie('mc_leads_consent');
    if (consent) return; // Consent already given/refused

    const banner = document.getElementById('mc-cookie-banner');
    const panel = document.getElementById('mc-customize-panel');
    if (!banner) return;

    // Show banner with slide animation
    banner.style.display = 'block';
    setTimeout(() => banner.classList.add('mc-show'), 150);

    // 1. Accept All Button
    const btnAccept = document.getElementById('mc-btn-accept-cookies');
    if (btnAccept) {
      btnAccept.addEventListener('click', () => {
        saveConsent({ analytics: true, marketing: true });
      });
    }

    // 2. Reject All Button
    const btnReject = document.getElementById('mc-btn-reject-cookies');
    if (btnReject) {
      btnReject.addEventListener('click', () => {
        saveConsent({ analytics: false, marketing: false });
      });
    }

    // 3. Customize button -> Toggle customize panel
    const btnSettings = document.getElementById('mc-btn-settings-cookies');
    if (btnSettings && panel) {
      btnSettings.addEventListener('click', () => {
        panel.classList.toggle('open');
      });
    }

    // Save Preference Toggles
    const btnSavePrefs = document.getElementById('mc-btn-save-cookie-preferences');
    if (btnSavePrefs) {
      btnSavePrefs.addEventListener('click', () => {
        const gaToggle = document.getElementById('mc-toggle-analytics');
        const pixelToggle = document.getElementById('mc-toggle-marketing');

        saveConsent({
          analytics: !!(gaToggle && gaToggle.checked),
          marketing: !!(pixelToggle && pixelToggle.checked)
        });
      });
    }

    function saveConsent(choices) {
      setCookie('mc_leads_consent', JSON.stringify(choices), 365);
      banner.classList.remove('mc-show');
      setTimeout(() => banner.style.display = 'none', 500);
      initTracking(); // Boot layers immediately based on new choices
    }
  }

  /**
   * Passive Tracking Layer Initializer
   */
  function initTracking() {
    const consentRaw = getCookie('mc_leads_consent');
    
    // Default: If banner is disabled, assume passive tracking allowed, OR if banner is enabled but no consent set, default to no-tracking
    let consent = { analytics: false, marketing: false };
    
    if (parseInt(settings.bannerEnabled, 10) !== 1) {
      // Banner is disabled, tracking defaults to enabled
      consent = { analytics: true, marketing: true };
    } else if (consentRaw) {
      try {
        consent = JSON.parse(consentRaw) || { analytics: false, marketing: false };
      } catch (e) {
        consent = { analytics: false, marketing: false };
      }
    }

    // If marketing consent is false, scrub and clear UTM parameters/referrer cookies immediately
    if (!consent.marketing) {
      eraseCookie('mc_leads_utm_source');
      eraseCookie('mc_leads_utm_medium');
      eraseCookie('mc_leads_utm_campaign');
      eraseCookie('mc_leads_utm_term');
      eraseCookie('mc_leads_utm_content');
      eraseCookie('mc_leads_referrer');
      eraseCookie('mc_leads_ga_client_id');
    } else {
      // Marketing allowed -> Capture campaign indicators
      captureUtmParams();
    }

    // Initialize Google Analytics if allowed and enabled
    if (consent.analytics && parseInt(settings.gaEnabled, 10) === 1 && settings.gaId) {
      loadGoogleAnalytics(settings.gaId);
    }

    // Initialize Meta Pixel if allowed and enabled
    if (consent.marketing && parseInt(settings.pixelEnabled, 10) === 1 && settings.pixelId) {
      loadMetaPixel(settings.pixelId);
    }

    // Initialize WhatsApp Click tracking
    if (parseInt(settings.whatsappClickTrack, 10) === 1) {
      initWhatsAppClickTracking(consent);
    }

    // Bind passive observer listeners to the custom DOM events emitted by the Core System Layer
    bindCoreEvents(consent);
  }

  /**
   * Google Analytics async script load
   */
  function loadGoogleAnalytics(gaId) {
    if (window.MCLeadsGaLoaded) return;
    window.MCLeadsGaLoaded = true;

    const script = document.createElement('script');
    script.async = true;
    script.src = 'https://www.googletagmanager.com/gtag/js?id=' + gaId;
    document.head.appendChild(script);

    window.dataLayer = window.dataLayer || [];
    window.gtag = function () {
      window.dataLayer.push(arguments);
    };
    gtag('js', new Date());
    gtag('config', gaId, { 'anonymize_ip': true });

    // Capture Google Analytics client ID for lead linking
    setTimeout(() => {
      try {
        if (typeof window.ga !== 'undefined') {
          ga(function(tracker) {
            if (tracker) {
              setCookie('mc_leads_ga_client_id', tracker.get('clientId'), 14);
            }
          });
        }
      } catch (e) {}
    }, 3000);
  }

  /**
   * Meta Pixel async script load
   */
  function loadMetaPixel(pixelId) {
    if (window.MCLeadsPixelLoaded) return;
    window.MCLeadsPixelLoaded = true;

    !(function (f, b, e, v, n, t, s) {
      if (f.fbq) return;
      n = f.fbq = function () {
        n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments);
      };
      if (!f._fbq) f._fbq = n;
      n.push = n;
      n.loaded = !0;
      n.version = '2.0';
      n.queue = [];
      t = b.createElement(e);
      t.async = !0;
      t.src = v;
      s = b.getElementsByTagName(e)[0];
      s.parentNode.insertBefore(t, s);
    })(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');

    fbq('init', pixelId);
    fbq('track', 'PageView');
  }

  /**
   * Extract UTM details from query string and cache them in cookies
   */
  function captureUtmParams() {
    const urlParams = new URLSearchParams(window.location.search);
    const utmFields = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
    
    let captured = false;
    utmFields.forEach(field => {
      const val = urlParams.get(field);
      if (val) {
        setCookie('mc_leads_' + field, val, 14);
        captured = true;
      }
    });

    // Capture referrer if external
    const ref = document.referrer;
    if (ref) {
      try {
        const refUrl = new URL(ref);
        if (refUrl.hostname !== window.location.hostname) {
          setCookie('mc_leads_referrer', ref, 14);
        }
      } catch (e) {}
    }
  }

  /**
   * Observe core custom events and dispatch analytics metrics
   */
  function bindCoreEvents(consent) {
    // 1. Survey Started event
    document.addEventListener('mc_leads_survey_start', (e) => {
      const data = e.detail || {};
      
      if (consent.analytics && window.gtag) {
        gtag('event', 'mc_survey_start', {
          survey_id: data.surveyId,
          survey_title: data.surveyTitle || 'Survey'
        });
      }
      if (consent.marketing && window.fbq) {
        fbq('trackCustom', 'MCSurveyStart', {
          surveyId: data.surveyId
        });
      }
    });

    // 2. Survey Step Completed event
    document.addEventListener('mc_leads_survey_step', (e) => {
      const data = e.detail || {};
      
      if (consent.analytics && window.gtag) {
        gtag('event', 'mc_survey_step_completed', {
          survey_id: data.surveyId,
          step_number: data.step
        });
      }
      if (consent.marketing && window.fbq) {
        fbq('track', 'InitiateCheckout', {
          content_name: 'Survey Step ' + data.step,
          content_ids: [String(data.surveyId)]
        });
      }
    });

    // 3. Survey Estimate Submitted event
    document.addEventListener('mc_leads_survey_submit', (e) => {
      const data = e.detail || {};
      
      if (consent.analytics && window.gtag) {
        gtag('event', 'mc_survey_submit', {
          survey_id: data.surveyId,
          price_estimate: data.price,
          lead_quality_score: data.score
        });
      }
      if (consent.marketing && window.fbq) {
        fbq('track', 'Lead', {
          value: parseFloat(data.price || 0),
          currency: 'KES',
          content_name: 'Lead Submission',
          content_ids: [String(data.surveyId)]
        });
      }
    });

    // 4. Booking scheduled event
    document.addEventListener('mc_leads_booking_scheduled', (e) => {
      const data = e.detail || {};
      
      if (consent.analytics && window.gtag) {
        gtag('event', 'mc_booking_scheduled', {
          meeting_type: data.bookingType,
          booking_date: data.date,
          booking_time: data.time,
          booking_location: data.location
        });
      }
      if (consent.marketing && window.fbq) {
        fbq('track', 'Schedule', {
          content_name: data.bookingType,
          start_time: data.date + ' ' + data.time,
          content_category: 'Meeting Booking'
        });
      }
    });
  }

  /**
   * Set up WhatsApp link click conversion hooks
   */
  function initWhatsAppClickTracking(consent) {
    document.addEventListener('click', (e) => {
      const anchor = e.target.closest('a');
      if (!anchor) return;

      const href = anchor.getAttribute('href') || '';
      
      // Look for typical WhatsApp links
      if (href.includes('wa.me') || href.includes('api.whatsapp.com') || href.includes('send?phone=')) {
        if (consent.analytics && window.gtag) {
          gtag('event', 'mc_whatsapp_click', {
            whatsapp_url: href
          });
        }
        
        if (consent.marketing && window.fbq) {
          fbq('trackCustom', 'WhatsAppClick', {
            url: href
          });
        }
      }
    });
  }

})();
