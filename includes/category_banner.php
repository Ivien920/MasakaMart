<?php
/**
 * Unified hero/category slider.
 *
 * Expects `$bannerCats` — an array of rows from the `categories` table
 * (category_id, name, slug, description, image_path). Renders one
 * `.hero-slide.hero-lead-slide` per category, reusing the same banner
 * structure, gradient and typography as the static main hero slide in
 * index.php. Nothing is written to the DB.
 *
 * Renders nothing when there are no eligible categories.
 */
if (empty($bannerCats) || !is_array($bannerCats)) {
    return;
}

$slides = [];
foreach ($bannerCats as $cat) {
    $title = trim((string)($cat['name'] ?? ''));
    if ($title === '') {
        continue;
    }
    $desc = trim((string)($cat['description'] ?? ''));
    if ($desc === '') {
        $desc = 'Explore fresh picks and great prices from our ' . mb_strtolower($title) . ' range.';
    }
    $img = '';
    if (!empty($cat['image_path'])) {
        // Category image lives in uploads/products/ alongside product images.
        $img = UPLOAD_URL . e($cat['image_path']);
    } else {
        $stmt = $db->prepare('SELECT image_path FROM products WHERE category_id = :cid AND image_path IS NOT NULL AND image_path <> \'\' ORDER BY product_id ASC LIMIT 1');
        $stmt->execute(['cid' => (int)$cat['category_id']]);
        $row = $stmt->fetch();
        if (!empty($row['image_path'])) {
            $img = UPLOAD_URL . e($row['image_path']);
        }
    }
    $slides[] = [
        'title'  => $title,
        'desc'   => $desc,
        'img'    => $img !== '' ? $img : APP_URL . '/assets/images/placeholder.svg',
        'link'   => APP_URL . '/products.php?category=' . (int)$cat['category_id'],
    ];
}
unset($cat);

if (!$slides) {
    return;
}

// Static index counter, incremented per category slide after slide 0 (hero).
$baseIndex = 1;
?>
<?php foreach ($slides as $s): ?>
  <article class="hero-slide hero-lead-slide" data-slide-index="<?= $baseIndex ?>"<?= $baseIndex === 1 ? '' : ' aria-hidden="true"' ?>>
    <div class="hero-lead-media">
      <img class="hero-photo" src="<?= $s['img'] ?>" alt="<?= $s['title'] ?> category" width="736" height="981" loading="lazy">
    </div>
    <div class="hero-lead-copy">
      <span class="hero-lead-badge">Explore Category</span>
      <h2><?= e($s['title']) ?></h2>
      <p class="hero-lead-sub"><?= e($s['desc']) ?></p>
      <a href="<?= $s['link'] ?>" class="hero-lead-cta" tabindex="<?= $baseIndex === 1 ? '0' : '-1' ?>">
        <span>Shop Now</span><b aria-hidden="true">&rarr;</b>
      </a>
    </div>
  </article>
<?php $baseIndex++; ?>
<?php endforeach; ?>
