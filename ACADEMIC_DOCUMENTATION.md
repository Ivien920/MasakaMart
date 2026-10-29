# KATALE — Academic Documentation Support

Supporting notes for the Electronic Commerce coursework write-up.

## Business Concept

KATALE ("market" in Luganda) is an online food and local-market e-tailer
serving Kampala and surrounding areas. It sources fresh produce, grains,
meat/poultry/fish, and everyday market essentials, and sells them directly
to consumers online — a curated, single-seller model rather than a
multi-vendor marketplace.

## E-commerce Type

Business-to-Consumer (B2C) e-tailing.

## Revenue Model

Direct product margin: KATALE purchases/sources stock from local markets
and sells at a markup, with delivery fees as a secondary revenue line for
home-delivery orders (pickup is offered free to encourage lower-cost
fulfilment).

## Value Proposition

- Convenience: order fresh market goods without visiting the market
  in person or navigating traffic/parking.
- Trust: transparent, consistent pricing and clearly labelled discounts,
  compared to price variability common in physical markets.
- Reliability: real-time stock visibility avoids wasted trips for
  out-of-stock items.

## Functional Requirements (implemented)

Guest browsing/search/cart, login-gated checkout with cart preservation,
category and product catalogue management, discount engine, stock
management with server-side validation, delivery zone management, simulated
payment, order lifecycle tracking, verified-purchase reviews, wishlist,
ready-made baskets, customer support tickets, and a full admin back office
with dashboard analytics.

## Non-Functional Requirements

- **Security**: password hashing (`password_hash`/`password_verify`),
  PDO prepared statements throughout, CSRF tokens on all state-changing
  forms, output escaping (`e()` helper) to prevent XSS, role-based access
  control for the admin area, and server-side re-validation of stock and
  prices at every step (cart, checkout, and order placement).
- **Performance**: paginated admin product listings, indexed foreign keys
  and a full-text index for search, lazy-loaded images.
- **Usability**: mobile-first responsive layout, empty states instead of
  broken pages when data is missing, clear stock/discount messaging.
- **Maintainability**: one class per file, MVC-inspired separation
  (includes/ for shared logic and templates, plain PHP entry-point files
  per route), commented business logic (see `includes/pricing.php` and
  `checkout/checkout_process.php` in particular).

## Hosting Rationale

Plain PHP/MySQL was chosen (per the assignment's own constraint) because it
runs on virtually any low-cost shared hosting plan without needing a
Node.js runtime, Composer dependency resolution, or containerization —
appropriate for a student project intended to be deployable cheaply and
explainable line-by-line.

## Security Explanation

See `README.md` §8 "Security checks" for the concrete test list, and the
Non-Functional Requirements section above for the mechanisms themselves.
The most important business-logic security property is that **the browser
is never trusted with money math**: cart totals, discount amounts, and
delivery fees are recalculated from the database on every add-to-cart
action and again at final checkout submission.

## International Considerations

The currency (`APP_CURRENCY`), timezone, and delivery-zone model are all
defined in one place (`config/config.php` and the `delivery_zones` table),
so supporting additional currencies, countries, or zones later is a
matter of data and configuration, not a redesign. Uganda is the initial
market.

## Ethical Considerations

Pricing is transparent (regular price and discount always shown together,
never hidden), discounts cannot make a price negative, delivery fees are
disclosed before checkout, and the returns/refund policy is stated plainly
rather than buried. No fake reviews, fake statistics, or fake urgency
messaging are generated anywhere in the system — all numbers shown (sales,
popularity, ratings) are computed from real database activity.

## Sustainability Considerations

Digital order confirmations and support tickets replace paper receipts and
in-person queries; delivery is zoned to support more efficient batching of
routes as order volume grows; and the model connects existing local market
supply chains to online demand rather than creating new logistics
infrastructure from scratch.
