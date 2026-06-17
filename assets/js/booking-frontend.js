document.addEventListener('DOMContentLoaded', () => {
  const container = document.querySelector('.mc-booking-engine');
  if (container) {
    initBookingWizard(container);
  }
});

function initBookingWizard(container) {
  const steps = Array.from(container.querySelectorAll('.mc-booking-step'));
  const progressFill = container.querySelector('.mc-progress-fill');
  const progressLabels = Array.from(container.querySelectorAll('.mc-progress-labels .step-label'));
  const nextBtns = Array.from(container.querySelectorAll('.mc-next-btn'));
  const prevBtns = Array.from(container.querySelectorAll('.mc-prev-btn'));

  const cf7Id = container.dataset.cf7Id;
  const sessionId = container.dataset.sessionId;
  let ajaxUrl = window.MCLeadsBooking?.ajaxUrl || '';
  let restUrl = window.MCLeadsBooking?.restUrl || '';
  const nonce = window.MCLeadsBooking?.nonce || '';
  const restNonce = window.MCLeadsBooking?.restNonce || '';
  const gmapsKey = window.MCLeadsBooking?.gmapsKey || '';

  // Resolve hostname mismatch when testing on mobile devices in local network
  if (ajaxUrl && ajaxUrl.startsWith('http')) {
    try {
      const urlObj = new URL(ajaxUrl);
      if (urlObj.hostname !== window.location.hostname) {
        urlObj.hostname = window.location.hostname;
        urlObj.port = window.location.port;
        urlObj.protocol = window.location.protocol;
        ajaxUrl = urlObj.toString();
      }
    } catch (e) {
      console.error('Error resolving AJAX URL hostname:', e);
    }
  }
  if (restUrl && restUrl.startsWith('http')) {
    try {
      const urlObj = new URL(restUrl);
      if (urlObj.hostname !== window.location.hostname) {
        urlObj.hostname = window.location.hostname;
        urlObj.port = window.location.port;
        urlObj.protocol = window.location.protocol;
        restUrl = urlObj.toString();
      }
    } catch (e) {
      console.error('Error resolving REST URL hostname:', e);
    }
  }

  // Booking State
  const state = {
    currentStep: 1,
    meetingType: '',
    locationType: '',
    locationName: '',
    locationAddress: '',
    selectedDate: '',
    selectedTime: '',
    clientName: '',
    clientEmail: '',
    clientPhone: '',
    clientMessage: ''
  };

  // Calendar State
  let calDate = new Date();
  if (calDate.getHours() >= 17) {
    calDate.setDate(calDate.getDate() + 1); // Select tomorrow if late
  }

  // 1. Setup Card Clicks (Step 1)
  const typeCards = Array.from(container.querySelectorAll('.mc-type-card'));
  typeCards.forEach(card => {
    card.addEventListener('click', () => {
      typeCards.forEach(c => c.classList.remove('selected'));
      card.classList.add('selected');
      state.meetingType = card.dataset.value;
      
      // Update location default configs based on type
      if (state.meetingType === 'online') {
        state.locationType = 'predefined';
        state.locationName = 'Google Meet / Zoom';
        state.locationAddress = 'Online Call Link';
      } else if (state.meetingType === 'host') {
        state.locationType = 'predefined';
        state.locationName = 'Memories Creative Studio';
        state.locationAddress = 'General Suite 104, Prestige Plaza, Ngong Road, Nairobi';
      } else {
        state.locationType = '';
        state.locationName = '';
        state.locationAddress = '';
      }

      // Enable Step 1 next button
      const step1Next = container.querySelector('.mc-booking-step[data-step="1"] .mc-next-btn');
      if (step1Next) {
        step1Next.disabled = false;
        step1Next.removeAttribute('disabled');
      }
    });
  });

  // 2. Navigation Actions
  nextBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      if (state.currentStep === 2) {
        // Validate Step 2 inputs
        if (!validateStep2()) return;
      }
      if (state.currentStep === 3) {
        // Validate Step 3 inputs
        if (!state.selectedDate || !state.selectedTime) return;
        updateSummaryBanner();
        injectCF7HiddenFields();
      }
      changeStep(state.currentStep + 1);
    });
  });

  const backBtnRound = container.querySelector('.mc-back-btn-round');
  if (backBtnRound) {
    backBtnRound.addEventListener('click', () => {
      changeStep(state.currentStep - 1);
    });
  }

  function changeStep(step) {
    state.currentStep = Math.min(Math.max(step, 1), 4);
    
    if (backBtnRound) {
      if (state.currentStep > 1) {
        backBtnRound.style.display = 'flex';
        container.classList.add('has-back-button');
      } else {
        backBtnRound.style.display = 'none';
        container.classList.remove('has-back-button');
      }
    }
    
    // Toggle Step visibility
    steps.forEach(s => {
      s.hidden = parseInt(s.dataset.step, 10) !== state.currentStep;
      if (parseInt(s.dataset.step, 10) === state.currentStep) {
        s.classList.add('active');
      } else {
        s.classList.remove('active');
      }
    });

    // Update progress elements
    const pct = ((state.currentStep) / 4) * 100;
    if (progressFill) progressFill.style.width = `${pct}%`;

    progressLabels.forEach(lbl => {
      const sNum = parseInt(lbl.dataset.step, 10);
      if (sNum <= state.currentStep) {
        lbl.classList.add('active');
      } else {
        lbl.classList.remove('active');
      }
    });

    // Trigger step-specific logic
    if (state.currentStep === 2) {
      initStep2();
    } else if (state.currentStep === 3) {
      initStep3Calendar();
    }
  }

  // 3. Step 2 Logic (Location Selection)
  const locPanes = Array.from(container.querySelectorAll('.mc-loc-pane'));
  const predefinedSelect = container.querySelector('.mc-predefined-select');
  const customAddressInput = container.querySelector('.mc-custom-address');

  // Track whether Places autocomplete has been attached to the input
  let autocompleteAttached = false;

  /**
   * Attach Google Maps Places Autocomplete to the custom address input.
   * Called either immediately (if Maps is already loaded) or from the
   * MCLeadsBookingMapsReady global callback when the async script finishes.
   */
  function attachPlacesAutocomplete() {
    if (autocompleteAttached || !customAddressInput) return;
    if (typeof google === 'undefined' || !google.maps || !google.maps.places) return;

    autocompleteAttached = true;

    const step2Next = container.querySelector('.mc-booking-step[data-step="2"] .mc-next-btn');

    const autocomplete = new google.maps.places.Autocomplete(customAddressInput, {
      // Request both address and establishment results so business names work too
      types: [],
    });

    // Bias results towards Kenya (adjust if your clients are elsewhere)
    autocomplete.setComponentRestrictions({ country: 'ke' });

    autocomplete.addListener('place_changed', () => {
      const place = autocomplete.getPlace();
      const addr = place.formatted_address || customAddressInput.value;
      if (addr) {
        state.locationAddress = addr;
        state.locationName = place.name || 'Client Office';
        state.locationType = 'custom';
        if (step2Next) {
          step2Next.disabled = false;
          step2Next.removeAttribute('disabled');
        }
      }
    });
  }

  // Global callback invoked by the Maps async loader when ready.
  // Must be a window-level function because the Maps script calls it by name.
  window.MCLeadsBookingMapsReady = function () {
    attachPlacesAutocomplete();
  };

  function initStep2() {
    // Show correct pane
    locPanes.forEach(pane => {
      pane.hidden = pane.dataset.type !== state.meetingType;
    });

    const step2Next = container.querySelector('.mc-booking-step[data-step="2"] .mc-next-btn');
    if (state.meetingType === 'online' || state.meetingType === 'host') {
      step2Next.disabled = false;
      step2Next.removeAttribute('disabled');
    } else {
      step2Next.disabled = true;
      step2Next.setAttribute('disabled', 'true');
    }

    // For office type, try to attach autocomplete immediately.
    // If Maps hasn't loaded yet it will be attached by MCLeadsBookingMapsReady.
    if (state.meetingType === 'office' && gmapsKey) {
      attachPlacesAutocomplete();
      // Focus the input so the user can start typing right away
      if (customAddressInput) {
        customAddressInput.focus();
      }
    }

    // For coffee type: the pane now has BOTH a select and a custom text input.
    // Attach Places autocomplete to the custom input so suggestions appear on keyup.
    if (state.meetingType === 'coffee' && gmapsKey) {
      attachPlacesAutocomplete();
    }
  }

  // Single location input event — handles both coffee and office panes.
  // For coffee: datalist provides predefined suggestions; Google Maps autocomplete
  // provides additional search. Both feed into the same field.
  if (customAddressInput) {
    customAddressInput.addEventListener('input', () => {
      const val = customAddressInput.value.trim();
      const step2Next = container.querySelector('.mc-booking-step[data-step="2"] .mc-next-btn');
      if (val.length > 3) {
        const label = state.meetingType === 'coffee' ? 'Coffee Spot' : 'Client Office';
        state.locationName = label;
        state.locationAddress = val;
        state.locationType = 'custom';
        step2Next.disabled = false;
        step2Next.removeAttribute('disabled');
      } else {
        state.locationName = '';
        state.locationAddress = '';
        state.locationType = '';
        step2Next.disabled = true;
        step2Next.setAttribute('disabled', 'true');
      }
    });
  }

  function validateStep2() {
    if (state.meetingType === 'online' || state.meetingType === 'host') {
      return true;
    }
    // Both coffee and office now use the single customAddressInput field
    return customAddressInput && customAddressInput.value.trim().length > 3;
  }

  // 4. Step 3 Logic (Calendar and time slots)
  const monthYearLabel = container.querySelector('.mc-cal-month-year');
  const prevMonthBtn = container.querySelector('.mc-cal-nav.prev');
  const nextMonthBtn = container.querySelector('.mc-cal-nav.next');
  const daysGrid = container.querySelector('.mc-calendar-days');
  const slotsGrid = container.querySelector('.mc-slots-grid');

  let activeMonth = calDate.getMonth();
  let activeYear = calDate.getFullYear();

  function initStep3Calendar() {
    renderCalendar();
    
    // Calendar month routing
    prevMonthBtn.onclick = () => {
      activeMonth--;
      if (activeMonth < 0) {
        activeMonth = 11;
        activeYear--;
      }
      renderCalendar();
    };

    nextMonthBtn.onclick = () => {
      activeMonth++;
      if (activeMonth > 11) {
        activeMonth = 0;
        activeYear++;
      }
      renderCalendar();
    };
  }

  function renderCalendar() {
    const monthNames = [
      'January', 'February', 'March', 'April', 'May', 'June',
      'July', 'August', 'September', 'October', 'November', 'December'
    ];
    if (monthYearLabel) {
      monthYearLabel.textContent = `${monthNames[activeMonth]} ${activeYear}`;
    }

    if (!daysGrid) return;
    daysGrid.innerHTML = '';

    const firstDayIndex = new Date(activeYear, activeMonth, 1).getDay(); // 0 (Sun) - 6 (Sat)
    // Adjust index to start on Monday (0 index = Mon, 6 index = Sun)
    let adjustedFirstDay = firstDayIndex - 1;
    if (adjustedFirstDay < 0) adjustedFirstDay = 6;

    const lastDay = new Date(activeYear, activeMonth + 1, 0).getDate();
    const today = new Date();
    today.setHours(0,0,0,0);

    // Empty spaces for previous month's padding
    for (let i = 0; i < adjustedFirstDay; i++) {
      const empty = document.createElement('div');
      empty.className = 'mc-cal-day empty';
      daysGrid.appendChild(empty);
    }

    // Month days
    for (let day = 1; day <= lastDay; day++) {
      const dayEl = document.createElement('div');
      dayEl.className = 'mc-cal-day';
      dayEl.textContent = String(day);

      const dStr = `${activeYear}-${String(activeMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
      const dayDate = new Date(activeYear, activeMonth, day);
      
      // Working days filter (disable weekends by default, unless configured otherwise)
      const dayOfWeek = dayDate.getDay(); // 0 (Sun) - 6 (Sat)
      const normalizedDayOfWeek = dayOfWeek === 0 ? '7' : String(dayOfWeek);
      const bookingDays = ['1', '2', '3', '4', '5']; // Default Mon-Fri

      const isWorkingDay = bookingDays.includes(normalizedDayOfWeek);

      if (dayDate < today || !isWorkingDay) {
        dayEl.classList.add('disabled');
      } else {
        if (state.selectedDate === dStr) {
          dayEl.classList.add('selected');
        }
        dayEl.addEventListener('click', () => {
          container.querySelectorAll('.mc-cal-day').forEach(d => d.classList.remove('selected'));
          dayEl.classList.add('selected');
          state.selectedDate = dStr;
          state.selectedTime = ''; // Clear slot
          
          // Disable Step 3 Next button until slot is chosen
          const step3Next = container.querySelector('.mc-booking-step[data-step="3"] .mc-next-btn');
          if (step3Next) {
            step3Next.disabled = true;
            step3Next.setAttribute('disabled', 'true');
          }

          loadAvailableSlots(dStr);
        });
      }

      daysGrid.appendChild(dayEl);
    }
  }

  function loadAvailableSlots(dateStr) {
    if (!slotsGrid) return;
    slotsGrid.innerHTML = '<p class="no-slots-msg">Loading slots...</p>';

    // Use the REST API endpoint instead of admin-ajax.php.
    // admin-ajax.php lives under /wp-admin/ which Hostinger's LiteSpeed security
    // layer intercepts for unauthenticated (non-Chrome) browsers and returns an
    // HTML redirect page — breaking JSON parsing and showing "Error loading slots".
    // /wp-json/ is a public frontend URL that LiteSpeed never blocks.
    const endpoint = (restUrl || ajaxUrl)
      .replace(/\/$/, '') + (restUrl ? '/slots' : '') +
      '?_t=' + Date.now();

    const isRest = !!restUrl;

    const fetchOptions = isRest
      ? {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': restNonce,
            'Cache-Control': 'no-cache',
          },
          body: JSON.stringify({ date: dateStr }),
        }
      : {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'Cache-Control': 'no-cache',
          },
          body: new URLSearchParams({ action: 'mc_leads_booking_slots', nonce, date: dateStr }).toString(),
        };

    fetch(endpoint, fetchOptions)
      .then(res => {
        const ct = res.headers.get('content-type') || '';
        if (!ct.includes('json')) {
          throw new Error('Non-JSON response (likely a cached page). Status: ' + res.status);
        }
        return res.json();
      })
      .then(res => {
        slotsGrid.innerHTML = '';

        // REST API returns { slots: [...] } directly.
        // admin-ajax returns { success: true, data: { slots: [...] } }.
        const slots = isRest
          ? (res.slots || [])
          : (res.success && res.data ? res.data.slots || [] : []);

        if (slots.length > 0) {
          slots.forEach(slot => {
            const slotEl = document.createElement('div');
            slotEl.className = 'mc-time-slot';
            slotEl.textContent = slot.time;
            if (state.selectedTime === slot.time) {
              slotEl.classList.add('selected');
            }

            slotEl.addEventListener('click', () => {
              container.querySelectorAll('.mc-time-slot').forEach(s => s.classList.remove('selected'));
              slotEl.classList.add('selected');
              state.selectedTime = slot.time;

              const step3Next = container.querySelector('.mc-booking-step[data-step="3"] .mc-next-btn');
              if (step3Next) {
                step3Next.disabled = false;
                step3Next.removeAttribute('disabled');
              }
            });

            slotsGrid.appendChild(slotEl);
          });
        } else {
          const msg = (!isRest && !res.success && res.data?.message)
            ? res.data.message
            : (res.message || 'No slots available for this day.');
          slotsGrid.innerHTML = '<p class="no-slots-msg">' + msg + '</p>';
        }
      })
      .catch(err => {
        console.error('MC Booking slots error:', err);
        slotsGrid.innerHTML = '<p class="no-slots-msg">Error loading slots. Please try again.</p>';
      });
  }

  // 5. Step 4 Summary & Hidden Inject
  const summaryDateTime = container.querySelector('.mc-summary-date-time');
  const summaryLoc = container.querySelector('.mc-summary-location');

  function updateSummaryBanner() {
    if (summaryDateTime) {
      summaryDateTime.textContent = `${state.selectedDate} @ ${state.selectedTime}`;
    }
    if (summaryLoc) {
      summaryLoc.textContent = state.locationAddress ? `${state.locationName} (${state.locationAddress})` : state.locationName;
    }
  }

  function injectCF7HiddenFields() {
    const cf7Form = container.querySelector('.wpcf7 form');
    if (!cf7Form) return;

    const dataMapping = {
      'mc_booking_type': state.meetingType,
      'mc_booking_location_type': state.locationType,
      'mc_booking_location_name': state.locationName,
      'mc_booking_location_address': state.locationAddress,
      'mc_booking_date': state.selectedDate,
      'mc_booking_time': state.selectedTime,
      'mc_leads_session_id': sessionId
    };

    for (const key in dataMapping) {
      let field = cf7Form.querySelector(`input[name="${key}"]`);
      if (!field) {
        field = document.createElement('input');
        field.type = 'hidden';
        field.name = key;
        cf7Form.appendChild(field);
      }
      field.value = dataMapping[key];
    }
  }

  // 6. Listen to CF7 successfully sent event to handle redirects
  document.addEventListener('wpcf7mailsent', (event) => {
    // Check if the submitted form matches the booking wizard's form
    if (String(event.detail.contactFormId) === String(cf7Id)) {
      const responseLeadId = event.detail.apiResponse?.mc_lead_id;
      const leadId = responseLeadId ? responseLeadId : 'active';
      // Redirect or show thank you page containing lead details
      const thankYouUrl = window.location.origin + window.location.pathname + `?mc_leads_submitted=1&lead_id=${leadId}`;
      window.location.href = thankYouUrl;
    }
  }, false);
}
