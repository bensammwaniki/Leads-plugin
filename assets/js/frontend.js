document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.mc-leads-engine').forEach((container) => {
    const state = initSurvey(container);
    if (state) {
      state.refresh();
    }
  });
});

function initSurvey(container) {
  const steps = Array.from(container.querySelectorAll('.mc-leads-engine-step'));
  const totalSteps = parseInt(container.dataset.totalSteps || steps.length || '1', 10);
  const mode = container.dataset.mode || 'standard';
  const surveyId = parseInt(container.dataset.surveyId || '0', 10);
  const sessionId = container.dataset.sessionId || '';
  let ajaxUrl = (window.MCLeadsEngine && window.MCLeadsEngine.ajaxUrl) || window.ajaxurl || '';
  const nonce = (window.MCLeadsEngine && window.MCLeadsEngine.nonce) || '';

  // Clear browser-autofilled/cached form inputs if the session is brand new
  if (container.dataset.clearOnLoad === '1') {
    container.querySelectorAll('input, textarea, select').forEach(field => {
      const type = (field.type || '').toLowerCase();
      if (type === 'radio' || type === 'checkbox') {
        field.checked = false;
        const parentOption = field.closest('.mc-option');
        if (parentOption) {
          parentOption.classList.remove('mc-option-checked');
        }
      } else if (field.tagName === 'SELECT') {
        field.selectedIndex = 0;
      } else if (field.type !== 'hidden' && field.type !== 'submit') {
        field.value = '';
      }
    });
    
    // Also reset Contact Form 7 form if present
    const cf7Form = container.querySelector('.wpcf7 form');
    if (cf7Form) {
      cf7Form.reset();
    }
  }

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
  const progressFill = container.querySelector('.mc-progress-fill');
  const progressStep = container.querySelector('.mc-progress-step');
  const priceDisplay = container.querySelector('.mc-live-price');
  const scoreDisplay = container.querySelector('.mc-live-score');
  const finalSessionField = container.querySelector('.mc-final-session-id');
  const form = container.querySelector('.mc-leads-engine-form, .mc-leads-engine-flow');
  const hiddenAnswersField = container.querySelector('input[name="mc_answers_json"]');
  let currentStep = parseInt(container.dataset.currentStep || '1', 10);
  let saveTimer = null;
  let startedEventDispatched = false;

  function getVisibleStep() {
    return Math.min(Math.max(currentStep, 1), totalSteps);
  }

  function setVisibleStep(step) {
    currentStep = Math.min(Math.max(step, 1), totalSteps);
    container.dataset.currentStep = String(currentStep);

    steps.forEach((panel) => {
      panel.hidden = parseInt(panel.dataset.step || '1', 10) !== currentStep;
    });

    updateProgress();
    injectCf7Fields();
    checkStepValidation();

    const backBtn = container.querySelector('.mc-back-btn');
    if (backBtn) {
      backBtn.style.display = currentStep > 1 ? 'inline-flex' : 'none';
    }

    // Dispatch step completed custom event
    document.dispatchEvent(new CustomEvent('mc_leads_survey_step', {
      detail: { surveyId: surveyId, step: currentStep }
    }));

    // Dispatch start custom event on step 1
    if (currentStep === 1 && !startedEventDispatched) {
      document.dispatchEvent(new CustomEvent('mc_leads_survey_start', {
        detail: {
          surveyId: surveyId,
          surveyTitle: container.querySelector('.mc-leads-title, .mc-leads-engine-title')?.textContent.trim() || 'Survey'
        }
      }));
      startedEventDispatched = true;
    }
  }

  function updateProgress() {
    const pct = (currentStep / Math.max(1, totalSteps)) * 100;
    if (progressFill) {
      progressFill.style.width = `${pct}%`;
    }
    if (progressStep) {
      progressStep.textContent = `Step ${currentStep} of ${totalSteps}`;
    }
  }

  function collectAnswers() {
    const answers = {};
    const fields = container.querySelectorAll('.mc-leads-engine-section [data-question-id]');

    fields.forEach((field) => {
      const questionId = field.dataset.questionId;
      const type = (field.type || field.dataset.questionType || '').toLowerCase();

      if (type === 'radio') {
        if (field.checked) {
          answers[questionId] = field.value;
        }
        return;
      }

      if (type === 'checkbox') {
        if (!answers[questionId]) {
          answers[questionId] = [];
        }
        if (field.checked) {
          answers[questionId].push(field.value);
        }
        return;
      }

      if (field.tagName === 'TEXTAREA' || field.tagName === 'SELECT' || field.tagName === 'INPUT') {
        const value = field.value;
        if (value !== '') {
          answers[questionId] = value;
        }
      }
    });

    return answers;
  }

  function syncHiddenAnswerPayload(answers) {
    if (hiddenAnswersField) {
      hiddenAnswersField.value = JSON.stringify(answers);
    }
  }

  function updatePricing(pricing) {
    if (!pricing) {
      return;
    }

    if (priceDisplay && typeof pricing.total_price !== 'undefined') {
      priceDisplay.textContent = Number(pricing.total_price).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    if (scoreDisplay && typeof pricing.lead_score !== 'undefined') {
      scoreDisplay.textContent = String(pricing.lead_score);
    }
  }

  function injectCf7Fields() {
    if (mode !== 'cf7') {
      return;
    }

    const formEl = container.querySelector('.wpcf7 form');
    if (!formEl) {
      return;
    }

    let sessionField = formEl.querySelector('input[name="mc_leads_session_id"]');
    if (!sessionField) {
      sessionField = document.createElement('input');
      sessionField.type = 'hidden';
      sessionField.name = 'mc_leads_session_id';
      formEl.appendChild(sessionField);
    }
    sessionField.value = sessionId;

    let surveyField = formEl.querySelector('input[name="mc_leads_survey_id"]');
    if (!surveyField) {
      surveyField = document.createElement('input');
      surveyField.type = 'hidden';
      surveyField.name = 'mc_leads_survey_id';
      formEl.appendChild(surveyField);
    }
    surveyField.value = String(surveyId);
  }

  function saveProgress() {
    if (!ajaxUrl || !nonce || !surveyId) {
      return Promise.resolve();
    }

    const answers = collectAnswers();
    syncHiddenAnswerPayload(answers);

    const payload = new URLSearchParams();
    payload.set('action', 'mc_leads_engine_save_progress');
    payload.set('nonce', nonce);
    payload.set('survey_id', String(surveyId));
    payload.set('session_id', sessionId);
    payload.set('current_step', String(currentStep));
    payload.set('answers', JSON.stringify(answers));

    return fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
      body: payload.toString(),
    })
      .then((response) => response.json())
      .then((response) => {
        if (response && response.success && response.data) {
          updatePricing(response.data.pricing);
        }
      })
      .catch(() => {});
  }

  function debounceSave() {
    window.clearTimeout(saveTimer);
    saveTimer = window.setTimeout(saveProgress, 300);
  }

  function updateOptionStates() {
    container.querySelectorAll('.mc-option').forEach((label) => {
      const input = label.querySelector('input[type="radio"], input[type="checkbox"]');
      if (input) {
        if (input.checked) {
          label.classList.add('mc-option-checked');
        } else {
          label.classList.remove('mc-option-checked');
        }
      }
    });
  }

  function checkStepValidation() {
    const activeStepEl = container.querySelector('.mc-leads-engine-step:not([hidden])');
    if (!activeStepEl) {
      return;
    }

    const nextBtn = activeStepEl.querySelector('.mc-step-next');
    const submitBtn = activeStepEl.querySelector('.mc-submit-survey');
    const actionBtn = nextBtn || submitBtn;
    if (!actionBtn) {
      return;
    }

    let isValid = true;

    // 1. Check standard HTML5 required inputs
    const requiredInputs = Array.from(activeStepEl.querySelectorAll('[required]'));
    
    // Group elements by name to validate them as groups
    const radioGroups = {};
    const checkboxGroups = {};

    requiredInputs.forEach((input) => {
      const name = input.name;
      const type = (input.type || '').toLowerCase();

      if (type === 'radio') {
        if (!radioGroups[name]) {
          radioGroups[name] = [];
        }
        radioGroups[name].push(input);
      } else if (type === 'checkbox') {
        if (!checkboxGroups[name]) {
          checkboxGroups[name] = [];
        }
        checkboxGroups[name].push(input);
      } else {
        if (input.value.trim() === '') {
          isValid = false;
        }
      }
    });

    for (const name in radioGroups) {
      const group = radioGroups[name];
      const hasChecked = group.some((radio) => radio.checked);
      if (!hasChecked) {
        isValid = false;
      }
    }

    for (const name in checkboxGroups) {
      const group = checkboxGroups[name];
      const hasChecked = group.some((cb) => cb.checked);
      if (!hasChecked) {
        isValid = false;
      }
    }

    // 2. Check Contact Form 7 required fields if present in the step
    const cf7Required = Array.from(activeStepEl.querySelectorAll('.wpcf7-validates-as-required, [aria-required="true"]'));
    cf7Required.forEach((input) => {
      const type = (input.type || '').toLowerCase();
      if (type === 'radio') {
        const name = input.name;
        const group = Array.from(activeStepEl.querySelectorAll(`input[name="${name}"]`));
        const hasChecked = group.some((radio) => radio.checked);
        if (!hasChecked) {
          isValid = false;
        }
      } else if (type === 'checkbox') {
        const name = input.name;
        const group = Array.from(activeStepEl.querySelectorAll(`input[name="${name}"]`));
        const hasChecked = group.some((cb) => cb.checked);
        if (!hasChecked) {
          isValid = false;
        }
      } else {
        if (input.value.trim() === '') {
          isValid = false;
        }
      }
    });

    if (isValid) {
      actionBtn.disabled = false;
      actionBtn.removeAttribute('disabled');
      actionBtn.classList.remove('mc-button-disabled');
    } else {
      actionBtn.disabled = true;
      actionBtn.setAttribute('disabled', 'true');
      actionBtn.classList.add('mc-button-disabled');
    }
  }

  function wireInputs() {
    const fields = container.querySelectorAll('.mc-leads-engine-section [name^="mc_answers"], .wpcf7 [name]');
    fields.forEach((field) => {
      field.addEventListener('change', () => {
        updateOptionStates();
        checkStepValidation();
        debounceSave();
      });
      if (field.tagName === 'INPUT' || field.tagName === 'TEXTAREA' || field.tagName === 'SELECT') {
        field.addEventListener('input', () => {
          checkStepValidation();
          debounceSave();
        });
      }
    });
  }

  function wireNavigation() {
    container.querySelectorAll('.mc-step-next').forEach((button) => {
      button.addEventListener('click', () => {
        saveProgress().finally(() => setVisibleStep(Math.min(totalSteps, currentStep + 1)));
      });
    });

    container.querySelectorAll('.mc-step-prev').forEach((button) => {
      button.addEventListener('click', () => {
        saveProgress().finally(() => setVisibleStep(Math.max(1, currentStep - 1)));
      });
    });
  }

  function submitFormPayload() {
    // Sync the latest answers into the hidden field immediately before submit
    const latestAnswers = collectAnswers();
    syncHiddenAnswerPayload(latestAnswers);

    // Dispatch survey submit custom event
    document.dispatchEvent(new CustomEvent('mc_leads_survey_submit', {
      detail: {
        surveyId: surveyId,
        price: priceDisplay ? parseFloat(priceDisplay.textContent.replace(/[^0-9.]/g, '')) : 0,
        score: scoreDisplay ? parseInt(scoreDisplay.textContent, 10) : 0
      }
    }));

    showLoadingOverlay('Saving your estimate...');

    const data = new URLSearchParams();
    data.append('action', 'mc_leads_engine_submit_survey_ajax');
    data.append('nonce', MCLeadsEngine.nonce);
    
    // Copy all hidden inputs from the container to data
    form.querySelectorAll('input[type="hidden"]').forEach((input) => {
      data.append(input.name, input.value);
    });

    data.append('base_url', window.location.href);

    fetch(MCLeadsEngine.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: data
    })
    .then(r => r.json())
    .then(response => {
      if (response.success && response.data.html) {
        container.innerHTML = response.data.html;
      } else {
        hideLoadingOverlay();
        alert(response.data?.message || 'Error submitting the survey. Please try again.');
      }
    })
    .catch(() => {
      hideLoadingOverlay();
      alert('A network error occurred. Please try again.');
    });
  }

  function wireStandardSubmit() {
    if (!form) {
      return;
    }

    const submitBtn = container.querySelector('.mc-submit-survey');
    if (submitBtn) {
      submitBtn.addEventListener('click', (event) => {
        event.preventDefault();
        syncHiddenAnswerPayload(collectAnswers());

        if (currentStep < totalSteps) {
          saveProgress().finally(() => setVisibleStep(currentStep + 1));
        } else {
          // If there is an embedded CF7 form inside the survey steps
          const cf7Form = container.querySelector('.wpcf7 form');
          if (cf7Form) {
            const cf7Submit = cf7Form.querySelector('.wpcf7-submit');
            if (cf7Submit) {
              // Click the hidden CF7 submit button programmatically
              cf7Submit.click();
              
              // Global listeners will handle wpcf7mailsent and wpcf7invalid
            } else {
              submitFormPayload();
            }
          } else {
            submitFormPayload();
          }
        }
      });
    }
  }

  // Listen to CF7 events to manage loading states and redirects in survey
  function showLoadingOverlay(message) {
    let overlay = container.querySelector('.mc-leads-engine-loading-overlay');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.className = 'mc-leads-engine-loading-overlay';
      
      const spinner = document.createElement('div');
      spinner.className = 'mc-spinner';
      overlay.appendChild(spinner);
      
      const msgEl = document.createElement('div');
      msgEl.className = 'mc-loading-msg';
      overlay.appendChild(msgEl);
      
      const card = container.querySelector('.mc-leads-engine-card');
      if (card) {
        card.appendChild(overlay);
      }
    }
    const msgEl = overlay.querySelector('.mc-loading-msg');
    if (msgEl) {
      msgEl.textContent = message;
    }
  }

  function hideParentScrollbars() {
    let el = container.parentElement;
    let limit = 10;
    while (el && el !== document.body && limit > 0) {
      const style = window.getComputedStyle(el);
      if (style.overflowY === 'auto' || style.overflowY === 'scroll') {
        el.style.setProperty('scrollbar-width', 'none', 'important');
        el.style.setProperty('-ms-overflow-style', 'none', 'important');
        el.classList.add('mc-hide-scrollbars');
      }
      el = el.parentElement;
      limit--;
    }
  }

  function hideLoadingOverlay() {
    const overlay = container.querySelector('.mc-leads-engine-loading-overlay');
    if (overlay) {
      overlay.remove();
    }
  }

  document.addEventListener('wpcf7beforesubmit', (event) => {
    const cf7Wrapper = container.querySelector('.wpcf7');
    if (cf7Wrapper && (event.target === cf7Wrapper || cf7Wrapper.contains(event.target))) {
      showLoadingOverlay('Saving your estimate...');
    }
  }, false);

  document.addEventListener('wpcf7mailsent', (event) => {
    const cf7Wrapper = container.querySelector('.wpcf7');
    if (cf7Wrapper && (event.target === cf7Wrapper || cf7Wrapper.contains(event.target))) {
      showLoadingOverlay('Estimate confirmed! Loading your summary...');
      
      const priceVal = priceDisplay ? parseFloat(priceDisplay.textContent.replace(/[^0-9.]/g, '')) : 0;
      const scoreVal = scoreDisplay ? parseInt(scoreDisplay.textContent, 10) : 0;
      
      document.dispatchEvent(new CustomEvent('mc_leads_survey_submit', {
        detail: {
          surveyId: surveyId,
          price: priceVal,
          score: scoreVal
        }
      }));

      const responseLeadId = event.detail.apiResponse?.mc_lead_id;
      const leadId = responseLeadId ? responseLeadId : 'active';
      
      const data = new URLSearchParams();
      data.append('action', 'mc_leads_engine_get_thank_you');
      data.append('nonce', MCLeadsEngine.nonce);
      data.append('survey_id', surveyId);
      data.append('lead_id', leadId);
      data.append('base_url', window.location.href);

      fetch(MCLeadsEngine.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: data
      })
      .then(r => r.json())
      .then(response => {
        if (response.success && response.data.html) {
          container.innerHTML = response.data.html;
        } else {
          hideLoadingOverlay();
          alert('Successfully submitted, but could not load the thank you screen.');
        }
      })
      .catch((e) => {
        console.error('Fetch error:', e);
        hideLoadingOverlay();
        alert('An error occurred loading the thank you screen.');
      });
    }
  }, false);

  document.addEventListener('wpcf7invalid', (event) => {
    const cf7Wrapper = container.querySelector('.wpcf7');
    if (cf7Wrapper && (event.target === cf7Wrapper || cf7Wrapper.contains(event.target))) {
      hideLoadingOverlay();
      alert('The contact form could not be submitted. Please check your answers and try again.');
    }
  }, false);

  document.addEventListener('wpcf7spam', (event) => {
    const cf7Wrapper = container.querySelector('.wpcf7');
    if (cf7Wrapper && (event.target === cf7Wrapper || cf7Wrapper.contains(event.target))) {
      hideLoadingOverlay();
    }
  }, false);

  document.addEventListener('wpcf7mailfailed', (event) => {
    const cf7Wrapper = container.querySelector('.wpcf7');
    if (cf7Wrapper && (event.target === cf7Wrapper || cf7Wrapper.contains(event.target))) {
      hideLoadingOverlay();
    }
  }, false);

  function refresh() {
    wireInputs();
    wireNavigation();
    wireStandardSubmit();
    updateProgress();
    updateOptionStates();
    syncHiddenAnswerPayload(collectAnswers());
    saveProgress();
    updateOptionStates();
    setVisibleStep(getVisibleStep());
    hideParentScrollbars();
    checkStepValidation();
  }

  return {
    refresh,
  };
}
