<?php
/**
 * Shared admin payment-action UI: the action buttons and ONE confirmation
 * dialog. Used by admin/payments.php, admin/payment_detail.php and
 * admin/order_detail.php. The buttons only OPEN the dialog; the actual change
 * is a CSRF-protected POST to admin/payment_action.php, authorised and
 * recorded server-side. Nothing here changes a status on the client.
 */

if (!function_exists('admin_payment_status_badge')) {
    /** Status pill + the confirmation-type label (provider/admin/pending). */
    function admin_payment_status_badge(array $p): string
    {
        $type = katale_payment_confirmation_type($p);
        return '<span class="status-pill status-' . e((string)$p['status']) . '">' . e(strtoupper((string)$p['status'])) . '</span>'
            . '<span class="pay-type pay-type-' . e($type['key']) . '">' . e($type['label']) . '</span>';
    }
}

if (!function_exists('admin_payment_action_buttons')) {
    /**
     * @param array  $p      payment row + order_number, order_status, inventory_state
     * @param string $return where to come back to: 'list' | 'detail' | 'order'
     * @param bool   $menu   true = compact dropdown (table rows)
     */
    function admin_payment_action_buttons(array $p, string $return, bool $menu = false): string
    {
        $allowed = katale_admin_payment_allowed_actions($p);
        $base = [
            'data-payment' => (string)(int)$p['payment_id'],
            'data-order' => (string)($p['order_number'] ?? ''),
            'data-amount' => money((float)$p['amount']),
            'data-status' => strtoupper((string)$p['status']),
            'data-return' => $return,
        ];
        $mk = static function (string $action, string $label, string $class, array $extra = []) use ($base): string {
            $attrs = '';
            foreach (array_merge($base, ['data-action' => $action], $extra) as $k => $v) {
                $attrs .= ' ' . $k . '="' . e((string)$v) . '"';
            }
            return '<button type="button" class="btn btn-sm pay-act ' . $class . '"' . $attrs . '>' . e($label) . '</button>';
        };

        $html = '';
        if ($allowed['verify']) {
            $html .= $mk('verify', 'Verify Payment', 'pay-act-verify');
        }
        if ($allowed['mark_paid']) {
            $html .= $mk('mark_paid', 'Mark as PAID', 'pay-act-paid', ['data-ack' => katale_admin_payment_ack_text($p, 'paid')]);
        }
        if ($allowed['mark_failed']) {
            $html .= $mk('mark_failed', 'Mark as FAILED', 'pay-act-failed', ['data-ack' => katale_admin_payment_ack_text($p, 'failed')]);
        }
        if ($allowed['mark_pending']) {
            $html .= $mk('mark_pending', 'Mark as PENDING', 'pay-act-pending', ['data-ack' => katale_admin_payment_ack_text($p, 'pending')]);
        }
        if ($allowed['resolve']) {
            $html .= $mk('resolve', 'Resolve flag', 'pay-act-resolve');
        }
        if ($html === '') {
            return '';
        }
        if ($menu) {
            return '<details class="row-actions"><summary class="btn btn-sm">Actions ▾</summary><div class="row-actions-menu">' . $html . '</div></details>';
        }
        return '<div class="pay-actions">' . $html . '</div>';
    }
}

if (!function_exists('admin_payment_action_dialog')) {
    /** The single confirmation dialog (rendered once per page). */
    function admin_payment_action_dialog(): string
    {
        ob_start(); ?>
<dialog id="payActionDialog" class="pay-dialog" aria-labelledby="payDlgTitle">
  <form method="post" action="<?= APP_URL ?>/admin/payment_action.php" id="payActionForm">
    <?= csrf_field() ?>
    <input type="hidden" name="payment_id" value="">
    <input type="hidden" name="action" value="">
    <input type="hidden" name="return" value="detail">
    <h2 id="payDlgTitle">Confirm</h2>
    <p class="pay-dlg-lead" id="payDlgLead"></p>
    <dl class="pay-dlg-facts">
      <div><dt>Order</dt><dd id="payDlgOrder"></dd></div>
      <div><dt>Amount</dt><dd id="payDlgAmount"></dd></div>
      <div><dt>Current status</dt><dd id="payDlgStatus"></dd></div>
    </dl>
    <label class="pay-dlg-field" id="payDlgNoteWrap">
      <span id="payDlgNoteLabel">Reason / verification note <em>(required, saved in the audit log)</em></span>
      <textarea name="note" rows="3" minlength="10" maxlength="500" placeholder="e.g. MTN statement shows UGX 45,000 received from 0771…, ref 123456"></textarea>
    </label>
    <label class="pay-dlg-field" id="payDlgRefWrap" hidden>
      <span>Provider / mobile-money transaction reference <em>(optional evidence)</em></span>
      <input type="text" name="transaction_reference" maxlength="100" autocomplete="off">
    </label>
    <label class="pay-dlg-ack" id="payDlgAckWrap" hidden>
      <input type="checkbox" name="acknowledge_override" value="1">
      <span id="payDlgAckText"></span>
    </label>
    <div class="pay-dlg-buttons">
      <button type="button" class="btn btn-secondary" data-pay-cancel>Cancel</button>
      <button type="submit" class="btn" id="payDlgConfirm">Confirm</button>
    </div>
  </form>
</dialog>
<?php
        return (string)ob_get_clean();
    }
}
