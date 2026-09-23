/**
 * Modal "Solicitar hora extra" — compartilhado entre ponto.php, my_timesheet.php e receipt.php.
 *
 * Uso:
 *   OvertimeRequest.init({ csrfToken: '...', endpoint: '/api/request_overtime.php' });
 *   OvertimeRequest.open({ attendanceId: 123, minutes: 45, onSuccess: (data) => {...} });
 */
(function (global) {
  'use strict';

  const STATE = {
    csrfToken: '',
    endpoint: '/api/request_overtime.php',
    modal: null,
    activeContext: null,
  };

  function formatMinutes(min) {
    min = Math.max(0, parseInt(min, 10) || 0);
    const h = Math.floor(min / 60);
    const m = min % 60;
    if (h <= 0) return m + ' min';
    return h + 'h' + String(m).padStart(2, '0') + 'm';
  }

  function buildModal() {
    if (STATE.modal) return STATE.modal;
    const wrap = document.createElement('div');
    wrap.id = 'overtime-request-modal';
    wrap.setAttribute('role', 'dialog');
    wrap.setAttribute('aria-modal', 'true');
    wrap.setAttribute('aria-labelledby', 'otrq-title');
    wrap.hidden = true;
    wrap.innerHTML = [
      '<div class="otrq-backdrop"></div>',
      '<div class="otrq-card">',
      '  <h3 id="otrq-title">Solicitar hora extra</h3>',
      '  <p class="otrq-summary">Tempo excedente calculado pelo sistema: <strong class="otrq-minutes">--</strong>.</p>',
      '  <label for="otrq-justification">Justificativa <span aria-hidden="true">*</span></label>',
      '  <textarea id="otrq-justification" maxlength="500" rows="4" placeholder="Descreva o motivo da hora extra (obrigatorio)."></textarea>',
      '  <p class="otrq-counter"><span class="otrq-count">0</span>/500</p>',
      '  <p class="otrq-error" role="alert" hidden></p>',
      '  <div class="otrq-actions">',
      '    <button type="button" class="otrq-cancel">Cancelar</button>',
      '    <button type="button" class="otrq-submit">Enviar solicitacao</button>',
      '  </div>',
      '</div>',
    ].join('\n');
    document.body.appendChild(wrap);

    const style = document.createElement('style');
    style.textContent = [
      '#overtime-request-modal{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;}',
      '#overtime-request-modal[hidden]{display:none;}',
      '#overtime-request-modal .otrq-backdrop{position:absolute;inset:0;background:rgba(0,0,0,0.55);}',
      '#overtime-request-modal .otrq-card{position:relative;background:#fff;border-radius:10px;padding:20px 22px;max-width:460px;width:90%;box-shadow:0 18px 50px rgba(0,0,0,0.3);font-family:inherit;}',
      '#overtime-request-modal h3{margin:0 0 8px;font-size:1.15rem;color:#1a3a5c;}',
      '#overtime-request-modal .otrq-summary{margin:0 0 12px;font-size:0.92rem;color:#444;}',
      '#overtime-request-modal label{display:block;margin:6px 0 4px;font-size:0.88rem;color:#333;}',
      '#overtime-request-modal textarea{width:100%;box-sizing:border-box;border:1px solid #c7cdd5;border-radius:6px;padding:8px;font-family:inherit;font-size:0.95rem;resize:vertical;min-height:90px;}',
      '#overtime-request-modal textarea:focus{outline:2px solid #2b6cb0;outline-offset:1px;border-color:#2b6cb0;}',
      '#overtime-request-modal .otrq-counter{margin:4px 0 8px;font-size:0.78rem;color:#666;text-align:right;}',
      '#overtime-request-modal .otrq-error{margin:6px 0;color:#a02020;font-size:0.86rem;background:#fdecec;padding:6px 8px;border-radius:5px;}',
      '#overtime-request-modal .otrq-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:12px;}',
      '#overtime-request-modal button{border:0;border-radius:6px;padding:9px 16px;font-size:0.93rem;cursor:pointer;}',
      '#overtime-request-modal .otrq-cancel{background:#e2e6eb;color:#333;}',
      '#overtime-request-modal .otrq-submit{background:#2b6cb0;color:#fff;}',
      '#overtime-request-modal .otrq-submit[disabled]{opacity:0.55;cursor:wait;}',
    ].join('\n');
    document.head.appendChild(style);

    const $ = (sel) => wrap.querySelector(sel);
    const ta = $('#otrq-justification');
    const counter = $('.otrq-count');
    const errEl = $('.otrq-error');
    const submitBtn = $('.otrq-submit');
    const cancelBtn = $('.otrq-cancel');
    const backdrop = $('.otrq-backdrop');

    ta.addEventListener('input', () => { counter.textContent = String(ta.value.length); });

    function close() {
      wrap.hidden = true;
      STATE.activeContext = null;
      ta.value = '';
      counter.textContent = '0';
      errEl.hidden = true; errEl.textContent = '';
      submitBtn.disabled = false;
      submitBtn.textContent = 'Enviar solicitacao';
    }

    cancelBtn.addEventListener('click', close);
    backdrop.addEventListener('click', close);
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && !wrap.hidden) close();
    });

    submitBtn.addEventListener('click', async () => {
      if (!STATE.activeContext) return;
      const justification = ta.value.trim();
      if (justification.length === 0) {
        errEl.textContent = 'A justificativa eh obrigatoria.';
        errEl.hidden = false;
        ta.focus();
        return;
      }
      errEl.hidden = true; errEl.textContent = '';
      submitBtn.disabled = true;
      submitBtn.textContent = 'Enviando...';
      try {
        const body = JSON.stringify({
          attendance_id: STATE.activeContext.attendanceId,
          justification,
          csrf: STATE.csrfToken,
        });
        const resp = await fetch(STATE.endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-Token': STATE.csrfToken,
          },
          body,
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok || !data.ok) {
          throw new Error(data.message || 'Erro ao enviar solicitacao.');
        }
        const ctx = STATE.activeContext;
        close();
        if (typeof ctx.onSuccess === 'function') ctx.onSuccess(data);
      } catch (err) {
        errEl.textContent = err && err.message ? err.message : 'Erro inesperado.';
        errEl.hidden = false;
        submitBtn.disabled = false;
        submitBtn.textContent = 'Enviar solicitacao';
      }
    });

    STATE.modal = { wrap, ta, counter, errEl, submitBtn, close };
    return STATE.modal;
  }

  const OvertimeRequest = {
    init(opts) {
      opts = opts || {};
      if (opts.csrfToken) STATE.csrfToken = String(opts.csrfToken);
      if (opts.endpoint)  STATE.endpoint  = String(opts.endpoint);
      buildModal();
    },
    open(ctx) {
      ctx = ctx || {};
      if (!ctx.attendanceId) throw new Error('attendanceId obrigatorio');
      const modal = buildModal();
      STATE.activeContext = ctx;
      modal.wrap.hidden = false;
      modal.wrap.querySelector('.otrq-minutes').textContent = formatMinutes(ctx.minutes || 0);
      setTimeout(() => modal.ta.focus(), 30);
    },
    formatMinutes,
  };

  global.OvertimeRequest = OvertimeRequest;
})(window);
