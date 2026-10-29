<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $fields = [
            'delivery_enabled' => isset($_POST['delivery_enabled']) ? '1' : '0',
            'delivery_origin_name' => trim($_POST['delivery_origin_name'] ?? ''),
            'delivery_origin_lat' => (string)(float)($_POST['delivery_origin_lat'] ?? 0),
            'delivery_origin_lng' => (string)(float)($_POST['delivery_origin_lng'] ?? 0),
            'delivery_pricing_model' => ($_POST['delivery_pricing_model'] ?? 'bracket') === 'per_km' ? 'per_km' : 'bracket',
            'delivery_base_fee' => (string)max(0, (float)($_POST['delivery_base_fee'] ?? 0)),
            'delivery_price_per_km' => (string)max(0, (float)($_POST['delivery_price_per_km'] ?? 0)),
            'delivery_max_distance_enabled' => isset($_POST['delivery_max_distance_enabled']) ? '1' : '0',
            'delivery_max_distance_km' => (string)max(0, (float)($_POST['delivery_max_distance_km'] ?? 0)),
            'pickup_enabled' => isset($_POST['pickup_enabled']) ? '1' : '0',
            'pickup_location_name' => trim($_POST['pickup_location_name'] ?? ''),
            'pickup_location_address' => trim($_POST['pickup_location_address'] ?? ''),
            'pickup_lat' => (string)(float)($_POST['pickup_lat'] ?? 0),
            'pickup_lng' => (string)(float)($_POST['pickup_lng'] ?? 0),
            'pickup_instructions' => trim($_POST['pickup_instructions'] ?? ''),
            'pickup_phone' => trim($_POST['pickup_phone'] ?? ''),
        ];
        foreach ($fields as $key => $value) {
            katale_set_setting($db, $key, $value);
        }
        flash('success', 'Delivery & pickup settings saved.');
        redirect(APP_URL . '/admin/delivery_settings.php');
    }

    if ($action === 'save_bracket') {
        $bracketId = (int)($_POST['bracket_id'] ?? 0);
        $minKm = (float)($_POST['min_km'] ?? 0);
        $maxKmRaw = trim($_POST['max_km'] ?? '');
        $maxKm = $maxKmRaw === '' ? null : (float)$maxKmRaw;
        $fee = (float)($_POST['fee'] ?? 0);

        if ($minKm < 0 || $fee < 0 || ($maxKm !== null && $maxKm <= $minKm)) {
            flash('error', 'Please provide a valid distance range and fee (max must be greater than min).');
        } elseif ($bracketId > 0) {
            $db->prepare("UPDATE delivery_fee_brackets SET min_km=:min, max_km=:max, fee=:fee WHERE bracket_id=:id")
               ->execute(['min' => $minKm, 'max' => $maxKm, 'fee' => $fee, 'id' => $bracketId]);
            flash('success', 'Bracket updated.');
        } else {
            $nextSort = (int)$db->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM delivery_fee_brackets")->fetchColumn();
            $db->prepare("INSERT INTO delivery_fee_brackets (min_km, max_km, fee, sort_order) VALUES (:min,:max,:fee,:sort)")
               ->execute(['min' => $minKm, 'max' => $maxKm, 'fee' => $fee, 'sort' => $nextSort]);
            flash('success', 'Bracket added.');
        }
        redirect(APP_URL . '/admin/delivery_settings.php');
    }

    if ($action === 'delete_bracket') {
        $db->prepare("DELETE FROM delivery_fee_brackets WHERE bracket_id = :id")->execute(['id' => (int)$_POST['bracket_id']]);
        flash('success', 'Bracket removed.');
        redirect(APP_URL . '/admin/delivery_settings.php');
    }
}

$editBracket = null;
if ($_GET['edit_bracket'] ?? null) {
    $stmt = $db->prepare("SELECT * FROM delivery_fee_brackets WHERE bracket_id = :id");
    $stmt->execute(['id' => (int)$_GET['edit_bracket']]);
    $editBracket = $stmt->fetch();
}

$origin = katale_delivery_origin($db);
$pickup = katale_pickup_location($db);
$pricingModel = katale_get_setting($db, 'delivery_pricing_model', 'bracket');
$deliveryEnabled = katale_get_setting($db, 'delivery_enabled', '1') === '1';
$maxDistanceEnabled = katale_get_setting($db, 'delivery_max_distance_enabled', '1') === '1';
$maxDistanceKm = katale_get_setting($db, 'delivery_max_distance_km', '15');
$baseFee = katale_get_setting($db, 'delivery_base_fee', '0');
$pricePerKm = katale_get_setting($db, 'delivery_price_per_km', '0');
$brackets = $db->query("SELECT * FROM delivery_fee_brackets ORDER BY min_km ASC")->fetchAll();

$pageTitle = 'Delivery & Pickup Settings — MasakaMart Admin';
require_once __DIR__ . '/includes/admin_header.php';
?>
<h1>Delivery &amp; Pickup Settings</h1>

<div class="admin-panel">
  <h2>Delivery</h2>
  <form method="post" class="admin-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_settings">
    <?php // Pickup fields must round-trip unchanged since both forms share the "save_settings" action. ?>
    <input type="hidden" name="pickup_location_name" value="<?= e($pickup['name']) ?>">
    <input type="hidden" name="pickup_location_address" value="<?= e($pickup['address']) ?>">
    <input type="hidden" name="pickup_lat" value="<?= e((string)$pickup['lat']) ?>">
    <input type="hidden" name="pickup_lng" value="<?= e((string)$pickup['lng']) ?>">
    <input type="hidden" name="pickup_phone" value="<?= e($pickup['phone']) ?>">
    <input type="hidden" name="pickup_instructions" value="<?= e($pickup['instructions']) ?>">
    <?php if ($pickup['enabled']): ?><input type="hidden" name="pickup_enabled" value="1"><?php endif; ?>

    <label><input type="checkbox" name="delivery_enabled" <?= $deliveryEnabled ? 'checked' : '' ?>> Delivery enabled</label>

    <label>Delivery Origin Name</label>
    <input type="text" name="delivery_origin_name" value="<?= e($origin['name']) ?>" required>

    <div class="admin-form-grid">
      <div>
        <label>Origin Latitude</label>
        <input type="text" name="delivery_origin_lat" value="<?= e((string)$origin['lat']) ?>" required>
      </div>
      <div>
        <label>Origin Longitude</label>
        <input type="text" name="delivery_origin_lng" value="<?= e((string)$origin['lng']) ?>" required>
      </div>
    </div>
    <p class="field-hint">Default is Masaka Main Market. Find exact coordinates by right-clicking the location on Google Maps and copying the lat/lng shown.</p>

    <label>Pricing Model</label>
    <select name="delivery_pricing_model" id="pricingModelSelect">
      <option value="bracket" <?= $pricingModel === 'bracket' ? 'selected' : '' ?>>Distance brackets (fixed fee per range)</option>
      <option value="per_km" <?= $pricingModel === 'per_km' ? 'selected' : '' ?>>Base fee + price per kilometre</option>
    </select>

    <div class="admin-form-grid">
      <div>
        <label>Base Fee (UGX) — used only in per-km model</label>
        <input type="number" step="0.01" name="delivery_base_fee" value="<?= e($baseFee) ?>">
      </div>
      <div>
        <label>Price per Kilometre (UGX) — used only in per-km model</label>
        <input type="number" step="0.01" name="delivery_price_per_km" value="<?= e($pricePerKm) ?>">
      </div>
    </div>

    <label><input type="checkbox" name="delivery_max_distance_enabled" <?= $maxDistanceEnabled ? 'checked' : '' ?>> Enforce a maximum delivery distance</label>
    <label>Maximum Delivery Distance (km)</label>
    <input type="number" step="0.1" name="delivery_max_distance_km" value="<?= e($maxDistanceKm) ?>">

    <button type="submit" class="btn btn-cta">Save Delivery Settings</button>
  </form>
</div>

<div class="admin-grid-2">
  <div class="admin-panel">
    <h2><?= $editBracket ? 'Edit Bracket' : 'Add Distance Bracket' ?></h2>
    <p class="field-hint">Only used when the pricing model above is "Distance brackets". Ranges should not overlap; leave "Up to (km)" blank for the open-ended top bracket (e.g. "15+ km").</p>
    <form method="post" class="admin-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_bracket">
      <?php if ($editBracket): ?><input type="hidden" name="bracket_id" value="<?= (int)$editBracket['bracket_id'] ?>"><?php endif; ?>
      <div class="admin-form-grid">
        <div>
          <label>From (km)</label>
          <input type="number" step="0.1" name="min_km" value="<?= e((string)($editBracket['min_km'] ?? '0')) ?>" required>
        </div>
        <div>
          <label>Up to (km) — blank = no upper limit</label>
          <input type="number" step="0.1" name="max_km" value="<?= e($editBracket['max_km'] !== null ? (string)$editBracket['max_km'] : '') ?>">
        </div>
      </div>
      <label>Fee (UGX)</label>
      <input type="number" step="0.01" name="fee" value="<?= e((string)($editBracket['fee'] ?? '0')) ?>" required>
      <button type="submit" class="btn btn-cta"><?= $editBracket ? 'Save' : 'Add Bracket' ?></button>
    </form>
  </div>

  <div class="admin-panel">
    <h2>Current Brackets</h2>
    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>From</th><th>Up to</th><th>Fee</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($brackets as $b): ?>
        <tr>
          <td><?= e((string)$b['min_km']) ?> km</td>
          <td><?= $b['max_km'] !== null ? e((string)$b['max_km']) . ' km' : 'No limit' ?></td>
          <td><?= money((float)$b['fee']) ?></td>
          <td class="admin-row-actions">
            <a href="?edit_bracket=<?= (int)$b['bracket_id'] ?>">Edit</a>
            <form method="post" class="inline-form" onsubmit="return confirm('Remove this bracket?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete_bracket"><input type="hidden" name="bracket_id" value="<?= (int)$b['bracket_id'] ?>"><button type="submit" class="link-btn link-btn-danger">Delete</button></form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$brackets): ?><tr><td colspan="4">No brackets configured yet — every delivery will be treated as out of range until you add at least one.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<div class="admin-panel">
  <h2>Pickup</h2>
  <form method="post" class="admin-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_settings">
    <?php // Delivery fields must round-trip unchanged since this is the same "save_settings" action. ?>
    <input type="hidden" name="delivery_origin_name" value="<?= e($origin['name']) ?>">
    <input type="hidden" name="delivery_origin_lat" value="<?= e((string)$origin['lat']) ?>">
    <input type="hidden" name="delivery_origin_lng" value="<?= e((string)$origin['lng']) ?>">
    <input type="hidden" name="delivery_pricing_model" value="<?= e($pricingModel) ?>">
    <input type="hidden" name="delivery_base_fee" value="<?= e($baseFee) ?>">
    <input type="hidden" name="delivery_price_per_km" value="<?= e($pricePerKm) ?>">
    <input type="hidden" name="delivery_max_distance_km" value="<?= e($maxDistanceKm) ?>">
    <?php if ($deliveryEnabled): ?><input type="hidden" name="delivery_enabled" value="1"><?php endif; ?>
    <?php if ($maxDistanceEnabled): ?><input type="hidden" name="delivery_max_distance_enabled" value="1"><?php endif; ?>

    <label><input type="checkbox" name="pickup_enabled" <?= $pickup['enabled'] ? 'checked' : '' ?>> Pickup enabled</label>

    <label>Pickup Location Name</label>
    <input type="text" name="pickup_location_name" value="<?= e($pickup['name']) ?>" required>

    <label>Pickup Address</label>
    <input type="text" name="pickup_location_address" value="<?= e($pickup['address']) ?>" required>

    <div class="admin-form-grid">
      <div>
        <label>Pickup Latitude</label>
        <input type="text" name="pickup_lat" value="<?= e((string)$pickup['lat']) ?>">
      </div>
      <div>
        <label>Pickup Longitude</label>
        <input type="text" name="pickup_lng" value="<?= e((string)$pickup['lng']) ?>">
      </div>
    </div>

    <label>Pickup Phone Number</label>
    <input type="text" name="pickup_phone" value="<?= e($pickup['phone']) ?>">

    <label>Pickup Instructions</label>
    <textarea name="pickup_instructions" rows="3"><?= e($pickup['instructions']) ?></textarea>

    <button type="submit" class="btn btn-cta">Save Pickup Settings</button>
  </form>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
