<?php
require_once __DIR__ . '/includes/admin_bootstrap.php';
require_admin();

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if ($action === 'save') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if ($name === '') {
            flash('error', 'Category name is required.');
        } else {
            $slug = slugify($name);
            try {
                $imagePath = handle_image_upload('image');
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
                redirect(APP_URL . '/admin/categories.php');
            }

            if ($categoryId > 0) {
                $oldImageStmt = $db->prepare("SELECT image_path FROM categories WHERE category_id=:id");
                $oldImageStmt->execute(['id' => $categoryId]);
                $oldImage = $oldImageStmt->fetchColumn() ?: null;
                $removeImage = isset($_POST['remove_image']) && $_POST['remove_image'] === '1';
                $updateImage = $imagePath !== null || $removeImage;
                $sql = "UPDATE categories SET name=:name, slug=:slug, description=:desc, sort_order=:sort" .
                       ($updateImage ? ", image_path=:img" : "") . " WHERE category_id=:id";
                $params = ['name' => $name, 'slug' => $slug, 'desc' => $description ?: null, 'sort' => $sortOrder, 'id' => $categoryId];
                if ($updateImage) $params['img'] = $imagePath;
                $db->prepare($sql)->execute($params);
                if ($updateImage && $oldImage && $oldImage !== $imagePath) {
                    delete_uploaded_image($oldImage);
                }
                log_admin_activity($db, 'update_category', $name);
                flash('success', 'Category updated.');
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO categories (name, slug, description, image_path, sort_order) VALUES (:name, :slug, :desc, :img, :sort)"
                );
                $stmt->execute(['name' => $name, 'slug' => $slug, 'desc' => $description ?: null, 'img' => $imagePath, 'sort' => $sortOrder]);
                log_admin_activity($db, 'create_category', $name);
                flash('success', 'Category created.');
            }
        }
        redirect(APP_URL . '/admin/categories.php');
    }

    if ($action === 'toggle') {
        $categoryId = (int)$_POST['category_id'];
        $db->prepare("UPDATE categories SET is_active = 1 - is_active WHERE category_id = :id")->execute(['id' => $categoryId]);
        flash('success', 'Category status updated.');
        redirect(APP_URL . '/admin/categories.php');
    }

    if ($action === 'delete') {
        $categoryId = (int)$_POST['category_id'];
        $inUse = $db->prepare("SELECT COUNT(*) AS c FROM products WHERE category_id = :id");
        $inUse->execute(['id' => $categoryId]);
        if ((int)$inUse->fetch()['c'] > 0) {
            flash('error', 'Cannot delete a category that still has products. Reassign or delete those products first.');
        } else {
            $db->prepare("DELETE FROM categories WHERE category_id = :id")->execute(['id' => $categoryId]);
            log_admin_activity($db, 'delete_category', (string)$categoryId);
            flash('success', 'Category deleted.');
        }
        redirect(APP_URL . '/admin/categories.php');
    }
}

$editCategory = null;
if (($_GET['edit'] ?? null)) {
    $stmt = $db->prepare("SELECT * FROM categories WHERE category_id = :id");
    $stmt->execute(['id' => (int)$_GET['edit']]);
    $editCategory = $stmt->fetch();
}

$search = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM categories" . ($search !== '' ? " WHERE name LIKE :s" : "") . " ORDER BY sort_order, name";
$stmt = $db->prepare($sql);
if ($search !== '') $stmt->execute(['s' => '%' . $search . '%']); else $stmt->execute();
$categories = $stmt->fetchAll();

$pageTitle = 'Categories — MasakaMart Admin';
require_once __DIR__ . '/includes/admin_header.php';
?>
<h1>Category Management</h1>

<div class="admin-grid-2">
  <div class="admin-panel">
    <h2><?= $editCategory ? 'Edit Category' : 'Add Category' ?></h2>
    <form method="post" enctype="multipart/form-data" class="admin-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <?php if ($editCategory): ?><input type="hidden" name="category_id" value="<?= (int)$editCategory['category_id'] ?>"><?php endif; ?>
      <label>Name</label>
      <input type="text" name="name" value="<?= e($editCategory['name'] ?? '') ?>" required>
      <label>Description</label>
      <textarea name="description"><?= e($editCategory['description'] ?? '') ?></textarea>
      <label>Sort Order</label>
      <input type="number" name="sort_order" value="<?= (int)($editCategory['sort_order'] ?? 0) ?>">
      <label>Image (optional)</label>
      <input type="file" name="image" accept="image/png,image/jpeg,image/webp" id="categoryImageInput">
      <p class="field-hint">JPEG, PNG or WebP. Maximum 2 MB.</p>
      <div class="category-image-preview" id="categoryImagePreview" <?= empty($editCategory['image_path']) ? 'hidden' : '' ?>>
        <img id="categoryImagePreviewImg" src="<?= !empty($editCategory['image_path']) ? UPLOAD_URL . e($editCategory['image_path']) : '' ?>" alt="Category image preview">
      </div>
      <?php if (!empty($editCategory['image_path'])): ?>
        <label class="category-remove-image"><input type="checkbox" name="remove_image" value="1"> Remove current image</label>
      <?php endif; ?>
      <button type="submit" class="btn btn-cta"><?= $editCategory ? 'Save Changes' : 'Add Category' ?></button>
      <?php if ($editCategory): ?><a href="<?= APP_URL ?>/admin/categories.php" class="btn btn-outline">Cancel</a><?php endif; ?>
    </form>
  </div>

  <div class="admin-panel">
    <h2>All Categories</h2>
    <form method="get" class="admin-search-form">
      <input type="text" name="q" placeholder="Search categories..." value="<?= e($search) ?>">
      <button type="submit" class="btn btn-sm">Search</button>
    </form>
    <?php if (empty($categories)): ?>
      <p class="empty-state-sm">No categories yet.</p>
    <?php else: ?>
      <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Name</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($categories as $cat): ?>
          <tr>
            <td><?= e($cat['name']) ?></td>
            <td><span class="status-pill status-<?= $cat['is_active'] ? 'paid' : 'failed' ?>"><?= $cat['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td class="admin-row-actions">
              <a href="?edit=<?= (int)$cat['category_id'] ?>">Edit</a>
              <form method="post" class="inline-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="category_id" value="<?= (int)$cat['category_id'] ?>">
                <button type="submit" class="link-btn"><?= $cat['is_active'] ? 'Deactivate' : 'Activate' ?></button>
              </form>
              <form method="post" class="inline-form" onsubmit="return confirm('Delete this category?');">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="category_id" value="<?= (int)$cat['category_id'] ?>">
                <button type="submit" class="link-btn link-btn-danger">Delete</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const input = document.getElementById('categoryImageInput');
  const preview = document.getElementById('categoryImagePreview');
  const image = document.getElementById('categoryImagePreviewImg');
  if (!input || !preview || !image) return;
  input.addEventListener('change', function () {
    const file = input.files && input.files[0];
    if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
      input.value = '';
      window.alert('Choose a JPEG, PNG or WebP image under 2 MB.');
      return;
    }
    image.src = URL.createObjectURL(file);
    preview.hidden = false;
  });
});
</script>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
