/**
 * MasakaMart Admin — shared JavaScript utilities.
 *
 * IntegerInput: Enforces whole-number-only entry on <input type="number"> fields.
 * Blocks decimal points, commas, +/-, and scientific notation keys.
 * Strips invalid pasted values on the `input` event.
 * Validates on form submit as a final safety net.
 */
var MasakaMart = MasakaMart || {};

(function () {
  'use strict';

  var WHOLE_UNITS = ['piece', 'bunch', 'tray', 'crate', 'packet', 'sack', 'basket'];
  var INVALID_KEYS = ['.', ',', '-', '+', 'e', 'E'];
  var INTEGER_RE = /^\d+$/;
  var ERROR_MSG = 'Whole numbers only — decimal values are not allowed for this field.';

  /**
   * Check whether the given unit string requires whole-number quantities.
   * @param {string} unit
   * @returns {boolean}
   */
  function unitRequiresWholeNumber(unit) {
    return WHOLE_UNITS.indexOf(unit) !== -1;
  }

  // ── per-input helpers ─────────────────────────────────────────────

  function setError(input, message) {
    input.setCustomValidity(message || '');
    input.setAttribute('aria-invalid', message ? 'true' : 'false');
  }

  function showInlineWarning(input) {
    // Create or reuse a small warning element right after the input.
    var id = 'intWarn_' + (input.name || '') + '_' + (input.id || Math.random().toString(36).slice(2));
    var warn = input.parentNode.querySelector('.int-input-warning');
    if (!warn) {
      warn = document.createElement('small');
      warn.className = 'int-input-warning';
      warn.style.cssText = 'display:block;color:#c0392b;font-size:.82em;margin-top:2px;';
      input.parentNode.insertBefore(warn, input.nextSibling);
    }
    warn.textContent = ERROR_MSG;
    clearTimeout(warn._timer);
    warn._timer = setTimeout(function () { warn.textContent = ''; }, 3500);
  }

  /**
   * Attach integer-only enforcement to a single input element.
   *
   * @param {HTMLInputElement} input       The numeric input to protect.
   * @param {function():boolean} isActive  Callback that returns true when
   *                                       whole-number mode is active for this
   *                                       input (allows dynamic unit switching).
   */
  function protect(input, isActive) {
    // Block forbidden keys on keydown (covers physical keyboard)
    input.addEventListener('keydown', function (e) {
      if (isActive() && INVALID_KEYS.indexOf(e.key) !== -1) {
        e.preventDefault();
        showInlineWarning(input);
      }
    });

    // Block typed/composed characters via beforeinput (wider coverage)
    input.addEventListener('beforeinput', function (e) {
      if (!isActive()) return;
      // insertText, insertFromPaste, insertFromDrop, etc.
      if (e.data && !INTEGER_RE.test(e.data)) {
        e.preventDefault();
        showInlineWarning(input);
      }
    });

    // Catch anything that slips through (paste fallback, autofill, etc.)
    input.addEventListener('input', function () {
      if (!isActive()) { setError(input, ''); return; }
      if (input.value !== '' && !INTEGER_RE.test(input.value)) {
        input.value = '';
        setError(input, ERROR_MSG);
        showInlineWarning(input);
        input.reportValidity();
        return;
      }
      setError(input, '');
    });

    // Also respond to programmatic paste via the paste event
    input.addEventListener('paste', function (e) {
      if (!isActive()) return;
      var text = (e.clipboardData || window.clipboardData || {}).getData('text') || '';
      if (!INTEGER_RE.test(text.trim())) {
        e.preventDefault();
        input.value = '';
        setError(input, ERROR_MSG);
        showInlineWarning(input);
        input.reportValidity();
      }
    });
  }

  /**
   * Validate a set of protected inputs before form submission.
   * Returns true if all are valid; false and prevents submission otherwise.
   *
   * @param {Event} event            The submit event.
   * @param {NodeList|Array} inputs  The inputs to check.
   * @param {function():boolean} isActive
   */
  function guardSubmit(event, inputs, isActive) {
    if (!isActive()) return;
    var bad = false;
    for (var i = 0; i < inputs.length; i++) {
      var inp = inputs[i];
      if (inp.value !== '' && !INTEGER_RE.test(inp.value)) {
        inp.value = '';
        setError(inp, ERROR_MSG);
        showInlineWarning(inp);
        bad = true;
      }
    }
    if (bad) {
      event.preventDefault();
      var first = document.querySelector('input[aria-invalid="true"]');
      if (first) first.reportValidity();
    }
  }

  /**
   * Sync the `step` attribute to match whole-number mode.
   * @param {HTMLInputElement} input
   * @param {boolean} wholeOnly
   */
  function syncStep(input, wholeOnly) {
    input.step = wholeOnly ? '1' : '0.01';
    if (!wholeOnly || input.value === '' || INTEGER_RE.test(input.value)) {
      setError(input, '');
    }
  }

  // ── public API ────────────────────────────────────────────────────

  MasakaMart.integerInput = {
    WHOLE_UNITS: WHOLE_UNITS,
    unitRequiresWholeNumber: unitRequiresWholeNumber,
    protect: protect,
    guardSubmit: guardSubmit,
    syncStep: syncStep,
    ERROR_MSG: ERROR_MSG
  };
})();

/**
 * Payment action confirmation dialog (admin/payments.php, payment_detail.php,
 * order_detail.php). The buttons only OPEN the dialog; the status change itself
 * is a CSRF-protected server POST to admin/payment_action.php.
 */
(function () {
  'use strict';

  var COPY = {
    mark_paid: {
      title: 'Mark payment as PAID',
      lead: 'Confirm that the money has actually been received. The order will be recorded as paid and treated as an admin-confirmed payment. This can be done even if PesaJet has not sent a callback.',
      noteLabel: 'Verification note (required — saved in the audit log)',
      button: 'Confirm: mark PAID', cls: 'pay-act-paid', ref: true, note: true
    },
    mark_failed: {
      title: 'Mark payment as FAILED',
      lead: 'Declare that this payment failed. The order will be cancelled and its stock released back to inventory (once). The customer is notified.',
      noteLabel: 'Reason (required — saved in the audit log)',
      button: 'Confirm: mark FAILED', cls: 'pay-act-failed', ref: false, note: true
    },
    mark_pending: {
      title: 'Return payment to PENDING',
      lead: 'Keep this transaction unresolved for review. It will not be reported as paid, and automatic expiry will not cancel it while it is held.',
      noteLabel: 'Reason (required — saved in the audit log)',
      button: 'Confirm: set PENDING', cls: 'pay-act-pending', ref: false, note: true
    },
    verify: {
      title: 'Verify payment with PesaJet',
      lead: 'Ask PesaJet for this transaction’s record and compare it with ours (amount, currency, reference). If PesaJet reports a final result, the payment is updated; if it disagrees with an administrator decision it is flagged for reconciliation instead of being overwritten.',
      noteLabel: '', button: 'Verify now', cls: 'pay-act-verify', ref: false, note: false
    },
    resolve: {
      title: 'Resolve reconciliation flag',
      lead: 'You have reviewed the conflict and accept the current payment status. The flag is cleared and your note is recorded.',
      noteLabel: 'How was it resolved? (required — saved in the audit log)',
      button: 'Resolve flag', cls: 'pay-act-resolve', ref: false, note: true
    }
  };

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
  }

  ready(function () {
    var dlg = document.getElementById('payActionDialog');
    if (!dlg) { return; }
    var form = document.getElementById('payActionForm');
    var $ = function (id) { return document.getElementById(id); };
    var supportsDialog = typeof dlg.showModal === 'function';

    function openFor(btn) {
      var c = COPY[btn.getAttribute('data-action')];
      if (!c) { return; }
      form.elements['payment_id'].value = btn.getAttribute('data-payment');
      form.elements['action'].value = btn.getAttribute('data-action');
      form.elements['return'].value = btn.getAttribute('data-return') || 'detail';
      $('payDlgTitle').textContent = c.title;
      $('payDlgLead').textContent = c.lead;
      $('payDlgOrder').textContent = btn.getAttribute('data-order');
      $('payDlgAmount').textContent = btn.getAttribute('data-amount');
      $('payDlgStatus').textContent = btn.getAttribute('data-status');

      var note = form.elements['note'];
      $('payDlgNoteWrap').hidden = !c.note;
      note.required = c.note;
      note.value = '';
      if (c.note) { $('payDlgNoteLabel').textContent = c.noteLabel; }

      $('payDlgRefWrap').hidden = !c.ref;
      form.elements['transaction_reference'].value = '';

      var ack = btn.getAttribute('data-ack') || '';
      $('payDlgAckWrap').hidden = ack === '';
      form.elements['acknowledge_override'].checked = false;
      form.elements['acknowledge_override'].required = ack !== '';
      $('payDlgAckText').textContent = ack;

      var confirm = $('payDlgConfirm');
      confirm.textContent = c.button;
      confirm.className = 'btn ' + c.cls;
      confirm.disabled = false;

      // Close the row's <details> menu, if any.
      var menu = btn.closest('details');
      if (menu) { menu.removeAttribute('open'); }

      if (supportsDialog) { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
      if (c.note) { note.focus(); } else { confirm.focus(); }
    }

    function closeDialog() {
      if (supportsDialog) { dlg.close(); } else { dlg.removeAttribute('open'); }
    }

    document.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.pay-act') : null;
      if (btn) { e.preventDefault(); openFor(btn); }
    });
    dlg.querySelector('[data-pay-cancel]').addEventListener('click', closeDialog);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) { closeDialog(); } });

    // One submit only: stops double-clicks sending the decision twice.
    form.addEventListener('submit', function () {
      var confirm = $('payDlgConfirm');
      if (confirm.disabled) { return; }
      setTimeout(function () { confirm.disabled = true; confirm.textContent = 'Saving…'; }, 0);
    });
  });
})();
