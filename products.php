<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/catalogue.php';

$q = trim($_GET['q'] ?? '');
$categoryId = isset($_GET['category']) ? (int)$_GET['category'] : null;
$sort = $_GET['sort'] ?? 'newest';

$category = null;
if ($categoryId) {
    $stmt = $db->prepare("SELECT * FROM categories WHERE category_id = :id AND is_active = 1");
    $stmt->execute(['id' => $categoryId]);
    $category = $stmt->fetch() ?: null;
}

if ($q !== '') {
    $products = search_products($db, $q, $categoryId, $sort === 'newest' ? 'relevance' : $sort);
    log_search($db, $q, count($products));
    $pageTitle = 'Search: ' . $q . ' — MasakaMart';
} else {
    $products = browse_products($db, $categoryId, $sort);
    $pageTitle = ($category ? $category['name'] : 'All Products') . ' — MasakaMart';
}

$GLOBALS['seoCategory'] = $category;
$GLOBALS['seoItems'] = $products;
$GLOBALS['seoItemListName'] = $category['name'] ?? ($q !== '' ? 'Search results' : 'All products');

require_once __DIR__ . '/includes/header.php';
?>
<section class="container section">
  <?php if ($category && $q === ''): ?>
    <div class="category-banner">
      <?php if (!empty($category['image_path'])): ?>
        <img src="<?= UPLOAD_URL . e($category['image_path']) ?>" alt="" width="1200" height="420">
      <?php endif; ?>
      <div class="category-banner-copy">
        <a class="category-back-link" href="<?= APP_URL ?>/products.php">← All products</a>
        <h1><?= e($category['name']) ?></h1>
        <?php if (!empty($category['description'])): ?><p><?= e($category['description']) ?></p><?php endif; ?>
        <span><?= count($products) ?> <?= count($products) === 1 ? 'product' : 'products' ?></span>
      </div>
    </div>
  <?php endif; ?>
  <div class="page-heading-row">
    <h1 class="page-title">
      <?php if ($q !== ''): ?>
        Results for "<?= e($q) ?>"
      <?php else: ?>
        <?= e($category['name'] ?? 'All Products') ?>
      <?php endif; ?>
    </h1>
    <form class="sort-form" method="get">
      <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
      <?php if ($categoryId): ?><input type="hidden" name="category" value="<?= (int)$categoryId ?>"><?php endif; ?>
      <label for="sort">Sort by</label>
      <select name="sort" id="sort" onchange="this.form.submit()">
        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
        <option value="relevance" <?= $sort === 'relevance' ? 'selected' : '' ?>>Relevance</option>
        <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
        <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name</option>
      </select>
    </form>
  </div>

  <?php if (empty($products)): ?>
    <p class="empty-state">
      <?= $q !== '' ? 'No products matched your search. Try a different term.' : 'No products found in this category yet.' ?>
    </p>
  <?php else: ?>
    <div class="product-grid">
      <?php foreach ($products as $p): include __DIR__ . '/includes/product_card.php'; endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
