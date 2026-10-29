<?php
/**
 * KATALE — Catalogue queries.
 *
 * Popularity is computed from real activity: completed order quantity is
 * weighted heavily, product views are weighted lightly. Nothing here is
 * hard-coded — if there is no activity yet, the popularity score is 0 and
 * products simply fall back to newest-first ordering.
 */

function get_popular_products(PDO $db, int $limit = 8): array
{
    $sql = "SELECT p.*,
                COALESCE(sold.total_qty, 0)   AS total_sold,
                COALESCE(views.total_views,0) AS total_views,
                (COALESCE(sold.total_qty, 0) * 5 + COALESCE(views.total_views, 0) * 1) AS popularity_score
            FROM products p
            LEFT JOIN (
                SELECT oi.product_id, SUM(oi.quantity) AS total_qty
                FROM order_items oi
                JOIN orders o ON o.order_id = oi.order_id
                WHERE o.payment_status = 'paid'
                GROUP BY oi.product_id
            ) sold ON sold.product_id = p.product_id
            LEFT JOIN (
                SELECT product_id, COUNT(*) AS total_views
                FROM product_views
                GROUP BY product_id
            ) views ON views.product_id = p.product_id
            WHERE p.status = 'active' AND p.is_available = 1
            ORDER BY popularity_score DESC, p.created_at DESC
            LIMIT :lim";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return array_map(fn($p) => apply_pricing($db, $p), $stmt->fetchAll());
}

function get_discounted_products(PDO $db, int $limit = 8): array
{
    $sql = "SELECT p.*, MIN(d.ends_at) AS soonest_discount_end FROM products p
            JOIN product_discounts d ON d.product_id = p.product_id
            WHERE p.status = 'active' AND p.is_available = 1
              AND d.is_active = 1 AND d.starts_at <= NOW() AND d.ends_at >= NOW()
            GROUP BY p.product_id
            ORDER BY soonest_discount_end ASC
            LIMIT :lim";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return array_map(fn($p) => apply_pricing($db, $p), $stmt->fetchAll());
}

function get_new_arrivals(PDO $db, int $limit = 8): array
{
    $stmt = $db->prepare(
        "SELECT * FROM products WHERE status = 'active' AND is_available = 1
         ORDER BY created_at DESC LIMIT :lim"
    );
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return array_map(fn($p) => apply_pricing($db, $p), $stmt->fetchAll());
}

function get_active_baskets(PDO $db, int $limit = 6): array
{
    $stmt = $db->prepare("SELECT * FROM baskets WHERE is_active = 1 ORDER BY created_at DESC LIMIT :lim");
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Full-text + partial LIKE fallback search across name/description/keywords.
 * Partial matches (e.g. "tom" -> "Tomatoes") are supported via LIKE.
 */
function search_products(PDO $db, string $term, ?int $categoryId = null, string $sort = 'relevance'): array
{
    $like = '%' . $term . '%';
    $where = "p.status = 'active'
              AND (p.name LIKE :like OR p.description LIKE :like2
                   OR p.search_keywords LIKE :like3 OR c.name LIKE :like4)";
    $params = ['like' => $like, 'like2' => $like, 'like3' => $like, 'like4' => $like];

    if ($categoryId) {
        $where .= " AND p.category_id = :cat";
        $params['cat'] = $categoryId;
    }

    $orderBy = match ($sort) {
        'price_asc'  => 'p.regular_price ASC',
        'price_desc' => 'p.regular_price DESC',
        'newest'     => 'p.created_at DESC',
        default      => '(p.name LIKE :like5) DESC, p.view_count DESC',
    };
    if ($sort === 'relevance') {
        $params['like5'] = $term . '%';
    }

    $sql = "SELECT p.* FROM products p
            JOIN categories c ON c.category_id = p.category_id
            WHERE $where
            ORDER BY $orderBy";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return array_map(fn($p) => apply_pricing($db, $p), $stmt->fetchAll());
}

function browse_products(PDO $db, ?int $categoryId = null, string $sort = 'newest'): array
{
    $where = "p.status = 'active'";
    $params = [];
    if ($categoryId) {
        $where .= " AND p.category_id = :cat";
        $params['cat'] = $categoryId;
    }
    $orderBy = match ($sort) {
        'price_asc'  => 'p.regular_price ASC',
        'price_desc' => 'p.regular_price DESC',
        'name'       => 'p.name ASC',
        default      => 'p.created_at DESC',
    };
    $sql = "SELECT p.* FROM products p WHERE $where ORDER BY $orderBy";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return array_map(fn($p) => apply_pricing($db, $p), $stmt->fetchAll());
}

function log_search(PDO $db, string $term, int $resultsCount): void
{
    $stmt = $db->prepare(
        "INSERT INTO searches (search_term, user_id, session_id, results_count)
         VALUES (:term, :uid, :sid, :n)"
    );
    $stmt->execute([
        'term' => $term,
        'uid'  => current_user()['user_id'] ?? null,
        'sid'  => session_id_key(),
        'n'    => $resultsCount,
    ]);
}

function log_product_view(PDO $db, int $productId): void
{
    $stmt = $db->prepare(
        "INSERT INTO product_views (product_id, user_id, session_id) VALUES (:pid, :uid, :sid)"
    );
    $stmt->execute([
        'pid' => $productId,
        'uid' => current_user()['user_id'] ?? null,
        'sid' => session_id_key(),
    ]);
    $db->prepare("UPDATE products SET view_count = view_count + 1 WHERE product_id = :pid")
       ->execute(['pid' => $productId]);
}
