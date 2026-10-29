<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_once __DIR__ . '/includes/payment_actions.php';
require_admin();

// Status changes are made ONLY through admin/payment_action.php (CSRF-protected,
// reason required, transactional, audited). This page is read-only.

$filters = [
    'attention' => 'Needs attention',
    'pending' => 'Pending',
    'paid' => 'Paid',
    'failed' => 'Failed / cancelled',
    'all' => 'All',
];
$filter = (string)($_GET['filter'] ?? 'attention');
if (!isset($filters[$filter])) {
    $filter = 'attention';
}
$q = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];
switch ($filter) {
    case 'attention':
        $where[] = "(p.needs_reconciliation = 1 OR p.status IN ('pending','processing'))";
        break;
    case 'pending':
        $where[] = "p.status IN ('pending','processing')";
        break;
    case 'paid':
        $where[] = "p.status = 'paid'";
        break;
    case 'failed':
        $where[] = "p.status IN ('failed','expired','cancelled')";
        break;
}
if ($q !== '') {
    $where[] = "(o.order_number LIKE :q1 OR p.transaction_reference LIKE :q2 OR o.customer_name LIKE :q3 OR o.customer_phone LIKE :q4 OR p.pesajet_transaction_id LIKE :q5)";
    foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $k) {
        $params[$k] = '%' . $q . '%';
    }
}

$sql = "SELECT p.*, o.order_number, o.order_status, o.inventory_state, o.payment_status AS order_payment_status,
               o.customer_name, o.customer_email, o.customer_phone
          FROM payments p
          JOIN orders o ON o.order_id = p.order_id"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . " ORDER BY p.needs_reconciliation DESC, p.updated_at DESC, p.payment_id DESC LIMIT 200";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$reconCount = (int)$db->query("SELECT COUNT(*) AS c FROM payments WHERE needs_reconciliation = 1")->fetch()['c'];

$pageTitle = 'Payments — MasakaMart Admin';
require_once __DIR__ . '/includes/admin_header.php';
?>
<h1>Payments</h1>

<?php if ($reconCount > 0): ?>
  <div class="recon-banner" role="alert">
    <strong><?= $reconCount ?> payment<?= $reconCount === 1 ? '' : 's' ?> need reconciliation.</strong>
    PesaJet's result disagrees with an administrator decision (or the provider record did not match). Nothing was overwritten — open each one and decide.
    <a href="<?= APP_URL ?>/admin/payments.php?filter=attention">Show them</a>
  </div>
<?php endif; ?>

<div class="admin-panel">
  <div class="pay-toolbar">
    <nav class="pay-filters" aria-label="Payment filters">
      <?php foreach ($filters as $key => $label): ?>
        <a class="pay-filter<?= $key === $filter ? ' is-active' : '' ?>" href="<?= APP_URL ?>/admin/payments.php?filter=<?= e($key) ?><?= $q !== '' ? '&amp;q=' . e(urlencode($q)) : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <form method="get" class="pay-search" role="search">
      <input type="hidden" name="filter" value="<?= e($filter) ?>">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Order #, reference, customer, phone" aria-label="Search payments">
      <button class="btn btn-sm" type="submit">Search</button>
    </form>
  </div>

  <?php if (empty($payments)): ?>
    <p class="empty-state">No payments match this view.</p>
  <?php else: ?>
  <div class="table-scroll">
  <table class="data-table pay-table">
    <thead>
      <tr>
        <th>Order / reference</th>
        <th>Customer</th>
        <th>Amount</th>
        <th>Method</th>
        <th>Payment status</th>
        <th>Provider check</th>
        <th>Last update</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($payments as $p): ?>
      <tr class="<?= (int)$p['needs_reconciliation'] === 1 ? 'row-flagged' : '' ?>">
        <td>
          <a href="<?= APP_URL ?>/admin/order_detail.php?id=<?= (int)$p['order_id'] ?>"><strong><?= e($p['order_number']) ?></strong></a><br>
          <a href="<?= APP_URL ?>/admin/payment_detail.php?id=<?= (int)$p['payment_id'] ?>"><small><?= e($p['transaction_reference']) ?></small></a>
        </td>
        <td>
          <?= e($p['customer_name']) ?><br>
          <small><?= e($p['customer_phone']) ?></small>
        </td>
        <td><?= money((float)$p['amount']) ?></td>
        <td>
          <?= e(katale_payment_method_label((string)($p['provider'] ?: $p['method']))) ?>
          <?php if (!empty($p['phone_number'])): ?><br><small><?= e($p['phone_number']) ?></small><?php endif; ?>
        </td>
        <td>
          <?= admin_payment_status_badge($p) ?>
          <?php if ((int)$p['needs_reconciliation'] === 1): ?><span class="pay-flag">⚑ Reconcile</span><?php endif; ?>
          <?php if (!empty($p['failure_reason']) && in_array($p['status'], ['failed', 'expired', 'cancelled'], true)): ?>
            <br><small class="pay-reason"><?= e($p['failure_reason']) ?></small>
          <?php endif; ?>
        </td>
        <td>
          <?php if (!empty($p['provider_status'])): ?>
            <?= e($p['provider_status']) ?>
            <?php if (!empty($p['provider_verified_at'])): ?><br><small><?= e(date('d M H:i', strtotime((string)$p['provider_verified_at']))) ?></small><?php endif; ?>
          <?php elseif (!empty($p['provider_verification_result'])): ?>
            <small><?= e(str_replace('_', ' ', (string)$p['provider_verification_result'])) ?></small>
          <?php else: ?>
            <small class="muted">Not checked</small>
          <?php endif; ?>
        </td>
        <td><small><?= e(date('d M Y H:i', strtotime((string)($p['updated_at'] ?? $p['created_at'])))) ?></small></td>
        <td class="pay-row-actions">
          <a class="btn btn-sm" href="<?= APP_URL ?>/admin/payment_detail.php?id=<?= (int)$p['payment_id'] ?>">Details</a>
          <?= admin_payment_action_buttons($p, 'list', true) ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?= admin_payment_action_dialog() ?>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
