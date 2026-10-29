<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

$urls = [
    ['loc' => katale_sitemap_url(''), 'lastmod' => null],
    ['loc' => katale_sitemap_url('products.php'), 'lastmod' => null],
    ['loc' => katale_sitemap_url('policies/delivery.php'), 'lastmod' => null],
    ['loc' => katale_sitemap_url('policies/returns.php'), 'lastmod' => null],
    ['loc' => katale_sitemap_url('policies/privacy.php'), 'lastmod' => null],
    ['loc' => katale_sitemap_url('policies/terms.php'), 'lastmod' => null],
];

try {
    $db = Database::conn();
    $categories = $db->query("SELECT category_id, updated_at FROM categories WHERE is_active = 1 ORDER BY category_id")->fetchAll();
    foreach ($categories as $category) {
        $urls[] = ['loc' => katale_sitemap_url('products.php?category=' . (int)$category['category_id']), 'lastmod' => $category['updated_at'] ?? null];
    }
    $products = $db->query("SELECT slug, updated_at FROM products WHERE status = 'active' AND is_available = 1 ORDER BY product_id")->fetchAll();
    foreach ($products as $product) {
        $urls[] = ['loc' => katale_sitemap_url('product.php?slug=' . rawurlencode((string)$product['slug'])), 'lastmod' => $product['updated_at'] ?? null];
    }
} catch (Throwable $e) {
    error_log('[katale] sitemap generation failed: ' . $e->getMessage());
}

function katale_sitemap_url(string $path): string
{
    return rtrim(SEO_SITE_URL, '/') . ($path === '' ? '/' : '/' . ltrim($path, '/'));
}

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
foreach ($urls as $url) {
    echo '<url><loc>' . htmlspecialchars($url['loc'], ENT_XML1, 'UTF-8') . '</loc>';
    if (!empty($url['lastmod']) && strtotime((string)$url['lastmod']) !== false) {
        echo '<lastmod>' . date('c', strtotime((string)$url['lastmod'])) . '</lastmod>';
    }
    echo '</url>';
}
echo '</urlset>';