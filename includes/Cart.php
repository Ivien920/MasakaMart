<?php
/**
 * KATALE — Cart
 *
 * Guests get a cart keyed by their session id. On login, the guest cart is
 * merged into the customer's persistent cart so nothing is lost.
 * All prices/totals here are recalculated server-side from the database —
 * the browser is never trusted with money math.
 */

require_once __DIR__ . '/pricing.php';

class Cart
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Get (or create) the cart row for the current guest/user. */
    public function getOrCreateCartId(): int
    {
        $userId = current_user()['user_id'] ?? null;
        $sessionId = session_id_key();

        if ($userId) {
            $stmt = $this->db->prepare("SELECT cart_id FROM carts WHERE user_id = :uid LIMIT 1");
            $stmt->execute(['uid' => $userId]);
            $cart = $stmt->fetch();
            if ($cart) {
                return (int)$cart['cart_id'];
            }
            $stmt = $this->db->prepare("INSERT INTO carts (user_id, session_id) VALUES (:uid, :sid)");
            $stmt->execute(['uid' => $userId, 'sid' => $sessionId]);
            return (int)$this->db->lastInsertId();
        }

        $stmt = $this->db->prepare("SELECT cart_id FROM carts WHERE session_id = :sid AND user_id IS NULL LIMIT 1");
        $stmt->execute(['sid' => $sessionId]);
        $cart = $stmt->fetch();
        if ($cart) {
            return (int)$cart['cart_id'];
        }
        $stmt = $this->db->prepare("INSERT INTO carts (session_id) VALUES (:sid)");
        $stmt->execute(['sid' => $sessionId]);
        return (int)$this->db->lastInsertId();
    }

    /** Merge a guest cart into the logged-in user's cart. Call right after login. */
    public function mergeGuestCartIntoUser(int $userId): void
    {
        $sessionId = session_id_key();

        $stmt = $this->db->prepare("SELECT cart_id FROM carts WHERE session_id = :sid AND user_id IS NULL LIMIT 1");
        $stmt->execute(['sid' => $sessionId]);
        $guestCart = $stmt->fetch();
        if (!$guestCart) {
            return;
        }
        $guestCartId = (int)$guestCart['cart_id'];

        $stmt = $this->db->prepare("SELECT cart_id FROM carts WHERE user_id = :uid LIMIT 1");
        $stmt->execute(['uid' => $userId]);
        $userCart = $stmt->fetch();

        if (!$userCart) {
            // Simplest merge: just attach the guest cart to this user.
            $stmt = $this->db->prepare("UPDATE carts SET user_id = :uid WHERE cart_id = :cid");
            $stmt->execute(['uid' => $userId, 'cid' => $guestCartId]);
            return;
        }

        $userCartId = (int)$userCart['cart_id'];
        $items = $this->db->prepare("SELECT product_id, quantity FROM cart_items WHERE cart_id = :cid");
        $items->execute(['cid' => $guestCartId]);
        foreach ($items->fetchAll() as $item) {
            $this->addItem($userCartId, (int)$item['product_id'], (float)$item['quantity']);
        }
        $this->db->prepare("DELETE FROM carts WHERE cart_id = :cid")->execute(['cid' => $guestCartId]);
    }

    public function addItem(int $cartId, int $productId, float $quantity): void
    {
        if ($quantity < 1 || floor($quantity) !== $quantity) {
            throw new InvalidArgumentException('Quantity must be a whole number.');
        }
        $stmt = $this->db->prepare(
            "INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (:cid, :pid, :qty)
             ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)"
        );
        $stmt->execute(['cid' => $cartId, 'pid' => $productId, 'qty' => $quantity]);
    }

    public function setQuantity(int $cartId, int $productId, float $quantity): void
    {
        if ($quantity > 0 && floor($quantity) !== $quantity) {
            throw new InvalidArgumentException('Quantity must be a whole number.');
        }
        if ($quantity <= 0) {
            $this->removeItem($cartId, $productId);
            return;
        }
        $stmt = $this->db->prepare(
            "UPDATE cart_items SET quantity = :qty WHERE cart_id = :cid AND product_id = :pid"
        );
        $stmt->execute(['qty' => $quantity, 'cid' => $cartId, 'pid' => $productId]);
    }

    public function removeItem(int $cartId, int $productId): void
    {
        $stmt = $this->db->prepare("DELETE FROM cart_items WHERE cart_id = :cid AND product_id = :pid");
        $stmt->execute(['cid' => $cartId, 'pid' => $productId]);
    }

    public function clear(int $cartId): void
    {
        $this->db->prepare("DELETE FROM cart_items WHERE cart_id = :cid")->execute(['cid' => $cartId]);
    }

    /**
     * Returns the cart's line items with server-recalculated prices, plus
     * a subtotal. This is the ONLY place cart totals should be computed.
     */
    public function getItemsWithPricing(int $cartId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ci.product_id, ci.quantity, p.name, p.slug, p.unit, p.image_path,
                    p.regular_price, p.stock_quantity, p.is_available, p.status
             FROM cart_items ci
             JOIN products p ON p.product_id = ci.product_id
             WHERE ci.cart_id = :cid
             ORDER BY ci.added_at DESC"
        );
        $stmt->execute(['cid' => $cartId]);
        $rows = $stmt->fetchAll();

        $items = [];
        $subtotal = 0.0;
        foreach ($rows as $row) {
            $row = apply_pricing($this->db, $row);
            $row['line_total'] = round($row['effective_price'] * (float)$row['quantity'], 2);
            $subtotal += $row['line_total'];
            $items[] = $row;
        }

        return ['items' => $items, 'subtotal' => round($subtotal, 2)];
    }

    public function countItems(int $cartId): int
    {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(quantity),0) AS n FROM cart_items WHERE cart_id = :cid");
        $stmt->execute(['cid' => $cartId]);
        return (int)$stmt->fetch()['n'];
    }
}
