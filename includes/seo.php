<?php
/** Shared metadata, structured data, canonical URL and consent-aware analytics helpers. */

if (!function_exists('katale_seo_is_public')) {
    function katale_seo_is_public(): bool
    {
        $path = str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? ''));
        foreach (['/admin/', '/auth/', '/customer/', '/cart/', '/checkout/', '/orders/', '/notifications', '/support/'] as $privatePath) {
            if (str_contains($path, $privatePath)) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('katale_seo_url')) {
    function katale_seo_url(string $path = ''): string
    {
        $base = rtrim(SEO_SITE_URL, '/');
        return $base . ($path === '' ? '/' : '/' . ltrim($path, '/'));
    }
}

if (!function_exists('katale_seo_canonical')) {
    function katale_seo_canonical(): string
    {
        $script = basename((string)($_SERVER['PHP_SELF'] ?? 'index.php'));
        if ($script === 'product.php' && !empty($GLOBALS['seoProduct']['slug'])) {
            return katale_seo_url('product.php?slug=' . rawurlencode((string)$GLOBALS['seoProduct']['slug']));
        }
        if ($script === 'products.php' && !empty($GLOBALS['seoCategory']['category_id']) && empty($_GET['q'])) {
            return katale_seo_url('products.php?category=' . (int)$GLOBALS['seoCategory']['category_id']);
        }
        if ($script === 'index.php') {
            return katale_seo_url();
        }
        return katale_seo_url($script === 'products.php' ? 'products.php' : $script);
    }
}

if (!function_exists('katale_seo_description')) {
    function katale_seo_description(string $fallback = ''): string
    {
        $description = trim(strip_tags($fallback));
        if ($description === '') {
            $description = 'Shop fresh food and everyday market essentials online with MasakaMart in Masaka, Uganda.';
        }
        return mb_substr($description, 0, 160);
    }
}

if (!function_exists('katale_seo_item')) {
    function katale_seo_item(array $product, ?string $listName = null): array
    {
        $item = [
            'item_id' => (string)(int)$product['product_id'],
            'item_name' => (string)$product['name'],
            'price' => (float)($product['effective_price'] ?? $product['regular_price'] ?? 0),
            'currency' => APP_CURRENCY,
            'item_category' => (string)($product['category_name'] ?? ''),
        ];
        if ($listName !== null) {
            $item['item_list_name'] = $listName;
        }
        return array_filter($item, static fn($value) => $value !== '');
    }
}

if (!function_exists('katale_seo_cart_item')) {
    function katale_seo_cart_item(array $item): array
    {
        return [
            'item_id' => (string)(int)($item['product_id'] ?? 0),
            'item_name' => (string)($item['name'] ?? $item['product_name'] ?? ''),
            'price' => (float)($item['effective_price'] ?? $item['unit_price'] ?? 0),
            'quantity' => (float)($item['quantity'] ?? 1),
            'currency' => APP_CURRENCY,
        ];
    }
}

if (!function_exists('katale_render_seo_head')) {
    function katale_render_seo_head(): void
    {
        $title = (string)($GLOBALS['pageTitle'] ?? APP_NAME . ' | Online Market in Masaka, Uganda');
        $description = katale_seo_description((string)($GLOBALS['metaDescription'] ?? ''));
        $canonical = katale_seo_canonical();
        $hasVerifiedPurchase = !empty($GLOBALS['seoPurchase']);
        $indexable = katale_seo_is_public() && empty($_GET['q']) && !isset($_GET['sort']);
        $robots = $indexable ? 'index,follow' : 'noindex,follow';
        $jsonLd = [
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'Organization', '@id' => katale_seo_url('#organization'), 'name' => APP_NAME, 'url' => katale_seo_url()],
                ['@type' => 'WebSite', '@id' => katale_seo_url('#website'), 'name' => APP_NAME, 'url' => katale_seo_url(), 'publisher' => ['@id' => katale_seo_url('#organization')]],
            ],
        ];
        if (!empty($GLOBALS['seoProduct'])) {
            $product = $GLOBALS['seoProduct'];
            $offer = [
                '@type' => 'Offer', 'priceCurrency' => APP_CURRENCY,
                'price' => (float)($product['effective_price'] ?? $product['regular_price']),
                'url' => $canonical,
                'availability' => ((float)$product['stock_quantity'] > 0 && (int)$product['is_available']) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            ];
            $jsonLd['@graph'][] = ['@type' => 'Product', 'name' => $product['name'], 'description' => katale_seo_description($product['description'] ?? ''), 'url' => $canonical, 'sku' => (string)(int)$product['product_id'], 'offers' => $offer];
        }
        ?>
<meta name="robots" content="<?= e($robots) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<meta property="og:type" content="<?= !empty($GLOBALS['seoProduct']) ? 'product' : 'website' ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:site_name" content="<?= e(APP_NAME) ?>">
<?php if (SEARCH_CONSOLE_VERIFICATION !== ''): ?><meta name="google-site-verification" content="<?= e(SEARCH_CONSOLE_VERIFICATION) ?>">
<?php endif; ?><script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php if (GA4_MEASUREMENT_ID !== '' && GA4_ANALYTICS_CONSENT && (katale_seo_is_public() || $hasVerifiedPurchase)): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= e(GA4_MEASUREMENT_ID) ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', '<?= e(GA4_MEASUREMENT_ID) ?>', {send_page_view: false});
gtag('event', 'page_view');
window.KATALE_ANALYTICS_READY = true;
<?php if (!empty($GLOBALS['seoProduct'])): ?>gtag('event', 'view_item', {currency: '<?= e(APP_CURRENCY) ?>', value: <?= json_encode((float)($GLOBALS['seoProduct']['effective_price'] ?? $GLOBALS['seoProduct']['regular_price'])) ?>, items: [<?= json_encode(katale_seo_item($GLOBALS['seoProduct'])) ?>]});<?php endif; ?>
<?php if (!empty($GLOBALS['seoItems'])): ?>gtag('event', 'view_item_list', {item_list_name: '<?= e((string)($GLOBALS['seoItemListName'] ?? 'Product list')) ?>', items: <?= json_encode(array_values(array_map('katale_seo_item', $GLOBALS['seoItems']))) ?>});<?php endif; ?>
<?php if (!empty($GLOBALS['seoCartItems'])): ?>gtag('event', 'view_cart', {currency: '<?= e(APP_CURRENCY) ?>', value: <?= json_encode((float)($GLOBALS['seoCartValue'] ?? 0)) ?>, items: <?= json_encode(array_values(array_map('katale_seo_cart_item', $GLOBALS['seoCartItems']))) ?>});<?php endif; ?>
<?php if (!empty($GLOBALS['seoCheckoutItems'])): ?>gtag('event', 'begin_checkout', {currency: '<?= e(APP_CURRENCY) ?>', value: <?= json_encode((float)($GLOBALS['seoCheckoutValue'] ?? 0)) ?>, items: <?= json_encode(array_values(array_map('katale_seo_cart_item', $GLOBALS['seoCheckoutItems']))) ?>});<?php endif; ?>
<?php if (!empty($GLOBALS['seoPurchase'])): ?>
(function(){var p=<?= json_encode($GLOBALS['seoPurchase'], JSON_UNESCAPED_SLASHES) ?>;var key='katale_purchase_'+p.transaction_id;if(!localStorage.getItem(key)){gtag('event','purchase',p);localStorage.setItem(key,'1');}}());
<?php endif; ?>
</script>
<?php endif;
    }
}

if (!function_exists('katale_render_analytics_consent')) {
    function katale_render_analytics_consent(): void
    {
        // Analytics is enabled by default when GA4_ANALYTICS_CONSENT is true.
        // Set that environment value to false to disable all GA4 loading.
    }
}
