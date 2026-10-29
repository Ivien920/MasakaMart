<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_once __DIR__ . '/includes/payment_actions.php';
require_admin();

$paymentId = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare(
    "SELECT p.*, o.order_number, o.order_status, o.inventory_state, o.payment_status AS order_payment_status,
            o.customer_name, o.customer_email, o.customer_phone, o.grand_total, o.delivery_method,
            o.needs_admin_review, o.created_at AS order_created_at,
            dec_admin.full_name AS decision_admin_name, man_admin.full_name AS manual_admin_name
       FROM payments p
       JOIN orders o ON o.order_id = p.order_id
       LEFT JOIN admins dec_admin ON dec_admin.admin_id = p.admin_decision_by
       LEFT JOIN admins man_admin ON man_admin.admin_id = p.manual_verified_by
      WHERE p.payment_id = :id LIMIT 1"
);
$stmt->execute(['id' => $paymentId]);
$p = $stmt->fetch();
if (!$p) {
    flash('error', 'Payment not found.');
    redirect(APP_URL . '/admin/payments.php');
}

$timeline = katale_admin_payment_timeline($db, $p);
$type = katale_payment_confirmation_type($p);
$fmt = static fn (?string $t): string => $t ? date('d M Y H:i:s', strtotime($t)) : '—';

// Provider response as stored: already sanitised (phone numbers masked, no
// credentials) when it was saved. Re-encoded for display only.
$providerResponse = '';
if (!empty($p['api_response'])) {
    $decoded = json_decode((string)$p['api_response'], true);
    $providerResponse = is_array($decoded)
        ? json_encode(katale_payment_log_sanitize($decoded), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : '';
}

$pageTitle = 'Payment ' . $p['transaction_reference'] . ' — MasakaMart Admin';
require_once __DIR__ . '/includes/admin_header.php';
?>
<p><a class="admin-back-link" href="<?= APP_URL ?>/admin/payments.php">← All payments</a></p>
<h1>Payment <?= e($p['transaction_reference']) ?></h1>

<?php if ((int)$p['needs_reconciliation'] === 1): ?>
  <div class="recon-banner" role="alert">
    <strong>Needs reconciliation.</strong> <?= e((string)$p['reconciliation_note']) ?>
    <?php if (!empty($p['reconciliation_flagged_at'])): ?><small>(flagged <?= e($fmt($p['reconciliation_flagged_at'])) ?>)</small><?php endif; ?><br>
    The current status was <strong>not</strong> overwritten. Use Verify Payment, mark it PAID or FAILED with a note, or resolve the flag if you accept the current state.
  </div>
<?php endif; ?>

<div class="admin-panel">
  <div class="pay-headline">
    <div>
      <?= admin_payment_status_badge($p) ?>
      <span class="pay-amount-big"><?= money((float)$p['amount']) ?></span>
    </div>
    <?= admin_payment_action_buttons($p, 'detail') ?>
  </div>
</div>

<div class="admin-grid-2">
  <div class="admin-panel">
    <h2>Transaction</h2>
    <dl class="pay-detail-list">
      <div><dt>Transaction reference</dt><dd><?= e($p['transaction_reference']) ?></dd></div>
      <div><dt>Order</dt><dd><a href="<?= APP_URL ?>/admin/order_detail.php?id=<?= (int)$p['order_id'] ?>"><?= e($p['order_number']) ?></a> (ID <?= (int)$p['order_id'] ?>) · order is <strong><?= e(str_replace('_', ' ', (string)$p['order_status'])) ?></strong>, order payment <strong><?= e(strtoupper((string)$p['order_payment_status'])) ?></strong></dd></div>
      <div><dt>Customer</dt><dd><?= e($p['customer_name']) ?><br><small><?= e($p['customer_email']) ?> · <?= e($p['customer_phone']) ?></small></dd></div>
      <div><dt>Amount</dt><dd><?= money((float)$p['amount']) ?> <?= e((string)$p['currency']) ?></dd></div>
      <div><dt>Payment method</dt><dd><?= e(katale_payment_method_label((string)($p['provider'] ?: $p['method']))) ?><?php if (!empty($p['phone_number'])): ?> · <?= e($p['phone_number']) ?><?php endif; ?></dd></div>
      <div><dt>Attempts</dt><dd><?= (int)$p['attempt_count'] ?></dd></div>
      <div><dt>Created</dt><dd><?= e($fmt($p['created_at'])) ?></dd></div>
      <div><dt>Paid / confirmed at</dt><dd><?= e($fmt($p['paid_at'] ?? null)) ?></dd></div>
      <?php if (!empty($p['failure_reason'])): ?><div><dt>Failure reason</dt><dd><?= e($p['failure_reason']) ?></dd></div><?php endif; ?>
    </dl>
  </div>

  <div class="admin-panel">
    <h2>Confirmation &amp; provider</h2>
    <dl class="pay-detail-list">
      <div><dt>Decision type</dt><dd><span class="pay-type pay-type-<?= e($type['key']) ?>"><?= e($type['label']) ?></span></dd></div>
      <?php if (!empty($p['admin_decision'])): ?>
        <div><dt>Administrator decision</dt><dd><strong><?= e(strtoupper((string)$p['admin_decision'])) ?></strong> by <?= e((string)($p['decision_admin_name'] ?? 'admin #' . (int)$p['admin_decision_by'])) ?> · <?= e($fmt($p['admin_decision_at'])) ?><br><small><?= e((string)$p['admin_decision_note']) ?></small></dd></div>
      <?php endif; ?>
      <div><dt>PesaJet transaction id</dt><dd><?= !empty($p['pesajet_transaction_id']) ? e($p['pesajet_transaction_id']) : '<span class="muted">none — request never reached PesaJet</span>' ?></dd></div>
      <?php if (!empty($p['provider_reference'])): ?><div><dt>Provider reference</dt><dd><?= e($p['provider_reference']) ?></dd></div><?php endif; ?>
      <div><dt>Provider status (last seen)</dt><dd><?= !empty($p['provider_status']) ? e($p['provider_status']) : '<span class="muted">not checked</span>' ?><?php if (!empty($p['provider_verified_at'])): ?> · <?= e($fmt($p['provider_verified_at'])) ?><?php endif; ?></dd></div>
      <div><dt>Last verification result</dt><dd><?= !empty($p['provider_verification_result']) ? e(str_replace('_', ' ', (string)$p['provider_verification_result'])) : '<span class="muted">—</span>' ?></dd></div>
      <div><dt>Last webhook</dt><dd><?= !empty($p['webhook_status']) ? e($p['webhook_status']) . ' · ' . e($fmt($p['webhook_received_at'] ?? null)) : '<span class="muted">none received</span>' ?></dd></div>
      <?php if (!empty($p['manual_note'])): ?><div><dt>Manual verification note</dt><dd><?= e($p['manual_note']) ?></dd></div><?php endif; ?>
    </dl>
    <?php if ($providerResponse !== ''): ?>
      <details class="pay-raw"><summary>Provider response (sanitised)</summary><pre><?= e($providerResponse) ?></pre></details>
    <?php endif; ?>
  </div>
</div>

<div class="admin-panel">
  <h2>Payment history</h2>
  <?php if (empty($timeline['history'])): ?>
    <p class="empty-state-sm">No status changes recorded yet.</p>
  <?php else: ?>
  <ol class="pay-timeline">
    <?php foreach ($timeline['history'] as $h): ?>
      <li>
        <span class="pay-tl-time"><?= e($fmt($h['created_at'])) ?></span>
        <span class="pay-tl-what">
          <?php if ($h['previous_status'] === $h['new_status']): ?>
            <strong><?= e(strtoupper((string)$h['new_status'])) ?></strong> (no change)
          <?php else: ?>
            <?= e(strtoupper((string)($h['previous_status'] ?? '—'))) ?> → <strong><?= e(strtoupper((string)$h['new_status'])) ?></strong>
          <?php endif; ?>
          <span class="pay-tl-src"><?= e(str_replace('_', ' ', (string)$h['source'])) ?><?= !empty($h['admin_name']) ? ' · ' . e((string)$h['admin_name']) : '' ?><?= !empty($h['provider_status']) ? ' · provider: ' . e((string)$h['provider_status']) : '' ?></span>
          <?php if (!empty($h['note'])): ?><span class="pay-tl-note"><?= e((string)$h['note']) ?></span><?php endif; ?>
        </span>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>
</div>

<div class="admin-panel">
  <h2>Administrator audit log</h2>
  <?php if (empty($timeline['actions'])): ?>
    <p class="empty-state-sm">No administrator actions on this payment yet.</p>
  <?php else: ?>
  <div class="table-scroll">
  <table class="data-table">
    <thead><tr><th>When</th><th>Administrator</th><th>Action</th><th>Previous → new (payment)</th><th>Order</th><th>Reason / note</th></tr></thead>
    <tbody>
      <?php foreach ($timeline['actions'] as $a): ?>
      <tr>
        <td><?= e($fmt($a['created_at'])) ?></td>
        <td><?= e((string)($a['admin_name'] ?? ('#' . (int)$a['admin_id']))) ?></td>
        <td><?= e(str_replace('_', ' ', (string)$a['action_type'])) ?></td>
        <td><?= e(strtoupper((string)($a['previous_payment_status'] ?? '—'))) ?> → <?= e(strtoupper((string)($a['new_payment_status'] ?? '—'))) ?></td>
        <td><?= e((string)($a['previous_order_status'] ?? '—')) ?> → <?= e((string)($a['new_order_status'] ?? '—')) ?></td>
        <td><?= e((string)($a['note'] ?? '')) ?><?= !empty($a['transaction_reference']) ? '<br><small>ref: ' . e((string)$a['transaction_reference']) . '</small>' : '' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php if (!empty($timeline['webhooks'])): ?>
<div class="admin-panel">
  <h2>Provider webhook deliveries</h2>
  <div class="table-scroll">
  <table class="data-table">
    <thead><tr><th>Received</th><th>Event</th><th>Status</th><th>Processed</th><th>Duplicates ignored</th></tr></thead>
    <tbody>
      <?php foreach ($timeline['webhooks'] as $w): ?>
      <tr>
        <td><?= e($fmt($w['created_at'])) ?></td>
        <td><?= e((string)$w['event']) ?></td>
        <td><?= e((string)$w['status']) ?></td>
        <td><?= (int)$w['processed'] === 1 ? 'Yes' : 'No' ?></td>
        <td><?= (int)$w['duplicate_count'] ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
<?= admin_payment_action_dialog() ?>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
