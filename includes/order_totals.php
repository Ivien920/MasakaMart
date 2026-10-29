<?php
/**
 * Single server-side order total calculation (cart lines + delivery fee).
 */

if (!function_exists('katale_build_order_totals')) {
    /**
     * @param array<int,array<string,mixed>> $items Rows from Cart::getItemsWithPricing()
     * @return array{items:array,items_subtotal:float,discount_total:float,delivery_fee:float,grand_total:float}
     */
    function katale_build_order_totals(array $items, float $deliveryFee): array
    {
        $itemsSubtotal = 0.0;
        $discountTotal = 0.0;
        $normalized = [];

        foreach ($items as $item) {
            $qty = (float)($item['quantity'] ?? 0);
            if ($qty <= 0 || floor($qty) !== $qty) {
                throw new InvalidArgumentException('Quantity must be a whole number.');
            }
            $regular = round((float)($item['regular_price'] ?? 0), 2);
            $effective = round((float)($item['effective_price'] ?? $regular), 2);
            if ($regular < 0 || $effective < 0) {
                throw new InvalidArgumentException('Invalid product price.');
            }
            $lineTotal = round($effective * $qty, 2);
            $itemsSubtotal += $lineTotal;
            if ($regular > $effective) {
                $discountTotal += round(($regular - $effective) * $qty, 2);
            }

            $normalized[] = array_merge($item, [
                'quantity' => $qty,
                'regular_price' => $regular,
                'effective_price' => $effective,
                'line_total' => $lineTotal,
            ]);
        }

        $itemsSubtotal = round($itemsSubtotal, 2);
        $discountTotal = round(max(0.0, $discountTotal), 2);
        $deliveryFee = round(max(0.0, $deliveryFee), 2);
        $grandTotal = round($itemsSubtotal + $deliveryFee, 2);

        return [
            'items' => $normalized,
            'items_subtotal' => $itemsSubtotal,
            'discount_total' => $discountTotal,
            'delivery_fee' => $deliveryFee,
            'grand_total' => $grandTotal,
        ];
    }
}

if (!function_exists('katale_refresh_cart_pricing')) {
    /** Re-load cart lines from DB immediately before committing an order. */
    function katale_refresh_cart_pricing(PDO $db, Cart $cart, int $cartId): array
    {
        return $cart->getItemsWithPricing($cartId);
    }
}
