<?php
chdir(__DIR__ . '/..');
require 'includes/bootstrap.php';
$rows = $db->query("SELECT category_id, name, slug, description, image_path, is_active FROM categories WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll();
foreach ($rows as $r) {
    echo $r['category_id'] . ' | ' . $r['name'] . ' | img: ' . ($r['image_path'] ?: '(empty)')
        . ' | onDisk: ' . (!empty($r['image_path']) && file_exists('uploads/' . $r['image_path']) ? 'yes' : 'no') . PHP_EOL;
}
echo 'Total active: ' . count($rows) . PHP_EOL;
