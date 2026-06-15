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
  const ajaxUrl = window.MCLeadsBooking?.ajaxUrl || '';
  const nonce = window.MCLeadsBooking?.nonce || '';
  const gmapsKey = window.MCLeadsBooking?.gmapsKey || '';

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

  prevBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      changeStep(state.currentStep - 1);
    });
  });

  function changeStep(step) {
    state.currentStep = Math.min(Math.max(step, 1), 4);
    
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

    // Load Maps Autocomplete if Maps Key exists and element is active
    if (state.meetingType === 'office' && gmapsKey && typeof google !== 'undefined') {
      const autocomplete = new google.maps.places.Autocomplete(customAddressInput, {
        types: ['address'],
        componentRestrictions: { country: 'KE' }
      });
      autocomplete.addListener('place_changed', () => {
        const place = autocomplete.getPlace();
        if (place.formatted_address) {
          state.locationAddress = place.formatted_address;
          state.locationName = place.name || 'Client Office';
          state.locationType = 'custom';
          step2Next.disabled = false;
          step2Next.removeAttribute('disabled');
        }
      });
    }
  }

  // Predefined Location Select Event
  if (predefinedSelect) {
    predefinedSelect.addEventListener('change', () => {
      const val = predefinedSelect.value;
      const step2Next = container.querySelector('.mc-booking-step[data-step="2"] .mc-next-btn');
      if (val) {
        state.locationName = val;
        state.locationAddress = val;
        state.locationType = 'predefined';
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

  // Custom Address text input event
  if (customAddressInput) {
    customAddressInput.addEventListener('input', () => {
      const val = customAddressInput.value;
      const step2Next = container.querySelector('.mc-booking-step[data-step="2"] .mc-next-btn');
      if (val.trim().length > 5) {
        state.locationName = 'Client Office';
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
    if (state.meetingType === 'coffee' && predefinedSelect.value === '') {
      return false;
    }
    if (state.meetingType === 'office' && customAddressInput.value.trim().length <= 5) {
      return false;
    }
    return true;
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

    const payload = new URLSearchParams();
    payload.set('action', 'mc_leads_booking_slots');
    payload.set('nonce', nonce);
    payload.set('date', dateStr);

    fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
      body: payload.toString()
    })
      .then(res => res.json())
      .then(res => {
        slotsGrid.innerHTML = '';
        if (res.success && res.data && res.data.slots && res.data.slots.length > 0) {
          res.data.slots.forEach(slot => {
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

              // Enable Step 3 next button
              const step3Next = container.querySelector('.mc-booking-step[data-step="3"] .mc-next-btn');
              if (step3Next) {
                step3Next.disabled = false;
                step3Next.removeAttribute('disabled');
              }
            });

            slotsGrid.appendChild(slotEl);
          });
        } else {
          slotsGrid.innerHTML = '<p class="no-slots-msg">No slots available for this day.</p>';
        }
      })
      .catch(() => {
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
      // Redirect or show thank you page containing lead session details
      const thankYouUrl = window.location.origin + window.location.pathname + `?mc_leads_submitted=1&lead_id=active`;
      window.location.href = thankYouUrl;
    }
  }, false);
}
