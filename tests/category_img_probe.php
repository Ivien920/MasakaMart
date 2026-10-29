<?php
chdir(__DIR__ . '/..');
require 'includes/bootstrap.php';
$rows = $db->query("SELECT c.category_id, c.name, (SELECT p.image_path FROM products p WHERE p.category_id = c.category_id AND p.image_path IS NOT NULL AND p.image_path <> '' LIMIT 1) AS first_product_image FROM categories c WHERE c.is_active = 1 ORDER BY c.sort_order, c.name")->fetchAll();
foreach ($rows as $r) {
    $img = $r['first_product_image'];
    echo $r['category_id'] . ' | ' . $r['name'] . ' | productImg: ' . ($img ?: '(none)')
        . ' | onDisk: ' . (!empty($img) && file_exists('uploads/' . $img) ? 'yes' : 'no') . PHP_EOL;
}
echo 'Total: ' . count($rows) . PHP_EOL;
