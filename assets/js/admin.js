document.addEventListener('DOMContentLoaded', () => {
  const shell = document.querySelector('.mc-admin-app');
  const topbarTitle = document.getElementById('topbar-title');

  const setActivePanel = (panelName, pushState = true) => {
    if (!shell || !panelName) {
      return;
    }

    shell.querySelectorAll('[data-admin-panel]').forEach((item) => {
      item.classList.toggle('active', item.dataset.adminPanel === panelName);
    });

    shell.querySelectorAll('.panel[data-panel]').forEach((panel) => {
      panel.classList.toggle('active', panel.dataset.panel === panelName);
    });

    if (topbarTitle) {
      const activeLink = shell.querySelector(`[data-admin-panel="${panelName}"]`);
      topbarTitle.textContent = activeLink ? (activeLink.dataset.panelTitle || activeLink.textContent.trim()) : topbarTitle.textContent;
    }

    if (pushState) {
      const url = new URL(window.location.href);
      url.searchParams.set('mc_panel', panelName);
      window.history.pushState({ mcPanel: panelName }, '', url.toString());
    }
  };

  document.querySelectorAll('[data-copy-shortcode]').forEach((button) => {
    button.addEventListener('click', async (event) => {
      event.preventDefault();

      const text = button.dataset.shortcode || (() => {
        const row = button.closest('.mc-shortcode-row');
        const input = row ? row.querySelector('input[readonly]') : null;
        return input ? input.value || '' : '';
      })();

      if (!text) {
        return;
      }

      const originalHtml = button.innerHTML;
      try {
        await navigator.clipboard.writeText(text);
        button.textContent = 'Copied';
      } catch (error) {
        const row = button.closest('.mc-shortcode-row');
        const input = row ? row.querySelector('input[readonly]') : null;
        if (input) {
          input.focus();
          input.select();
          document.execCommand('copy');
          button.textContent = 'Copied';
        }
      }

      window.setTimeout(() => {
        button.innerHTML = originalHtml;
      }, 1200);
    });
  });

  // Make survey cards clickable to navigate to edit page
  document.querySelectorAll('.survey-card').forEach((card) => {
    card.addEventListener('click', (event) => {
      if (event.target.closest('.survey-actions') || event.target.closest('[data-copy-shortcode]')) {
        return;
      }
      const editBtn = card.querySelector('.survey-actions a');
      if (editBtn) {
        window.location.href = editBtn.href;
      }
    });
  });

  if (shell) {
    shell.querySelectorAll('[data-admin-panel]').forEach((navItem) => {
      navItem.addEventListener('click', (event) => {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
          return;
        }
        event.preventDefault();
        setActivePanel(navItem.dataset.adminPanel);
      });
    });

    window.addEventListener('popstate', () => {
      const url = new URL(window.location.href);
      const panel = url.searchParams.get('mc_panel') || document.querySelector('.panel.active')?.dataset.panel || 'dashboard';
      setActivePanel(panel, false);
    });

    const url = new URL(window.location.href);
    const initialPanel = url.searchParams.get('mc_panel') || document.querySelector('.panel.active')?.dataset.panel || document.querySelector('.nav-item.active')?.dataset.adminPanel || 'dashboard';
    setActivePanel(initialPanel, false);
  }

  document.querySelectorAll('.q-card-delete').forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      if (confirm('Delete this question?')) {
        button.closest('form')?.submit();
      }
    });
  });

  document.querySelectorAll('.section-edit-trigger').forEach((button) => {
    button.addEventListener('click', () => {
      const item = button.closest('.section-item');
      if (!item) {
        return;
      }

      item.classList.add('editing');
      const input = item.querySelector('.section-title-input');
      if (input) {
        input.focus();
        input.select();
      }
    });
  });

  document.querySelectorAll('.section-title-input').forEach((input) => {
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        input.form.submit();
        return;
      }

      if (event.key === 'Escape' || event.key === 'Esc') {
        event.preventDefault();
        const original = input.dataset.originalTitle || '';
        input.value = original;
        input.closest('.section-item')?.classList.remove('editing');
        input.blur();
      }
    });

    input.addEventListener('blur', () => {
      const original = input.dataset.originalTitle || '';
      if (!input.value.trim()) {
        input.value = original;
        input.closest('.section-item')?.classList.remove('editing');
        return;
      }

      if (input.value !== original) {
        input.form.submit();
      } else {
        input.closest('.section-item')?.classList.remove('editing');
      }
    });
  });

  document.querySelectorAll('[data-option-builder]').forEach((builder) => {
    const list = builder.querySelector('[data-option-list]');
    const template = builder.querySelector('template[data-option-template]');
    const addButton = builder.querySelector('[data-add-option-row]');
    let nextIndex = parseInt(builder.dataset.nextIndex || '0', 10) || 0;

    if (!list || !template || !addButton) {
      return;
    }

    const addRow = () => {
      const markup = template.innerHTML.replace(/__INDEX__/g, String(nextIndex++));
      list.insertAdjacentHTML('beforeend', markup);
    };

    addButton.addEventListener('click', addRow);

    builder.addEventListener('click', (event) => {
      const removeButton = event.target.closest('[data-remove-option-row]');
      if (!removeButton) {
        return;
      }

      const row = removeButton.closest('[data-option-row]');
      if (row) {
        row.remove();
      }
    });
  });

  /* ── Pricing Rule Builder ─────────────────────────────────── */
  const pricingPanel = document.getElementById('panel-pricing');
  if (pricingPanel) {
    const TYPE_LABELS = { fixed: 'Fixed', per_unit: 'Per Unit', option: 'Option' };
    const TYPE_COLORS = { fixed: 'ri-blue', per_unit: 'ri-green', option: 'ri-warn' };
    const TYPE_SYMBOLS = { fixed: '+', per_unit: '×', option: '+' };

    // Load initial rules from PHP data bridge
    let mcRules = [];
    const rulesDataEl = document.getElementById('mc-pricing-rules-data');
    if (rulesDataEl) {
      try { mcRules = JSON.parse(rulesDataEl.value) || []; } catch (e) { mcRules = []; }
    }

    let editingIndex = -1; // -1 = adding new, N = editing existing

    const ruleList   = document.getElementById('mc-rule-list');
    const ruleForm   = document.getElementById('mc-rule-form');
    const addBtn     = document.getElementById('mc-add-rule-btn');
    const saveBtn    = document.getElementById('mc-rule-save-btn');
    const cancelBtn  = document.getElementById('mc-rule-cancel-btn');
    const basePriceEl = document.getElementById('mc-base-price');

    // Form fields
    const fName  = document.getElementById('prf-name');
    const fType  = document.getElementById('prf-type');
    const fMatch = document.getElementById('prf-match');
    const fAmt   = document.getElementById('prf-amount');
    const fScore = document.getElementById('prf-score');

    // Prevent Enter key in inputs from submitting the parent form and instead trigger save
    const pricingInputs = [fName, fMatch, fAmt, fScore];
    pricingInputs.forEach(input => {
      if (input) {
        input.addEventListener('keydown', (e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            if (saveBtn) {
              saveBtn.click();
            }
          }
        });
      }
    });

    if (basePriceEl) {
      basePriceEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          basePriceEl.blur(); // Triggers the change event and saves to server
        }
      });
    }

    const fmt = (n) => Number(n).toLocaleString('en-KE', { minimumFractionDigits: 0 });

    // ── Render rule cards ──
    const renderRules = () => {
      // Clear all rule cards (keep empty placeholder logic)
      ruleList.querySelectorAll('.rule-card').forEach(el => el.remove());
      let emptyEl = document.getElementById('mc-rule-empty');

      if (mcRules.length === 0) {
        if (!emptyEl) {
          emptyEl = document.createElement('div');
          emptyEl.className = 'pricing-empty';
          emptyEl.id = 'mc-rule-empty';
          emptyEl.innerHTML = '<span class="dashicons dashicons-tag"></span><span>No pricing rules yet. Click "Add Rule" to get started.</span>';
          ruleList.appendChild(emptyEl);
        }
        return;
      }

      if (emptyEl) emptyEl.remove();

      mcRules.forEach((rule, i) => {
        const type = rule.type || 'fixed';
        const color = TYPE_COLORS[type] || 'ri-blue';
        const symbol = TYPE_SYMBOLS[type] || '+';
        const card = document.createElement('div');
        card.className = 'rule-card';
        card.dataset.ruleIndex = i;
        card.innerHTML = `
          <div class="rule-icon ${color}"><span class="dashicons dashicons-money-alt"></span></div>
          <div class="rule-info">
            <div class="rule-name">${rule.name || 'Pricing rule'}</div>
            <div class="rule-desc">
              <span class="rule-type-badge badge-${type}">${TYPE_LABELS[type] || type}</span>
              ${rule.match ? `· <em>${rule.match}</em>` : ''}
            </div>
          </div>
          <div class="rule-val">${symbol} ${fmt(rule.amount || 0)}</div>
          <div class="rule-actions">
            <button type="button" class="icon-btn rule-edit-btn" data-index="${i}" title="Edit"><span class="dashicons dashicons-edit"></span></button>
            <button type="button" class="icon-btn del rule-del-btn" data-index="${i}" title="Delete"><span class="dashicons dashicons-trash"></span></button>
          </div>`;
        ruleList.appendChild(card);
      });
    };

    // ── Open/close form ──
    const openForm = (index = -1) => {
      editingIndex = index;
      if (index >= 0) {
        const r = mcRules[index];
        fName.value  = r.name  || '';
        fType.value  = r.type  || 'fixed';
        fMatch.value = r.match || '';
        fAmt.value   = r.amount !== undefined ? r.amount : '';
        fScore.value = r.score_impact !== undefined ? r.score_impact : '';
        saveBtn.textContent = 'Update Rule';
      } else {
        fName.value = fMatch.value = fAmt.value = fScore.value = '';
        fType.value = 'fixed';
        saveBtn.textContent = 'Save Rule';
      }
      ruleForm.style.display = 'block';
      fName.focus();
    };

    const closeForm = () => {
      ruleForm.style.display = 'none';
      editingIndex = -1;
    };

    // ── Toast notification ──
    const showToast = (msg, ok = true) => {
      const t = document.createElement('div');
      t.className = 'mc-toast' + (ok ? '' : ' mc-toast-error');
      t.textContent = msg;
      document.body.appendChild(t);
      setTimeout(() => t.remove(), 2800);
    };

    // ── AJAX save to server ──
    const saveToServer = () => {
      const nonce = (document.getElementById('mc-pricing-nonce') || {}).value || (window.mcLeadsEngine || {}).nonce || '';
      const ajaxUrl = (window.mcLeadsEngine || {}).ajaxUrl || '/wp-admin/admin-ajax.php';
      const basePrice = basePriceEl ? parseFloat(basePriceEl.value) || 0 : 0;

      const body = new URLSearchParams({
        action:     'mc_leads_engine_save_pricing_rules',
        nonce:      nonce,
        rules:      JSON.stringify(mcRules),
        base_price: basePrice,
      });

      fetch(ajaxUrl, { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            showToast('Rules saved ✓');
          } else {
            showToast('Save failed', false);
          }
        })
        .catch(() => showToast('Save failed', false));
    };

    // ── Event: Add rule button ──
    addBtn && addBtn.addEventListener('click', () => openForm(-1));

    // ── Event: Cancel ──
    cancelBtn && cancelBtn.addEventListener('click', closeForm);

    // ── Event: Save rule ──
    saveBtn && saveBtn.addEventListener('click', () => {
      const name  = fName.value.trim();
      const type  = fType.value;
      const match = fMatch.value.trim();
      const amount = parseFloat(fAmt.value) || 0;
      const score  = parseInt(fScore.value, 10) || 0;

      if (!name) { fName.focus(); return; }

      const rule = { name, type, match, amount, score_impact: score };

      if (editingIndex >= 0) {
        mcRules[editingIndex] = rule;
      } else {
        mcRules.push(rule);
      }

      renderRules();
      closeForm();
      saveToServer();
    });

    // ── Event: Edit/Delete delegation on rule list ──
    ruleList.addEventListener('click', (e) => {
      const editBtn = e.target.closest('.rule-edit-btn');
      const delBtn  = e.target.closest('.rule-del-btn');

      if (editBtn) {
        openForm(parseInt(editBtn.dataset.index, 10));
      }

      if (delBtn && confirm('Delete this pricing rule?')) {
        const idx = parseInt(delBtn.dataset.index, 10);
        mcRules.splice(idx, 1);
        renderRules();
        saveToServer();
      }
    });

    // ── Base price auto-save on blur ──
    basePriceEl && basePriceEl.addEventListener('change', saveToServer);

    // ── Pricing Simulator ──
    const simRun  = document.getElementById('mc-sim-run');
    const simSel  = document.getElementById('mc-sim-survey');
    const simRes  = document.getElementById('mc-sim-result');

    simRun && simRun.addEventListener('click', () => {
      const surveyId = simSel ? simSel.value : '0';
      const nonce = (document.getElementById('mc-pricing-nonce') || {}).value || (window.mcLeadsEngine || {}).nonce || '';
      const ajaxUrl = (window.mcLeadsEngine || {}).ajaxUrl || '/wp-admin/admin-ajax.php';

      simRun.disabled = true;
      simRun.textContent = 'Running…';

      const body = new URLSearchParams({
        action:    'mc_leads_engine_simulate_pricing',
        nonce:     nonce,
        survey_id: surveyId,
      });

      fetch(ajaxUrl, { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
          simRun.disabled = false;
          simRun.innerHTML = '<span class="dashicons dashicons-controls-play"></span> Simulate';

          if (!data.success) { simRes.style.display = 'block'; simRes.innerHTML = '<span style="color:var(--mc-red)">Simulation failed.</span>'; return; }

          const { total, score, breakdown } = data.data;
          let html = '<div class="pricing-sim-breakdown">';
          breakdown.forEach(row => {
            const symbol = row.rule_type === 'per_unit' ? '×' : '+';
            html += `<div class="pricing-sim-row">
              <span class="pricing-sim-label">${row.label}</span>
              <span class="pricing-sim-amount">${symbol} KES ${fmt(row.amount)}</span>
            </div>`;
          });
          html += `<div class="pricing-sim-total"><span>Total Estimate</span><strong>KES ${fmt(total)}</strong></div>`;
          if (score) html += `<div class="pricing-sim-score">Lead Score: <strong>${score}</strong></div>`;
          html += '</div>';
          simRes.style.display = 'block';
          simRes.innerHTML = html;
        })
        .catch(() => {
          simRun.disabled = false;
          simRun.innerHTML = '<span class="dashicons dashicons-controls-play"></span> Simulate';
        });
    });

    // Initial render
    renderRules();
  }

  /* ── Settings Tab Toggling & Dynamic WhatsApp Form Handling ── */
  const initSettingsTabs = (containerSelector, gatewayId, apiKeyLabelId, instIdLabelId, senderFieldId) => {
    const container = document.querySelector(containerSelector);
    if (!container) return;

    // Tabs logic
    const tabs = container.querySelectorAll('.settings-tab-btn');
    const panes = container.querySelectorAll('.settings-section-pane');
    tabs.forEach(tab => {
      tab.addEventListener('click', (e) => {
        e.preventDefault();
        const target = tab.dataset.tab;
        tabs.forEach(t => t.classList.toggle('active', t === tab));
        panes.forEach(p => p.classList.toggle('active', p.dataset.pane === target));
      });
    });

    // Dynamic WhatsApp gateway labels logic
    const gatewaySelect = document.getElementById(gatewayId);
    const apiKeyLabel = document.getElementById(apiKeyLabelId);
    const instIdLabel = document.getElementById(instIdLabelId);
    const senderField = document.getElementById(senderFieldId);

    if (gatewaySelect && apiKeyLabel && instIdLabel) {
      const updateGatewayFields = () => {
        const val = gatewaySelect.value;
        if (senderField) {
          senderField.style.display = (val === 'twilio' || val === 'cloud_api') ? 'block' : 'none';
        }
        
        switch (val) {
          case 'twilio':
            apiKeyLabel.textContent = 'Twilio Auth Token';
            instIdLabel.textContent = 'Twilio Account SID';
            break;
          case 'cloud_api':
            apiKeyLabel.textContent = 'Meta Access Token (System User)';
            instIdLabel.textContent = 'WhatsApp Phone Number ID';
            break;
          case 'custom':
            apiKeyLabel.textContent = 'API Key / Secret Header (Optional)';
            instIdLabel.textContent = 'Custom Webhook Gateway URL';
            break;
          case 'ultramsg':
          default:
            apiKeyLabel.textContent = 'UltraMsg API Token';
            instIdLabel.textContent = 'UltraMsg Instance ID';
            break;
        }
      };

      gatewaySelect.addEventListener('change', updateGatewayFields);
      updateGatewayFields(); // run initially
    }
  };

  // Init for dedicated settings page
  initSettingsTabs('.mc-leads-engine-admin', 'mc-whatsapp-gateway', 'mc-whatsapp-api-key-label', 'mc-whatsapp-instance-id-label', 'mc-whatsapp-sender-field');
  // Init for SPA panel settings
  initSettingsTabs('#panel-settings', 'mc-spa-whatsapp-gateway', 'mc-spa-whatsapp-api-key-label', 'mc-spa-whatsapp-instance-id-label', 'mc-spa-whatsapp-sender-field');

  // ── Lead Status Pipeline ──────────────────────────────────────────────────
  document.querySelectorAll('.mc-status-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      const leadId = btn.dataset.lead;
      const status = btn.dataset.status;
      if (!leadId || !status || !window.mcLeadsEngine) return;

      btn.disabled = true;
      fetch(mcLeadsEngine.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          action: 'mc_leads_update_status',
          nonce:   mcLeadsEngine.nonce,
          lead_id: leadId,
          status:  status,
        }),
      })
        .then((r) => r.json())
        .then((data) => {
          if (data.success) {
            document.querySelectorAll('.mc-status-btn').forEach((b) => b.classList.remove('active'));
            btn.classList.add('active');
          } else {
            alert(data.data?.message || 'Update failed.');
          }
        })
        .catch(() => alert('Request failed.'))
        .finally(() => { btn.disabled = false; });
    });
  });

  // ── Add Note ─────────────────────────────────────────────────────────────
  const noteBtn      = document.getElementById('mc-add-note-btn');
  const noteInput    = document.getElementById('mc-note-input');
  const notesTimeline = document.getElementById('mc-activity-timeline');

  if (noteBtn && noteInput && notesTimeline && window.mcLeadsEngine) {
    noteBtn.addEventListener('click', () => {
      const note   = noteInput.value.trim();
      const leadId = noteBtn.dataset.lead;
      if (!note) return;

      noteBtn.disabled = true;
      fetch(mcLeadsEngine.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          action:  'mc_leads_add_note',
          nonce:   mcLeadsEngine.nonce,
          lead_id: leadId,
          note:    note,
        }),
      })
        .then((r) => r.json())
        .then((data) => {
          if (data.success) {
            const d = data.data;
            const li = document.createElement('li');
            li.className = 'mc-activity-item';
            li.innerHTML = `
              <span class="mc-activity-icon dashicons dashicons-edit-page"></span>
              <div class="mc-activity-content">
                <span class="mc-activity-type">Note</span>
                <span class="mc-activity-time">${d.time} &bull; ${d.user}</span>
                <p class="mc-activity-body">${d.body.replace(/</g,'&lt;')}</p>
              </div>`;
            const emptyMsg = notesTimeline.querySelector('.mc-activity-empty');
            if (emptyMsg) emptyMsg.remove();
            notesTimeline.prepend(li);
            noteInput.value = '';
          } else {
            alert(data.data?.message || 'Could not save note.');
          }
        })
        .catch(() => alert('Request failed.'))
        .finally(() => { noteBtn.disabled = false; });
    });
  }

  /* ── Survey Builder Tab Switching ─────────────────────────── */
  const svTabs = document.querySelectorAll('.sv-tab');
  const svPanes = document.querySelectorAll('.sv-tab-pane');
  svTabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      const target = tab.dataset.svTab;
      svTabs.forEach((t) => t.classList.toggle('active', t === tab));
      svPanes.forEach((p) => p.classList.toggle('active', p.dataset.svPane === target));
    });
  });

  /* ── Shortcode chip copy ────────────────────────────────────  */
  document.querySelectorAll('.sv-shortcode-chip').forEach((chip) => {
    chip.addEventListener('click', async () => {
      const text = chip.dataset.shortcode || chip.querySelector('code')?.textContent || '';
      if (!text) return;
      const hint = chip.querySelector('.sv-copy-hint');
      const originalHint = hint ? hint.textContent : '';
      try {
        await navigator.clipboard.writeText(text);
        if (hint) hint.textContent = '✓ Copied!';
      } catch (_) {
        // Fallback: select the code text manually
        const range = document.createRange();
        range.selectNode(chip.querySelector('code'));
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        if (hint) hint.textContent = '✓ Copied!';
      }
      setTimeout(() => { if (hint) hint.textContent = originalHint; }, 1400);
    });
  });
});
