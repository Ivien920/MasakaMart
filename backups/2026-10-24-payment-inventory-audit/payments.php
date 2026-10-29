<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $paymentId = (int)$_POST['payment_id'];
    $status = $_POST['status'];
    if (in_array($status, katale_payment_status_options(), true)) {
        // Single idempotent transition path — keeps payments.status and
        // orders.payment_status in sync, prevents downgrading a PAID
        // payment, and fires customer notifications exactly once.
        $payStmt = $db->prepare("SELECT * FROM payments WHERE payment_id = :id LIMIT 1");
        $payStmt->execute(['id' => $paymentId]);
        $payment = $payStmt->fetch();
        if ($payment && katale_apply_payment_status($db, $payment, $status, ['source' => 'admin'])) {
            log_admin_activity($db, 'update_payment_status', "Payment #$paymentId -> $status");
            flash('success', 'Payment status updated.');
        } elseif ($payment) {
            flash('error', 'No change applied (a confirmed payment cannot be downgraded).');
        } else {
            flash('error', 'Payment not found.');
        }
    }
    redirect(APP_URL . '/admin/payments.php');
}

$payments = $db->query(
    "SELECT p.*, o.order_number, o.order_status, o.payment_status AS order_payment_status,
            o.customer_name, o.customer_email, o.customer_phone, u.full_name AS user_name
       FROM payments p
       JOIN orders o ON o.order_id = p.order_id
       LEFT JOIN users u ON u.user_id = o.user_id
      ORDER BY p.created_at DESC LIMIT 200"
)->fetchAll();

$pageTitle = 'Payments — MasakaMart Admin';
require_once __DIR__ . '/includes/admin_header.php';
?>
<h1>Payments</h1>
<div class="admin-panel">
  <div class="table-scroll">
  <table class="data-table">
    <thead>
      <tr>
        <th>Order #</th>
        <th>Customer</th>
        <th>Amount</th>
        <th>Method</th>
        <th>Payment</th>
        <th>Order</th>
        <th>Reference</th>
        <th>Date</th>
        <th>Completed</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($payments as $p): ?>
      <tr>
        <td><?= e($p['order_number']) ?></td>
        <td>
          <?= e($p['customer_name']) ?><br>
          <small><?= e($p['customer_email']) ?></small>
        </td>
        <td><?= money((float)$p['amount']) ?></td>
        <td>
          <?= e(katale_payment_method_label((string)($p['provider'] ?: $p['method']))) ?>
          <?php if (!empty($p['phone_number'])): ?><br><small><?= e($p['phone_number']) ?></small><?php endif; ?>
        </td>
        <td><span class="status-pill status-<?= e($p['status']) ?>"><?= e(ucfirst($p['status'])) ?></span></td>
        <td><span class="status-pill status-<?= e($p['order_payment_status'] === 'paid' ? 'paid' : 'pending') ?>"><?= e(str_replace('_', ' ', $p['order_status'])) ?></span></td>
        <td>
          <?= e($p['transaction_reference']) ?>
          <?php if (!empty($p['pesajet_transaction_id'])): ?>
            <br><small>PesaJet: <?= e($p['pesajet_transaction_id']) ?></small>
          <?php endif; ?>
          <?php if (!empty($p['webhook_status'])): ?>
            <br><small>Webhook: <?= e($p['webhook_status']) ?></small>
          <?php endif; ?>
        </td>
        <td><?= date('d M Y H:i', strtotime($p['created_at'])) ?></td>
        <td><?= !empty($p['paid_at']) ? e(date('d M Y H:i', strtotime($p['paid_at']))) : '—' ?></td>
        <td>
          <form method="post" class="inline-form">
            <?= csrf_field() ?><input type="hidden" name="payment_id" value="<?= (int)$p['payment_id'] ?>">
            <select name="status">
              <?php foreach (katale_payment_status_options() as $s): ?>
                <option value="<?= $s ?>" <?= $p['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm">Update</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
