# KATALE — "Your Local Market, Online."

An online food and local-market e-tailer built as an individual Electronic
Commerce coursework project (Uganda Martyrs University, BSc IT). KATALE is
an **e-tailer**, not a marketplace: all products, pricing, stock, and
policies are controlled by KATALE administration — there are no independent
vendor storefronts.

This document covers installation, hosting, admin/customer usage, and
testing. See also:
- `AI_DISCLOSURE.md` — required AI-assisted development disclosure
- `ACADEMIC_DOCUMENTATION.md` — business concept, requirements, and
  non-functional considerations for the coursework write-up
- `sql/katale_schema.sql` — full database schema (+ optional demo seed data)

---

## 1. Technology Stack

- PHP 8+ (no framework), PDO with prepared statements throughout
- MySQL / MariaDB
- HTML5, CSS3 (hand-written, no build step), vanilla JavaScript
- Chart.js (loaded from CDN) for the admin dashboard charts only

## 2. Requirements

- PHP 8.0 or newer with the `pdo_mysql`, `mbstring`, and `fileinfo`
  extensions enabled (all enabled by default in WAMP/XAMPP)
- MySQL 5.7+/MariaDB 10.3+ (needed for `CHECK` constraints and full-text
  search used by the reviews and product search features)
- A web server (Apache via WAMP/XAMPP, or any standard PHP host)

## 3. Local Installation (WAMP/XAMPP)

1. Copy the entire `katale/` folder into your web root:
   - WAMP: `C:\wamp64\www\katale`
   - XAMPP: `C:\xampp\htdocs\katale`
2. Start Apache and MySQL from your WAMP/XAMPP control panel.
3. Open phpMyAdmin and import `sql/katale_schema.sql`. This creates the
   `katale_db` database, all tables, and a small **optional** set of demo
   products/categories/zones (clearly marked in the SQL file — the
   application works with an empty catalogue too, it will just show
   friendly "no products yet" empty states).
4. Database and URL settings need no manual editing for local use: the
   defaults match a stock WAMP/XAMPP install (host `localhost`, user
   `root`) and `APP_URL` is auto-detected from the request, so
   `http://localhost/katale` (or your folder name/port) just works.
   Optional: copy `.env.example` to `.env` to override any value
   (database, `APP_URL`, SMTP, payment keys).
5. Create your first administrator account. From a terminal:
   ```
   cd katale
   php create_admin.php
   ```
   Follow the prompts to set your own name, email, and password — there is
   no hardcoded default admin password anywhere in the codebase.
6. Visit `http://localhost/katale/index.php` to see the storefront, and
   `http://localhost/katale/admin/login.php` to log in as admin.

## 4. Hosting on Standard PHP/MySQL Hosting

1. Upload the contents of `katale/` to your hosting account (e.g. via
   FTP/cPanel File Manager) — typically into `public_html/` or a
   subdirectory.
2. Create a MySQL database and user through your host's control panel, then
   import `sql/katale_schema.sql` via phpMyAdmin (most shared hosts provide
   this) or the `mysql` CLI.
3. Configure the environment for production. Copy `.env.example` to `.env`
   in the project folder (or, better on a live server, create the file
   outside the web root and point the `KATALE_ENV_FILE` environment
   variable at it), then fill in:
   ```
   APP_ENV=production
   APP_URL=https://yourdomain.com
   DB_HOST=...
   DB_NAME=...
   DB_USER=...
   DB_PASS=...
   ```
   `APP_URL` is the canonical base URL (no trailing slash) used by every
   link, redirect and email. Also add your SMTP and PesaJet keys there.
   The `.env` file is git-ignored — never commit real credentials.
   Payment return/callback/webhook URLs (`PAYMENT_RETURN_URL`,
   `PAYMENT_CALLBACK_URL`, `PAYMENT_WEBHOOK_URL`, …) default to
   `APP_URL`-based paths; override them in `.env` only if the payment
   provider requires specific registered values.
4. Ensure the `uploads/products/` folder is writable by PHP
   (`chmod 755` is usually sufficient; the app creates it automatically on
   first upload if missing).
5. Run `create_admin.php` via SSH if available, or temporarily insert an
   admin row via phpMyAdmin using a password you generate with PHP's
   `password_hash()` (never store a plaintext password in the database).
6. Paste a real GA4 Measurement ID into `config/config.php` if you want
   analytics — leaving it blank simply skips loading GA4, no fake ID is
   ever inserted.

## 5. Folder Structure

```
katale/
  admin/            Admin panel (separate auth, RBAC via require_admin())
  assets/           css/, js/, images/ (placeholder image)
  auth/             Customer register/login/logout
  cart/             Cart page + AJAX cart_action.php endpoint
  checkout/         Checkout form + server-side order processing
  config/           config.php (settings), database.php (PDO connection)
  customer/         Profile, wishlist, review submission
  includes/         Shared PHP: bootstrap, functions, pricing, Cart class,
                     catalogue queries, header/footer templates
  orders/           Order confirmation, history, detail, "Order Again"
  policies/         Privacy, Terms, Delivery, Returns pages
  products/         Product listing helpers (basket view, search suggest)
  sql/              katale_schema.sql
  support/          Customer support ticket pages
  uploads/products/ Uploaded product/category/basket images
  create_admin.php  CLI script to create the first admin
  index.php, products.php, product.php   Public storefront entry points
```

## 6. Admin Guide

1. Log in at `/admin/login.php` with the account you created via
   `create_admin.php`.
2. **Categories** (`/admin/categories.php`): add/edit/activate/deactivate
   categories; categories with existing products can't be deleted (reassign
   or remove those products first).
3. **Products** (`/admin/products.php`): add/edit products, upload an
   image, set price/unit/stock/low-stock threshold, and apply a discount
   (percentage or fixed, with a start/end window — the regular price is
   never overwritten). Deleting a product soft-deletes it (marks it
   inactive) so historical orders still display correctly.
4. **Inventory** (`/admin/inventory.php`): read-only view highlighting
   low-stock products across the catalogue.
5. **Orders** (`/admin/orders.php`): search/filter orders, open an order to
   view customer/payment/delivery details and update its status through the
   fulfilment pipeline (Received → Processing → Preparing → Ready → Out for
   Delivery → Delivered, or Cancelled).
6. **Payments** (`/admin/payments.php`): view payment records (from the real Mobile Money integration) and
   update their status if needed.
7. **Delivery Zones** (`/admin/delivery_zones.php`): add/edit zones and
   their fees — checkout reads these live, fees are never hardcoded in PHP.
8. **Shopping Baskets** (`/admin/baskets.php`): create ready-made bundles
   (e.g. "Student Basket") by picking products and quantities.
9. **Customers** (`/admin/customers.php`): view registered customers and
   deactivate accounts if necessary.
10. **Support Tickets** (`/admin/support_tickets.php`): view and reply to
    customer tickets, and change their status.

## 7. Customer Guide

1. Browse the storefront without an account — categories, search, and
   product details are all open to guests.
2. Add items to your cart as a guest; your cart is preserved and merged
   automatically once you register or log in.
3. At checkout, you'll be asked to log in or create an account if you
   haven't already.
4. Choose Home Delivery (pick your zone and address) or Market Pickup
   (free), choose Mobile Money (MTN or Airtel) or Cash on Delivery, and place your order.
5. Track your order's status from **My Orders**, and use **Order Again** to
   quickly re-order past items still in stock.
6. Leave a review on any product you've purchased and paid for, from the
   product page.
7. Reach support via **Contact/Support** in the footer, or through your
   account's **My Support Tickets** page.

## 8. Testing Checklist

**Customer flows**
- [ ] Register, log in, log out
- [ ] Edit profile, change password
- [ ] Browse by category, search (full and partial terms), sort products
- [ ] View product detail, see discount pricing and stock messaging
- [ ] Add to cart as a guest, then register — cart items persist
- [ ] Update/remove cart items, confirm totals recalculate server-side
- [ ] Checkout: pickup vs delivery, zone fee applied correctly
- [ ] Place an order, confirm stock decreases and cannot go negative
- [ ] View order history/detail, use Order Again
- [ ] Leave a review only after a paid order containing that product
- [ ] Submit and reply to a support ticket

**Admin flows**
- [ ] Admin login with RBAC (customer session cannot access `/admin/`)
- [ ] Create/edit/deactivate/delete a category
- [ ] Create/edit a product, upload an image, add stock, apply a discount
- [ ] View dashboard stats and confirm they reflect real orders (place a
      test order and re-check)
- [ ] Update an order's status and confirm the customer sees the update
- [ ] Create/edit a delivery zone and confirm checkout reflects the fee
- [ ] Create a shopping basket and add it to a cart from the storefront
- [ ] Deactivate a customer account
- [ ] Reply to and close a support ticket

**Security checks**
- [ ] Attempt to access `/admin/dashboard.php` while logged out — redirected
      to admin login
- [ ] Submit a form without a CSRF token (e.g. via curl) — rejected
- [ ] Try adding more to cart/checkout than available stock — blocked
      server-side even if attempted directly against `cart_action.php`
- [ ] Confirm passwords are stored hashed (check the `users`/`admins`
      tables — never plaintext)
- [ ] Upload a non-image file renamed to `.jpg` as a product image —
      rejected by `getimagesize()` validation

**Business logic checks**
- [ ] A discount outside its start/end window has no effect on price
- [ ] An out-of-stock product cannot be added to cart or checked out
- [ ] Changing a product's price later does not change historical order
      line items (they store the price paid at purchase time)

## 9. Known Limitations / Next Steps

This build covers the full customer and admin flow end-to-end. A few
lower-priority items from the original spec are intentionally left as
clearly-labelled next steps rather than fake/stubbed functionality:
shopping lists (schema exists in `sql/katale_schema.sql`, UI not yet
built), and wiring real GA4 event calls (`view_item`, `add_to_cart`, etc.)
beyond the page_view snippet already in `includes/header.php`. Both slot
into the existing structure without any redesign.
