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
  const form = container.querySelector('.mc-leads-engine-form');
  const hiddenAnswersField = container.querySelector('input[name="mc_answers_json"]');
  let currentStep = parseInt(container.dataset.currentStep || '1', 10);
  let saveTimer = null;

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

    const tempForm = document.createElement('form');
    tempForm.method = 'POST';
    tempForm.action = form.dataset.action || '';
    tempForm.style.display = 'none';

    // Copy all hidden inputs from the container (includes mc_answers_json)
    form.querySelectorAll('input[type="hidden"]').forEach((input) => {
      const clone = input.cloneNode(true);
      tempForm.appendChild(clone);
    });

    document.body.appendChild(tempForm);
    tempForm.submit();
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
              
              // Set up one-time event listeners on the form to check results
              const handleMailSent = () => {
                cleanupListeners();
                // CF7 submitted successfully, now submit the main survey!
                submitFormPayload();
              };
              
              const handleInvalid = () => {
                cleanupListeners();
                // CF7 validation failed, flip back to the step containing the form
                const parentStep = cf7Form.closest('.mc-leads-engine-step');
                if (parentStep) {
                  const stepVal = parseInt(parentStep.dataset.step, 10);
                  if (!isNaN(stepVal)) {
                    setVisibleStep(stepVal);
                  }
                }
              };
              
              const cleanupListeners = () => {
                cf7Form.removeEventListener('wpcf7mailsent', handleMailSent);
                cf7Form.removeEventListener('wpcf7invalid', handleInvalid);
              };
              
              cf7Form.addEventListener('wpcf7mailsent', handleMailSent);
              cf7Form.addEventListener('wpcf7invalid', handleInvalid);
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

  function refresh() {
    wireInputs();
    wireNavigation();
    wireStandardSubmit();
    updateProgress();
    updateOptionStates();
    syncHiddenAnswerPayload(collectAnswers());
    saveProgress();
    injectCf7Fields();
    setVisibleStep(getVisibleStep());
    checkStepValidation();
  }

  return {
    refresh,
  };
}
