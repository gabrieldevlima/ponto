/**
 * Modal "Regularizar saida esquecida" — compartilhado entre ponto.php e my_timesheet.php.
 *
 * Uso:
 *   CheckoutRegularize.init({ csrfToken: '...', endpoint: 'api/regularize_checkout.php' });
 *   CheckoutRegularize.open({
 *     attendanceId: 123,
 *     date: '2026-05-16',
 *     checkIn: '2026-05-16 08:00:00',
 *     schoolName: 'Escola X',
 *     onSuccess: (data) => {...}
 *   });
 */
(function (global) {
  'use strict';

  const STATE = {
    csrfToken: '',
    endpoint: 'api/regularize_checkout.php',
    modal: null,
    activeContext: null,
  };

  function pad2(n) { return String(n).padStart(2, '0'); }
  function formatDateBR(ymd) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd || '');
    return m ? (m[3] + '/' + m[2] + '/' + m[1]) : (ymd || '');
  }
  function formatTime(dtStr) {
    const m = /(\d{2}):(\d{2})/.exec(dtStr || '');
    return m ? (m[1] + ':' + m[2]) : '--:--';
  }
  function toLocalDatetimeValue(dtStr) {
    // 'YYYY-MM-DD HH:MM:SS' -> 'YYYY-MM-DDTHH:MM' (formato exigido por <input type="datetime-local">)
    if (!dtStr) return '';
    const m = /(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})/.exec(dtStr);
    return m ? (m[1] + 'T' + m[2]) : '';
  }

  function buildModal() {
    if (STATE.modal) return STATE.modal;
    const wrap = document.createElement('div');
    wrap.id = 'checkout-regularize-modal';
    wrap.setAttribute('role', 'dialog');
    wrap.setAttribute('aria-modal', 'true');
    wrap.setAttribute('aria-labelledby', 'crm-title');
    wrap.hidden = true;
    wrap.innerHTML = [
      '<div class="crm-backdrop"></div>',
      '<div class="crm-card">',
      '  <h3 id="crm-title">Regularizar saida esquecida</h3>',
      '  <p class="crm-context">',
      '    Ponto de <strong class="crm-date">--/--/----</strong> — entrada registrada as <strong class="crm-check-in">--:--</strong>',
      '    <span class="crm-school"></span>',
      '  </p>',
      '  <label for="crm-checkout">Horario estimado de saida <span aria-hidden="true">*</span></label>',
      '  <input type="datetime-local" id="crm-checkout" required>',
      '  <p class="crm-hint">Informe o horario em que voce saiu. Sera enviado para revisao do administrador.</p>',
      '  <label for="crm-justification">Justificativa <span aria-hidden="true">*</span></label>',
      '  <textarea id="crm-justification" maxlength="500" rows="3" placeholder="Ex: esqueci de bater a saida, sai por volta das 17h."></textarea>',
      '  <p class="crm-counter"><span class="crm-count">0</span>/500</p>',
      '  <p class="crm-error" role="alert" hidden></p>',
      '  <div class="crm-actions">',
      '    <button type="button" class="crm-cancel">Cancelar</button>',
      '    <button type="button" class="crm-submit">Enviar regularizacao</button>',
      '  </div>',
      '</div>',
    ].join('\n');
    document.body.appendChild(wrap);

    const style = document.createElement('style');
    style.textContent = [
      '#checkout-regularize-modal{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;}',
      '#checkout-regularize-modal[hidden]{display:none;}',
      '#checkout-regularize-modal .crm-backdrop{position:absolute;inset:0;background:rgba(0,0,0,0.55);}',
      '#checkout-regularize-modal .crm-card{position:relative;background:#fff;border-radius:10px;padding:20px 22px;max-width:480px;width:92%;box-shadow:0 18px 50px rgba(0,0,0,0.3);font-family:inherit;}',
      '#checkout-regularize-modal h3{margin:0 0 8px;font-size:1.15rem;color:#1a3a5c;}',
      '#checkout-regularize-modal .crm-context{margin:0 0 14px;font-size:0.95rem;color:#444;background:#f4f6fa;padding:8px 10px;border-radius:6px;}',
      '#checkout-regularize-modal .crm-school{display:block;font-size:0.85rem;color:#666;margin-top:2px;}',
      '#checkout-regularize-modal label{display:block;margin:6px 0 4px;font-size:0.88rem;color:#333;}',
      '#checkout-regularize-modal input[type=datetime-local],#checkout-regularize-modal textarea{width:100%;box-sizing:border-box;border:1px solid #c7cdd5;border-radius:6px;padding:8px;font-family:inherit;font-size:0.95rem;}',
      '#checkout-regularize-modal textarea{resize:vertical;min-height:80px;}',
      '#checkout-regularize-modal input:focus,#checkout-regularize-modal textarea:focus{outline:2px solid #2b6cb0;outline-offset:1px;border-color:#2b6cb0;}',
      '#checkout-regularize-modal .crm-hint{margin:4px 0 8px;font-size:0.8rem;color:#666;}',
      '#checkout-regularize-modal .crm-counter{margin:4px 0 8px;font-size:0.78rem;color:#666;text-align:right;}',
      '#checkout-regularize-modal .crm-error{margin:6px 0;color:#a02020;font-size:0.86rem;background:#fdecec;padding:6px 8px;border-radius:5px;}',
      '#checkout-regularize-modal .crm-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:12px;}',
      '#checkout-regularize-modal button{border:0;border-radius:6px;padding:9px 16px;font-size:0.93rem;cursor:pointer;}',
      '#checkout-regularize-modal .crm-cancel{background:#e2e6eb;color:#333;}',
      '#checkout-regularize-modal .crm-submit{background:#2b6cb0;color:#fff;}',
      '#checkout-regularize-modal .crm-submit[disabled]{opacity:0.55;cursor:wait;}',
    ].join('\n');
    document.head.appendChild(style);

    const $ = (sel) => wrap.querySelector(sel);
    const dt = $('#crm-checkout');
    const ta = $('#crm-justification');
    const counter = $('.crm-count');
    const errEl = $('.crm-error');
    const submitBtn = $('.crm-submit');
    const cancelBtn = $('.crm-cancel');
    const backdrop = $('.crm-backdrop');

    ta.addEventListener('input', () => { counter.textContent = String(ta.value.length); });

    function close() {
      wrap.hidden = true;
      STATE.activeContext = null;
      ta.value = '';
      dt.value = '';
      counter.textContent = '0';
      errEl.hidden = true; errEl.textContent = '';
      submitBtn.disabled = false;
      submitBtn.textContent = 'Enviar regularizacao';
    }

    cancelBtn.addEventListener('click', close);
    backdrop.addEventListener('click', close);
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && !wrap.hidden) close();
    });

    submitBtn.addEventListener('click', async () => {
      if (!STATE.activeContext) return;
      const ctx = STATE.activeContext;
      const justification = ta.value.trim();
      const proposed = dt.value.trim();
      if (!proposed) {
        errEl.textContent = 'Informe o horario estimado de saida.';
        errEl.hidden = false;
        dt.focus();
        return;
      }
      if (!justification) {
        errEl.textContent = 'A justificativa eh obrigatoria.';
        errEl.hidden = false;
        ta.focus();
        return;
      }
      // datetime-local devolve 'YYYY-MM-DDTHH:MM' — o backend aceita ambos os formatos.
      errEl.hidden = true; errEl.textContent = '';
      submitBtn.disabled = true;
      submitBtn.textContent = 'Enviando...';
      try {
        const body = JSON.stringify({
          attendance_id: ctx.attendanceId,
          proposed_check_out: proposed,
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
          throw new Error(data.message || 'Erro ao enviar regularizacao.');
        }
        close();
        if (typeof ctx.onSuccess === 'function') ctx.onSuccess(data);
      } catch (err) {
        errEl.textContent = err && err.message ? err.message : 'Erro inesperado.';
        errEl.hidden = false;
        submitBtn.disabled = false;
        submitBtn.textContent = 'Enviar regularizacao';
      }
    });

    STATE.modal = { wrap, dt, ta, counter, errEl, submitBtn, close };
    return STATE.modal;
  }

  const CheckoutRegularize = {
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
      modal.wrap.querySelector('.crm-date').textContent = formatDateBR(ctx.date);
      modal.wrap.querySelector('.crm-check-in').textContent = formatTime(ctx.checkIn);
      const sch = modal.wrap.querySelector('.crm-school');
      sch.textContent = ctx.schoolName ? 'Local: ' + ctx.schoolName : '';
      // Default sugerido: 17:00 do dia da entrada (UX comum de "fim de jornada").
      // Se o usuario quiser alterar, basta editar o campo.
      const dateOnly = (ctx.date || '').slice(0, 10);
      modal.dt.value = dateOnly ? (dateOnly + 'T17:00') : toLocalDatetimeValue(ctx.checkIn);
      // Limites do input: minimo = check_in, maximo = agora.
      modal.dt.min = toLocalDatetimeValue(ctx.checkIn);
      modal.dt.max = (() => {
        const d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth()+1) + '-' + pad2(d.getDate()) + 'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
      })();
      setTimeout(() => modal.dt.focus(), 30);
    },
  };

  global.CheckoutRegularize = CheckoutRegularize;
})(window);
