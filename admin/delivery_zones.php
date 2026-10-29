<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $zoneId = (int)($_POST['zone_id'] ?? 0);
        $name = trim($_POST['zone_name'] ?? '');
        $fee = (float)($_POST['delivery_fee'] ?? 0);
        if ($name === '' || $fee < 0) {
            flash('error', 'Please provide a valid zone name and fee.');
        } elseif ($zoneId > 0) {
            $db->prepare("UPDATE delivery_zones SET zone_name=:name, delivery_fee=:fee WHERE zone_id=:id")
               ->execute(['name' => $name, 'fee' => $fee, 'id' => $zoneId]);
            flash('success', 'Zone updated.');
        } else {
            $db->prepare("INSERT INTO delivery_zones (zone_name, delivery_fee) VALUES (:name, :fee)")
               ->execute(['name' => $name, 'fee' => $fee]);
            flash('success', 'Zone created.');
        }
        redirect(APP_URL . '/admin/delivery_zones.php');
    }

    if ($action === 'toggle') {
        $db->prepare("UPDATE delivery_zones SET is_active = 1 - is_active WHERE zone_id = :id")->execute(['id' => (int)$_POST['zone_id']]);
        redirect(APP_URL . '/admin/delivery_zones.php');
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM delivery_zones WHERE zone_id = :id")->execute(['id' => (int)$_POST['zone_id']]);
        flash('success', 'Zone deleted.');
        redirect(APP_URL . '/admin/delivery_zones.php');
    }
}

$editZone = null;
if ($_GET['edit'] ?? null) {
    $stmt = $db->prepare("SELECT * FROM delivery_zones WHERE zone_id = :id");
    $stmt->execute(['id' => (int)$_GET['edit']]);
    $editZone = $stmt->fetch();
}

$zones = $db->query("SELECT * FROM delivery_zones ORDER BY zone_name")->fetchAll();

$pageTitle = 'Delivery Zones — MasakaMart Admin';
require_once __DIR__ . '/includes/admin_header.php';
?>
<h1>Delivery Zones</h1>
<div class="admin-grid-2">
  <div class="admin-panel">
    <h2><?= $editZone ? 'Edit Zone' : 'Add Zone' ?></h2>
    <form method="post" class="admin-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="save">
      <?php if ($editZone): ?><input type="hidden" name="zone_id" value="<?= (int)$editZone['zone_id'] ?>"><?php endif; ?>
      <label>Zone Name</label>
      <input type="text" name="zone_name" value="<?= e($editZone['zone_name'] ?? '') ?>" required>
      <label>Delivery Fee (UGX)</label>
      <input type="number" name="delivery_fee" value="<?= e((string)($editZone['delivery_fee'] ?? 0)) ?>" required>
      <button type="submit" class="btn btn-cta"><?= $editZone ? 'Save' : 'Add Zone' ?></button>
    </form>
  </div>
  <div class="admin-panel">
    <h2>All Zones</h2>
    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Zone</th><th>Fee</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($zones as $z): ?>
        <tr>
          <td><?= e($z['zone_name']) ?></td>
          <td><?= money((float)$z['delivery_fee']) ?></td>
          <td><span class="status-pill status-<?= $z['is_active'] ? 'paid' : 'failed' ?>"><?= $z['is_active'] ? 'Active' : 'Inactive' ?></span></td>
          <td class="admin-row-actions">
            <a href="?edit=<?= (int)$z['zone_id'] ?>">Edit</a>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="zone_id" value="<?= (int)$z['zone_id'] ?>"><button type="submit" class="link-btn"><?= $z['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
            <form method="post" class="inline-form" onsubmit="return confirm('Delete this zone?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="zone_id" value="<?= (int)$z['zone_id'] ?>"><button type="submit" class="link-btn link-btn-danger">Delete</button></form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
