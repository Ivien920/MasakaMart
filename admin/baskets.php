<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_admin();

$products = $db->query("SELECT product_id, name, unit FROM products WHERE status = 'active' ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $basketId = (int)($_POST['basket_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '') {
            flash('error', 'Basket name is required.');
            redirect(APP_URL . '/admin/baskets.php');
        }

        try {
            $imagePath = handle_image_upload('image');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect(APP_URL . '/admin/baskets.php');
        }

        $slug = slugify($name) . ($basketId ? '' : '-' . substr(md5(uniqid()), 0, 5));

        if ($basketId > 0) {
            $sql = "UPDATE baskets SET name=:name, description=:desc" . ($imagePath ? ", image_path=:img" : "") . " WHERE basket_id=:id";
            $params = ['name' => $name, 'desc' => $description ?: null, 'id' => $basketId];
            if ($imagePath) $params['img'] = $imagePath;
            $db->prepare($sql)->execute($params);
        } else {
            $db->prepare("INSERT INTO baskets (name, slug, description, image_path) VALUES (:name, :slug, :desc, :img)")
               ->execute(['name' => $name, 'slug' => $slug, 'desc' => $description ?: null, 'img' => $imagePath]);
            $basketId = (int)$db->lastInsertId();
        }

        // Replace basket items with the submitted set
        $db->prepare("DELETE FROM basket_items WHERE basket_id = :id")->execute(['id' => $basketId]);
        $productIds = $_POST['item_product_id'] ?? [];
        $quantities = $_POST['item_quantity'] ?? [];
        $insertItem = $db->prepare("INSERT INTO basket_items (basket_id, product_id, quantity) VALUES (:bid, :pid, :qty)");
        foreach ($productIds as $i => $pid) {
            $pid = (int)$pid;
            $qty = (float)($quantities[$i] ?? 0);
            if ($pid > 0 && $qty > 0) {
                $insertItem->execute(['bid' => $basketId, 'pid' => $pid, 'qty' => $qty]);
            }
        }
        log_admin_activity($db, 'save_basket', $name);
        flash('success', 'Basket saved.');
        redirect(APP_URL . '/admin/baskets.php');
    }

    if ($action === 'toggle') {
        $db->prepare("UPDATE baskets SET is_active = 1 - is_active WHERE basket_id = :id")->execute(['id' => (int)$_POST['basket_id']]);
        redirect(APP_URL . '/admin/baskets.php');
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM baskets WHERE basket_id = :id")->execute(['id' => (int)$_POST['basket_id']]);
        flash('success', 'Basket deleted.');
        redirect(APP_URL . '/admin/baskets.php');
    }
}

$editBasket = null;
$editItems = [];
if ($_GET['edit'] ?? null) {
    $stmt = $db->prepare("SELECT * FROM baskets WHERE basket_id = :id");
    $stmt->execute(['id' => (int)$_GET['edit']]);
    $editBasket = $stmt->fetch();
    if ($editBasket) {
        $itemStmt = $db->prepare("SELECT * FROM basket_items WHERE basket_id = :id");
        $itemStmt->execute(['id' => $editBasket['basket_id']]);
        $editItems = $itemStmt->fetchAll();
    }
}

$baskets = $db->query("SELECT * FROM baskets ORDER BY created_at DESC")->fetchAll();

$pageTitle = 'Shopping Baskets — MasakaMart Admin';
require_once __DIR__ . '/includes/admin_header.php';
?>
<h1>Ready-Made Shopping Baskets</h1>

<div class="admin-panel">
  <h2><?= $editBasket ? 'Edit Basket' : 'Add Basket' ?></h2>
  <form method="post" enctype="multipart/form-data" class="admin-form" id="basketForm">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <?php if ($editBasket): ?><input type="hidden" name="basket_id" value="<?= (int)$editBasket['basket_id'] ?>"><?php endif; ?>
    <label>Basket Name</label>
    <input type="text" name="name" value="<?= e($editBasket['name'] ?? '') ?>" required>
    <label>Description</label>
    <textarea name="description"><?= e($editBasket['description'] ?? '') ?></textarea>
    <label>Image (optional)</label>
    <input type="file" name="image" accept="image/png,image/jpeg,image/webp">

    <h3>Basket Items</h3>
    <div id="basketItems">
      <?php
      $rows = $editItems ?: [['product_id' => '', 'quantity' => '']];
      foreach ($rows as $row): ?>
        <div class="basket-item-row">
          <select name="item_product_id[]">
            <option value="">Select product…</option>
            <?php foreach ($products as $p): ?>
              <option value="<?= (int)$p['product_id'] ?>" data-unit="<?= e($p['unit']) ?>" <?= (($row['product_id'] ?? '') == $p['product_id']) ? 'selected' : '' ?>><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="number" step="0.1" name="item_quantity[]" placeholder="Qty" value="<?= e((string)($row['quantity'] ?? '')) ?>">
          <button type="button" class="link-btn link-btn-danger remove-basket-row">Remove</button>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-sm" id="addBasketRow">+ Add Product</button>
    <br><br>
    <button type="submit" class="btn btn-cta"><?= $editBasket ? 'Save Basket' : 'Create Basket' ?></button>
  </form>
</div>

<div class="admin-panel">
  <h2>All Baskets</h2>
  <div class="table-scroll">
  <table class="data-table">
    <thead><tr><th>Name</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($baskets as $b): ?>
      <tr>
        <td><?= e($b['name']) ?></td>
        <td><span class="status-pill status-<?= $b['is_active'] ? 'paid' : 'failed' ?>"><?= $b['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td class="admin-row-actions">
          <a href="?edit=<?= (int)$b['basket_id'] ?>">Edit</a>
          <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="basket_id" value="<?= (int)$b['basket_id'] ?>"><button type="submit" class="link-btn"><?= $b['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
          <form method="post" class="inline-form" onsubmit="return confirm('Delete this basket?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="basket_id" value="<?= (int)$b['basket_id'] ?>"><button type="submit" class="link-btn link-btn-danger">Delete</button></form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
(function () {
  var II = MasakaMart.integerInput;
  var container = document.getElementById('basketItems');

  /** Get the unit for the currently selected product in a given row. */
  function getRowUnit(row) {
    var sel = row.querySelector('select[name="item_product_id[]"]');
    if (!sel || !sel.selectedOptions.length) return '';
    return sel.selectedOptions[0].getAttribute('data-unit') || '';
  }

  /** Wire up integer enforcement on a single basket-item row. */
  function initRow(row) {
    var sel = row.querySelector('select[name="item_product_id[]"]');
    var qtyInput = row.querySelector('input[name="item_quantity[]"]');
    if (!sel || !qtyInput) return;

    function rowUsesWholeNumbers() {
      return II.unitRequiresWholeNumber(getRowUnit(row));
    }

    // Protect the qty input
    II.protect(qtyInput, rowUsesWholeNumbers);

    // When the product changes, update step + clear invalid values
    sel.addEventListener('change', function () {
      var whole = rowUsesWholeNumbers();
      II.syncStep(qtyInput, whole);
      // If switching to a whole-number unit and the value is decimal, clear it
      if (whole && qtyInput.value !== '' && !/^\d+$/.test(qtyInput.value)) {
        qtyInput.value = '';
        qtyInput.setCustomValidity(II.ERROR_MSG);
        qtyInput.reportValidity();
      }
    });

    // Set initial step based on the currently selected product
    II.syncStep(qtyInput, rowUsesWholeNumbers());
  }

  // Initialise existing rows
  container.querySelectorAll('.basket-item-row').forEach(initRow);

  // "Add Product" button — clone row & re-init
  document.getElementById('addBasketRow').addEventListener('click', function () {
    var row = container.firstElementChild.cloneNode(true);
    row.querySelectorAll('select, input').forEach(function (el) { el.value = ''; });
    // Remove any leftover warning elements from the cloned row
    row.querySelectorAll('.int-input-warning').forEach(function (el) { el.remove(); });
    container.appendChild(row);
    initRow(row);
  });

  // "Remove" button delegation
  container.addEventListener('click', function (e) {
    if (e.target.classList.contains('remove-basket-row') && document.querySelectorAll('.basket-item-row').length > 1) {
      e.target.closest('.basket-item-row').remove();
    }
  });

  // Guard basket form submission
  var basketForm = document.getElementById('basketForm');
  if (basketForm) {
    basketForm.addEventListener('submit', function (ev) {
      var rows = container.querySelectorAll('.basket-item-row');
      var bad = false;
      rows.forEach(function (row) {
        var qtyInput = row.querySelector('input[name="item_quantity[]"]');
        if (!qtyInput) return;
        var whole = II.unitRequiresWholeNumber(getRowUnit(row));
        if (whole && qtyInput.value !== '' && !/^\d+$/.test(qtyInput.value)) {
          qtyInput.value = '';
          qtyInput.setCustomValidity(II.ERROR_MSG);
          bad = true;
        }
      });
      if (bad) {
        ev.preventDefault();
        var first = basketForm.querySelector('input[aria-invalid="true"]');
        if (first) first.reportValidity();
      }
    });
  }
})();
</script>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
