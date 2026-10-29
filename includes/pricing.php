<?php
/**
 * KATALE — Pricing engine.
 *
 * The regular_price on a product is never overwritten. An active discount
 * (if any, within its start/end window) is applied on top to compute the
 * effective price the customer actually pays. This file is the single
 * source of truth for that calculation — used by the catalogue, product
 * page, cart, and checkout so prices can never drift apart.
 */

/**
 * Fetch the currently-active discount row for a product, or null.
 */
function get_active_discount(PDO $db, int $productId): ?array
{
    $stmt = $db->prepare(
        "SELECT * FROM product_discounts
         WHERE product_id = :pid
           AND is_active = 1
           AND starts_at <= NOW()
           AND ends_at >= NOW()
         ORDER BY discount_id DESC
         LIMIT 1"
    );
    $stmt->execute(['pid' => $productId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Given a regular price and an optional discount row, return the effective
 * price a customer pays. Never allowed to go below 0.
 */
function calculate_effective_price(float $regularPrice, ?array $discount): float
{
    if (!$discount) {
        return $regularPrice;
    }

    if ($discount['discount_type'] === 'percentage') {
        $effective = $regularPrice * (1 - ((float)$discount['discount_value'] / 100));
    } else { // fixed
        $effective = $regularPrice - (float)$discount['discount_value'];
    }

    return max(0.0, round($effective, 2));
}

/**
 * Convenience: attach `effective_price`, `discount_active`, and
 * `discount_percent_label` fields onto a product row fetched from the DB.
 */
function apply_pricing(PDO $db, array $product): array
{
    $discount = get_active_discount($db, (int)$product['product_id']);
    $product['effective_price'] = calculate_effective_price((float)$product['regular_price'], $discount);
    $product['discount_active'] = $discount !== null;
    $product['discount_row'] = $discount;

    if ($discount) {
        if ($discount['discount_type'] === 'percentage') {
            $product['discount_label'] = (int)$discount['discount_value'] . '% OFF';
        } else {
            $product['discount_label'] = money((float)$discount['discount_value']) . ' OFF';
        }
    }

    return $product;
}
