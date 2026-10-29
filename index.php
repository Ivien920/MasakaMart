<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/catalogue.php';

$pageTitle = 'MasakaMart — Fresh Food. Better Living.';
$metaDescription = 'Shop fresh food and everyday market essentials online with MasakaMart in Masaka, Uganda.';
$categories = $db->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll();
$popular = get_popular_products($db, 8);
$deals = get_discounted_products($db, 8);
$newArrivals = get_new_arrivals($db, 8);
$GLOBALS['seoItems'] = $popular;
$GLOBALS['seoItemListName'] = 'Popular products';
$baskets = get_active_baskets($db, 6);
$heroSlides = $db->query(
  "SELECT * FROM hero_slides WHERE is_active = 1 ORDER BY sort_order ASC, slide_id ASC LIMIT 4"
)->fetchAll();
$phoneProducts = array_slice($popular, 0, 3);
$phoneCategories = array_slice($categories, 0, 4);

require_once __DIR__ . '/includes/header.php';
?>
<section class="hero hero-premium hero-campaign">
  <div class="hero-campaign-pattern" aria-hidden="true"></div>
  <div class="container hero-campaign-inner">
    <div class="hero-slider" aria-live="polite">
      <article class="hero-slide hero-lead-slide is-active" data-slide-index="0">
        <div class="hero-lead-media">
          <img class="hero-photo" src="<?= APP_URL ?>/includes/hero.jpg" alt="A market stall piled with fresh fruit and vegetables" width="736" height="981" fetchpriority="high">
        </div>
        <div class="hero-lead-copy">
          <span class="hero-lead-badge">Fresh & Healthy</span>
          <h1>Good Food, <span>Better Living.</span></h1>
          <p class="hero-lead-sub">Fresh produce, great prices, delivered to your door.</p>
          <a href="<?= APP_URL ?>/products.php" class="hero-lead-cta">
            <span>Shop Now</span><b aria-hidden="true">&rarr;</b>
          </a>
        </div>
      </article>
      <?php $bannerCats = $categories; include __DIR__ . '/includes/category_banner.php'; ?>
      <?php foreach ($heroSlides as $idx => $slide): ?>
        <article class="hero-slide hero-admin-slide" data-slide-index="<?= (int)$idx + 1 + count($categories) ?>">
          <?php if (($slide['media_type'] ?? 'image') === 'video' && !empty($slide['media_path'])): ?>
            <video class="hero-media" src="<?= UPLOAD_URL . e($slide['media_path']) ?>" autoplay muted loop playsinline></video>
          <?php elseif (!empty($slide['media_path'])): ?>
            <img class="hero-media" src="<?= UPLOAD_URL . e($slide['media_path']) ?>" alt="<?= e($slide['title']) ?>" loading="lazy">
          <?php else: ?>
            <div class="hero-slide-empty"><div class="hero-slide-copy"><span class="hero-slide-kicker"><?= e($slide['eyebrow'] ?: 'Fresh picks') ?></span><h2><?= e($slide['title'] ?: 'Welcome to MasakaMart') ?></h2><p><?= e($slide['subtitle'] ?: 'Fresh groceries delivered fast across Masaka & Kampala.') ?></p></div></div>
          <?php endif; ?>
          <?php if (!empty($slide['title']) || !empty($slide['subtitle']) || !empty($slide['button_text'])): ?>
            <div class="hero-slide-overlay">
              <?php if (!empty($slide['eyebrow'])): ?><span class="hero-slide-kicker"><?= e($slide['eyebrow']) ?></span><?php endif; ?>
              <?php if (!empty($slide['title'])): ?><h2><?= e($slide['title']) ?></h2><?php endif; ?>
              <?php if (!empty($slide['subtitle'])): ?><p><?= e($slide['subtitle']) ?></p><?php endif; ?>
              <?php if (!empty($slide['button_text']) && !empty($slide['button_link'])): ?><a href="<?= e($slide['button_link']) ?>" class="btn btn-cta btn-slide"><?= e($slide['button_text']) ?></a><?php endif; ?>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?php $slideCount = count($heroSlides) + count($categories) + 1; ?>
      <?php if ($slideCount > 1): ?>
      <button type="button" class="hero-slider-control hero-slider-prev" aria-label="Previous slide">‹</button>
      <button type="button" class="hero-slider-control hero-slider-next" aria-label="Next slide">›</button>
      <div class="hero-slider-dots" aria-label="Hero slider indicators">
        <?php for ($idx = 0; $idx < $slideCount; $idx++): ?><button type="button" class="hero-dot <?= $idx === 0 ? 'is-active' : '' ?>" data-slide-index="<?= $idx ?>" aria-label="Show slide <?= $idx + 1 ?>"></button><?php endfor; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<script>
(function () {
 document.addEventListener('DOMContentLoaded', function () {
  const slider = document.querySelector('.hero.hero-campaign .hero-slider');
  if (!slider) return;
  const slides = Array.from(slider.querySelectorAll('.hero-slide'));
  if (!slides.length) return;
  const dots = Array.from(slider.querySelectorAll('.hero-dot'));
  const prevBtn = slider.querySelector('.hero-slider-prev');
  const nextBtn = slider.querySelector('.hero-slider-next');
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const INTERVAL = 5000;

  let activeIndex = 0, timer = null, hovering = false, focused = false;

  function showSlide(index) {
    activeIndex = (index + slides.length) % slides.length;
    slides.forEach((slide, i) => {
      const isActive = i === activeIndex;
      slide.classList.toggle('is-active', isActive);
      if (isActive) {
        slide.removeAttribute('aria-hidden');
      } else {
        slide.setAttribute('aria-hidden', 'true');
      }
      slide.querySelectorAll('a, button, video').forEach(el => {
        if (el.tagName === 'VIDEO') { isActive ? el.play && el.play() : el.pause && el.pause(); }
        else el.tabIndex = isActive ? 0 : -1;
      });
    });
    dots.forEach((dot, i) => {
      dot.classList.toggle('is-active', i === activeIndex);
      dot.setAttribute('aria-selected', i === activeIndex ? 'true' : 'false');
    });
  }

  function startTimer() {
    stopTimer();
    if (reduceMotion || hovering || focused) return;
    timer = setInterval(() => showSlide(activeIndex + 1), INTERVAL);
  }
  function stopTimer() {
    if (timer) { clearInterval(timer); timer = null; }
  }

  prevBtn && prevBtn.addEventListener('click', () => { showSlide(activeIndex - 1); startTimer(); });
  nextBtn && nextBtn.addEventListener('click', () => { showSlide(activeIndex + 1); startTimer(); });
  dots.forEach(dot => dot.addEventListener('click', () => {
    showSlide(Number(dot.dataset.slideIndex || 0));
    startTimer();
  }));

  slider.addEventListener('mouseenter', () => { hovering = true; stopTimer(); });
  slider.addEventListener('mouseleave', () => { hovering = false; startTimer(); });
  slider.addEventListener('focusin', () => { focused = true; stopTimer(); });
  slider.addEventListener('focusout', (e) => {
    if (!slider.contains(e.relatedTarget)) { focused = false; startTimer(); }
  });
  slider.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowLeft') { showSlide(activeIndex - 1); startTimer(); }
    if (e.key === 'ArrowRight') { showSlide(activeIndex + 1); startTimer(); }
  });
  document.addEventListener('visibilitychange', () => {
    document.hidden ? stopTimer() : startTimer();
  });

  showSlide(0);
  startTimer();
 });
})();
</script>

<section class="container section">
  <div class="section-header">
    <h2 class="section-title">Shop by Category</h2>
    <a class="section-link" href="<?= APP_URL ?>/products.php">See all</a>
  </div>
  <?php if (empty($categories)): ?>
    <p class="empty-state">No categories yet — the admin is setting up the catalogue. Check back soon.</p>
  <?php else: ?>
    <div class="category-grid category-scroll-row">
      <?php foreach ($categories as $cat): ?>
        <a class="category-tile" href="<?= APP_URL ?>/products.php?category=<?= (int)$cat['category_id'] ?>">
          <span class="category-tile-image">
          <?php if (!empty($cat['image_path'])): ?>
            <img src="<?= UPLOAD_URL . e($cat['image_path']) ?>" alt="<?= e($cat['name']) ?> category" loading="eager" width="90" height="90">
            <span class="category-tile-fallback" aria-hidden="true" hidden><?= e(mb_substr($cat['name'], 0, 1)) ?></span>
          <?php else: ?>
            <span class="category-tile-fallback" aria-hidden="true"><?= e(mb_substr($cat['name'], 0, 1)) ?></span>
          <?php endif; ?>
          </span>
          <span class="category-tile-name"><?= e($cat['name']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php /* Category promo banners are now rendered inside the unified hero slider above. */ ?>
</section>

<section class="container section">
  <div class="section-header">
    <h2 class="section-title">Popular Products</h2>
    <a class="section-link" href="<?= APP_URL ?>/products.php">View all</a>
  </div>
  <?php if (empty($popular)): ?>
    <p class="empty-state">No popular products yet — check back once orders start coming in.</p>
  <?php else: ?>
    <div class="product-grid">
      <?php foreach ($popular as $p): include __DIR__ . '/includes/product_card.php'; endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="container section">
  <div class="section-header">
    <h2 class="section-title">Today's Deals</h2>
    <a class="section-link" href="<?= APP_URL ?>/products.php?sort=price_asc">Browse deals</a>
  </div>
  <?php if (empty($deals)): ?>
    <p class="empty-state">No active discounts right now.</p>
  <?php else: ?>
    <div class="product-grid">
      <?php foreach ($deals as $p): include __DIR__ . '/includes/product_card.php'; endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="container section">
  <div class="section-header">
    <h2 class="section-title">Recommended For You</h2>
    <a class="section-link" href="<?= APP_URL ?>/products.php">Shop more</a>
  </div>
  <?php if (empty($newArrivals)): ?>
    <p class="empty-state">No products yet.</p>
  <?php else: ?>
    <div class="product-grid">
      <?php foreach ($newArrivals as $p): include __DIR__ . '/includes/product_card.php'; endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="container section benefits-section">
  <div class="benefits-grid">
    <article class="benefit-card">
      <span class="benefit-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 4 9v12h16V9l-8-6Z"/><path d="M9 21v-6h6v6"/></svg>
      </span>
      <h3>Fresh &amp; Natural</h3>
      <p>Quality produce sourced for your table.</p>
    </article>
    <article class="benefit-card">
      <span class="benefit-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21s7-4.5 7-11a7 7 0 1 0-14 0c0 6.5 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg>
      </span>
      <h3>Local Source</h3>
      <p>Trusted sellers across Masaka &amp; Kampala.</p>
    </article>
    <article class="benefit-card">
      <span class="benefit-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7h13l3 5v5H3V7Z"/><circle cx="7.5" cy="18.5" r="1.5"/><circle cx="16.5" cy="18.5" r="1.5"/></svg>
      </span>
      <h3>Fast Delivery</h3>
      <p>Order online and get it delivered quickly.</p>
    </article>
  </div>
</section>

<section class="container section" id="baskets">
  <h2 class="section-title">Ready-Made Shopping Baskets</h2>
  <?php if (empty($baskets)): ?>
    <p class="empty-state">No baskets have been set up yet.</p>
  <?php else: ?>
    <div class="basket-grid">
      <?php foreach ($baskets as $b): ?>
        <a class="basket-card" href="<?= APP_URL ?>/products/basket.php?slug=<?= e($b['slug']) ?>">
          <h3><?= e($b['name']) ?></h3>
          <p><?= e($b['description'] ?? '') ?></p>
          <span class="basket-cta">View Basket →</span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
