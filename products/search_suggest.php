<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

$term = trim($_GET['q'] ?? '');
if (mb_strlen($term) < 2) {
    echo json_encode([]);
    exit;
}

$stmt = $db->prepare(
    "SELECT DISTINCT name, slug, view_count FROM products
     WHERE status = 'active' AND name LIKE :like
     ORDER BY view_count DESC LIMIT 6"
);
$stmt->execute(['like' => $term . '%']);
$results = $stmt->fetchAll();

// Fall back to a broader partial match if the prefix search found nothing.
if (empty($results)) {
    $stmt = $db->prepare(
        "SELECT DISTINCT name, slug, view_count FROM products
         WHERE status = 'active' AND name LIKE :like
         ORDER BY view_count DESC LIMIT 6"
    );
    $stmt->execute(['like' => '%' . $term . '%']);
    $results = $stmt->fetchAll();
}

echo json_encode($results);
