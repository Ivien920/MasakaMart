<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_admin();

$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$action = $_POST['action'] ?? 'list';
$wholeQuantityUnits = ['piece', 'bunch', 'tray', 'crate', 'packet', 'sack', 'basket'];

function validate_admin_quantity(string $value, bool $wholeOnly, string $label): array
{
  $pattern = $wholeOnly ? '/^\d+$/' : '/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/';
  if (!preg_match($pattern, $value)) {
    return [null, $label . ($wholeOnly ? ' must be a whole number.' : ' must be a valid non-negative number with up to two decimal places.')];
  }

  return [$wholeOnly ? (int)$value : (float)$value, null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if ($action === 'save') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $unit = trim($_POST['unit'] ?? 'kg');
        $regularPrice = (float)($_POST['regular_price'] ?? 0);
        $wholeQuantity = in_array($unit, $wholeQuantityUnits, true);
        [$stock, $stockError] = validate_admin_quantity(trim((string)($_POST['stock_quantity'] ?? '0')), $wholeQuantity, 'Stock quantity');
        [$lowStock, $lowStockError] = validate_admin_quantity(trim((string)($_POST['low_stock_threshold'] ?? '5')), $wholeQuantity, 'Low stock threshold');
        $keywords = trim($_POST['search_keywords'] ?? '');
        $isAvailable = isset($_POST['is_available']) ? 1 : 0;
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if ($name === '' || $categoryId <= 0 || $regularPrice <= 0 || $stockError || $lowStockError) {
          flash('error', $stockError ?: ($lowStockError ?: 'Name, category, and a valid price are required.'));
            redirect(APP_URL . '/admin/products.php');
        }

        try {
            $imagePath = handle_image_upload('image');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect(APP_URL . '/admin/products.php');
        }

        $slug = slugify($name) . '-' . substr(md5(uniqid()), 0, 5);

        if ($productId > 0) {
            $slugStmt = $db->prepare("SELECT slug FROM products WHERE product_id = :id");
            $slugStmt->execute(['id' => $productId]);
            $slug = $slugStmt->fetch()['slug']; // keep existing slug on edit

            $sql = "UPDATE products SET category_id=:cat, name=:name, description=:desc, unit=:unit,
                    regular_price=:price, stock_quantity=:stock, low_stock_threshold=:low,
                    search_keywords=:kw, is_available=:avail, status=:status" .
                    ($imagePath ? ", image_path=:img" : "") . " WHERE product_id=:id";
            $params = [
                'cat' => $categoryId, 'name' => $name, 'desc' => $description ?: null, 'unit' => $unit,
                'price' => $regularPrice, 'stock' => $stock, 'low' => $lowStock, 'kw' => $keywords ?: null,
                'avail' => $isAvailable, 'status' => $status, 'id' => $productId,
            ];
            if ($imagePath) $params['img'] = $imagePath;
            $db->prepare($sql)->execute($params);
            log_admin_activity($db, 'update_product', $name);
            flash('success', 'Product updated.');
        } else {
            $stmt = $db->prepare(
                "INSERT INTO products (category_id, name, slug, description, image_path, unit, regular_price,
                                        stock_quantity, low_stock_threshold, search_keywords, is_available, status)
                 VALUES (:cat, :name, :slug, :desc, :img, :unit, :price, :stock, :low, :kw, :avail, :status)"
            );
            $stmt->execute([
                'cat' => $categoryId, 'name' => $name, 'slug' => $slug, 'desc' => $description ?: null,
                'img' => $imagePath, 'unit' => $unit, 'price' => $regularPrice, 'stock' => $stock,
                'low' => $lowStock, 'kw' => $keywords ?: null, 'avail' => $isAvailable, 'status' => $status,
            ]);
            log_admin_activity($db, 'create_product', $name);
            flash('success', 'Product created.');
        }
        redirect(APP_URL . '/admin/products.php');
    }

    if ($action === 'toggle') {
        $db->prepare("UPDATE products SET is_available = 1 - is_available WHERE product_id = :id")
           ->execute(['id' => (int)$_POST['product_id']]);
        flash('success', 'Availability updated.');
        redirect(APP_URL . '/admin/products.php');
    }

    if ($action === 'delete') {
        $db->prepare("UPDATE products SET status = 'inactive' WHERE product_id = :id")
           ->execute(['id' => (int)$_POST['product_id']]);
        log_admin_activity($db, 'deactivate_product', (string)$_POST['product_id']);
        flash('success', 'Product removed from the storefront (soft-deleted to preserve order history).');
        redirect(APP_URL . '/admin/products.php');
    }

    if ($action === 'add_stock') {
        $productId = (int)$_POST['product_id'];
      $unitStmt = $db->prepare("SELECT unit FROM products WHERE product_id = :id");
      $unitStmt->execute(['id' => $productId]);
      $productUnit = (string)($unitStmt->fetchColumn() ?: '');
      [$qty, $qtyError] = validate_admin_quantity(trim((string)($_POST['qty'] ?? '')), in_array($productUnit, $wholeQuantityUnits, true), 'Stock addition');
      if ($qtyError || $qty === null || $qty <= 0) {
        flash('error', $qtyError ?: 'Stock addition must be greater than zero.');
      } else {
            $db->prepare("UPDATE products SET stock_quantity = stock_quantity + :qty WHERE product_id = :id")
               ->execute(['qty' => $qty, 'id' => $productId]);
            $db->prepare("INSERT INTO inventory_logs (product_id, change_qty, reason, admin_id) VALUES (:pid, :qty, 'restock', :aid)")
               ->execute(['pid' => $productId, 'qty' => $qty, 'aid' => current_admin()['admin_id']]);
            flash('success', 'Stock added.');
        }
        redirect(APP_URL . '/admin/products.php');
    }

    if ($action === 'save_discount') {
        $productId = (int)$_POST['product_id'];
        $type = $_POST['discount_type'] === 'fixed' ? 'fixed' : 'percentage';
        $value = (float)$_POST['discount_value'];
        $starts = $_POST['starts_at'];
        $ends = $_POST['ends_at'];

        if ($value <= 0 || strtotime($ends) <= strtotime($starts)) {
            flash('error', 'Please provide a valid discount value and date range.');
        } else {
            $db->prepare("UPDATE product_discounts SET is_active = 0 WHERE product_id = :pid")->execute(['pid' => $productId]);
            $db->prepare(
                "INSERT INTO product_discounts (product_id, discount_type, discount_value, starts_at, ends_at, is_active)
                 VALUES (:pid, :type, :val, :start, :end, 1)"
            )->execute(['pid' => $productId, 'type' => $type, 'val' => $value, 'start' => $starts, 'end' => $ends]);
            flash('success', 'Discount applied.');
        }
        redirect(APP_URL . '/admin/products.php');
    }
}

$editProduct = null;
if ($_GET['edit'] ?? null) {
    $stmt = $db->prepare("SELECT * FROM products WHERE product_id = :id");
    $stmt->execute(['id' => (int)$_GET['edit']]);
    $editProduct = $stmt->fetch();
}

// Listing with search/filter/pagination
$search = trim($_GET['q'] ?? '');
$filterCategory = (int)($_GET['category'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = "1=1";
$params = [];
if ($search !== '') { $where .= " AND p.name LIKE :s"; $params['s'] = '%' . $search . '%'; }
if ($filterCategory) { $where .= " AND p.category_id = :cat"; $params['cat'] = $filterCategory; }

$countStmt = $db->prepare("SELECT COUNT(*) AS c FROM products p WHERE $where");
$countStmt->execute($params);
$totalProducts = (int)$countStmt->fetch()['c'];
$totalPages = max(1, (int)ceil($totalProducts / $perPage));

$sql = "SELECT p.*, c.name AS category_name FROM products p JOIN categories c ON c.category_id = p.category_id
        WHERE $where ORDER BY p.created_at DESC LIMIT $perPage OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = array_map(fn($p) => apply_pricing($db, $p), $stmt->fetchAll());

$pageTitle = 'Products — MasakaMart Admin';
require_once __DIR__ . '/includes/admin_header.php';
?>
<h1>Product Management</h1>

<div class="admin-panel">
  <h2><?= $editProduct ? 'Edit Product' : 'Add Product' ?></h2>
  <form method="post" enctype="multipart/form-data" class="admin-form admin-form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($editProduct): ?><input type="hidden" name="product_id" value="<?= (int)$editProduct['product_id'] ?>"><?php endif; ?>

    <div><label>Name</label><input type="text" name="name" value="<?= e($editProduct['name'] ?? '') ?>" required></div>
    <div><label>Category</label>
      <select name="category_id" required>
        <option value="">Select…</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= (int)$cat['category_id'] ?>" <?= (($editProduct['category_id'] ?? null) == $cat['category_id']) ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Unit</label>
      <select name="unit" id="productUnit">
        <?php foreach (['kg','g','piece','bunch','tray','crate','litre','packet','sack','basket'] as $u): ?>
          <option value="<?= $u ?>" <?= (($editProduct['unit'] ?? 'kg') === $u) ? 'selected' : '' ?>><?= $u ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Regular Price (UGX)</label><input type="number" step="1" name="regular_price" value="<?= e((string)($editProduct['regular_price'] ?? '')) ?>" required></div>
    <?php $formUnit = $editProduct['unit'] ?? 'kg'; $formUsesWholeQuantities = in_array($formUnit, $wholeQuantityUnits, true); ?>
    <div><label>Stock Quantity</label><input type="number" min="0" step="<?= $formUsesWholeQuantities ? '1' : '0.01' ?>" name="stock_quantity" value="<?= e((string)($editProduct['stock_quantity'] ?? 0)) ?>"></div>
    <div><label>Low Stock Threshold</label><input type="number" min="0" step="<?= $formUsesWholeQuantities ? '1' : '0.01' ?>" name="low_stock_threshold" value="<?= e((string)($editProduct['low_stock_threshold'] ?? 5)) ?>"></div>
    <div class="span-2"><label>Description</label><textarea name="description"><?= e($editProduct['description'] ?? '') ?></textarea></div>
    <div class="span-2"><label>Search Keywords (comma-separated)</label><input type="text" name="search_keywords" value="<?= e($editProduct['search_keywords'] ?? '') ?>"></div>
    <div><label>Image</label><input type="file" name="image" accept="image/png,image/jpeg,image/webp"></div>
    <div>
      <label><input type="checkbox" name="is_available" <?= (($editProduct['is_available'] ?? 1)) ? 'checked' : '' ?>> Available for sale</label>
      <label>Status</label>
      <select name="status">
        <option value="active" <?= (($editProduct['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= (($editProduct['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
      </select>
    </div>
    <div class="span-2">
      <button type="submit" class="btn btn-cta"><?= $editProduct ? 'Save Changes' : 'Add Product' ?></button>
      <?php if ($editProduct): ?><a href="<?= APP_URL ?>/admin/products.php" class="btn btn-outline">Cancel</a><?php endif; ?>
    </div>
  </form>

  <?php if ($editProduct): ?>
    <hr>
    <h3>Add Stock</h3>
    <form method="post" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_stock"><input type="hidden" name="product_id" value="<?= (int)$editProduct['product_id'] ?>">
      <input type="number" min="0" step="<?= in_array($editProduct['unit'], $wholeQuantityUnits, true) ? '1' : '0.01' ?>" name="qty" placeholder="Quantity to add" required>
      <button type="submit" class="btn btn-sm">Add Stock</button>
    </form>

    <h3>Apply Discount</h3>
    <form method="post" class="admin-form admin-form-grid">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_discount"><input type="hidden" name="product_id" value="<?= (int)$editProduct['product_id'] ?>">
      <div><label>Type</label><select name="discount_type"><option value="percentage">Percentage</option><option value="fixed">Fixed Amount</option></select></div>
      <div><label>Value</label><input type="number" step="0.01" name="discount_value" required></div>
      <div><label>Starts</label><input type="datetime-local" name="starts_at" required></div>
      <div><label>Ends</label><input type="datetime-local" name="ends_at" required></div>
      <div class="span-2"><button type="submit" class="btn">Apply Discount</button></div>
    </form>
  <?php endif; ?>
</div>

<div class="admin-panel">
  <h2>All Products (<?= $totalProducts ?>)</h2>
  <form method="get" class="admin-search-form">
    <input type="text" name="q" placeholder="Search products..." value="<?= e($search) ?>">
    <select name="category">
      <option value="0">All Categories</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= (int)$cat['category_id'] ?>" <?= $filterCategory === (int)$cat['category_id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-sm">Filter</button>
  </form>

  <?php if (empty($products)): ?>
    <p class="empty-state-sm">No products match your filters.</p>
  <?php else: ?>
    <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($products as $p): ?>
        <tr>
          <td><?= e($p['name']) ?></td>
          <td><?= e($p['category_name']) ?></td>
          <td><?= money($p['effective_price']) ?><?= $p['discount_active'] ? ' <span class="badge-inline">'.e($p['discount_label']).'</span>' : '' ?></td>
          <td class="<?= (float)$p['stock_quantity'] <= (float)$p['low_stock_threshold'] ? 'text-warning' : '' ?>"><?= e((string)$p['stock_quantity']) ?> <?= e($p['unit']) ?></td>
          <td><span class="status-pill status-<?= $p['is_available'] && $p['status']==='active' ? 'paid' : 'failed' ?>"><?= $p['status'] === 'inactive' ? 'Inactive' : ($p['is_available'] ? 'Available' : 'Unavailable') ?></span></td>
          <td class="admin-row-actions">
            <a href="?edit=<?= (int)$p['product_id'] ?>">Edit</a>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="product_id" value="<?= (int)$p['product_id'] ?>"><button type="submit" class="link-btn"><?= $p['is_available'] ? 'Hide' : 'Show' ?></button></form>
            <form method="post" class="inline-form" onsubmit="return confirm('Remove this product from the storefront?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="product_id" value="<?= (int)$p['product_id'] ?>"><button type="submit" class="link-btn link-btn-danger">Delete</button></form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>&category=<?= $filterCategory ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var II = MasakaMart.integerInput;
  var unitSelect = document.getElementById('productUnit');
  var quantityInputs = document.querySelectorAll(
    'input[name="stock_quantity"], input[name="low_stock_threshold"], input[name="qty"]'
  );

  function usesWholeNumbers() {
    return unitSelect && II.unitRequiresWholeNumber(unitSelect.value);
  }

  // Attach enforcement to each quantity input
  for (var i = 0; i < quantityInputs.length; i++) {
    II.protect(quantityInputs[i], usesWholeNumbers);
  }

  // Sync step attributes when unit changes
  function updateSteps() {
    var whole = usesWholeNumbers();
    for (var j = 0; j < quantityInputs.length; j++) {
      II.syncStep(quantityInputs[j], whole);
    }
  }
  if (unitSelect) unitSelect.addEventListener('change', updateSteps);
  updateSteps();

  // Guard every form that contains a quantity input
  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      II.guardSubmit(e, quantityInputs, usesWholeNumbers);
    });
  });
});
</script>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
