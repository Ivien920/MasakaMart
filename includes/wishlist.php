<?php
/**
 * KATALE — Wishlist helpers (existing wishlists / wishlist_items tables).
 */

if (!function_exists('get_user_wishlist_product_ids')) {
    function get_user_wishlist_product_ids(PDO $db, int $userId): array
    {
        $stmt = $db->prepare("
            SELECT wi.product_id
            FROM wishlist_items wi
            JOIN wishlists w ON w.wishlist_id = wi.wishlist_id
            WHERE w.user_id = :uid
        ");
        $stmt->execute(['uid' => $userId]);
        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $ids[] = (int)$row['product_id'];
        }

        return $ids;
    }
}

if (!function_exists('ensure_user_wishlist_id')) {
    function ensure_user_wishlist_id(PDO $db, int $userId): int
    {
        $stmt = $db->prepare('SELECT wishlist_id FROM wishlists WHERE user_id = :uid LIMIT 1');
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch();
        if ($row) {
            return (int)$row['wishlist_id'];
        }

        $db->prepare('INSERT INTO wishlists (user_id) VALUES (:uid)')->execute(['uid' => $userId]);

        return (int)$db->lastInsertId();
    }
}

if (!function_exists('toggle_user_wishlist_product')) {
    /**
     * @return array{wishlisted:bool,wishlist_count:int}
     */
    function toggle_user_wishlist_product(PDO $db, int $userId, int $productId): array
    {
        if ($productId <= 0) {
            throw new InvalidArgumentException('Invalid product.');
        }

        $wishlistId = ensure_user_wishlist_id($db, $userId);
        $check = $db->prepare('SELECT wishlist_item_id FROM wishlist_items WHERE wishlist_id = :wid AND product_id = :pid LIMIT 1');
        $check->execute(['wid' => $wishlistId, 'pid' => $productId]);
        $existing = $check->fetch();

        if ($existing) {
            $db->prepare('DELETE FROM wishlist_items WHERE wishlist_id = :wid AND product_id = :pid')
                ->execute(['wid' => $wishlistId, 'pid' => $productId]);
            $wishlisted = false;
        } else {
            $db->prepare('INSERT INTO wishlist_items (wishlist_id, product_id) VALUES (:wid, :pid)')
                ->execute(['wid' => $wishlistId, 'pid' => $productId]);
            $wishlisted = true;
        }

        $countStmt = $db->prepare("
            SELECT COUNT(*) AS c FROM wishlist_items wi
            JOIN wishlists w ON w.wishlist_id = wi.wishlist_id
            WHERE w.user_id = :uid
        ");
        $countStmt->execute(['uid' => $userId]);

        return [
            'wishlisted' => $wishlisted,
            'wishlist_count' => (int)($countStmt->fetch()['c'] ?? 0),
        ];
    }
}
