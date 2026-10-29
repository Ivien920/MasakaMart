# 
c 
# MASAKAMART — Your Local Market, Online

MasakaMart is a web-based e-commerce platform developed as an individual Electronic Commerce coursework project at Uganda Martyrs University (UMU), Bachelor of Science in Information Technology.

The platform provides an online shopping experience for customers in Masaka, Uganda. Customers can browse available products, add items to a shopping cart, place orders, choose delivery or market pickup, and track their orders.

MasakaMart aims to make local shopping more convenient by connecting customers with products through an accessible online platform.

**Live website:** https://masakamart.xo.je/

**Slogan:** Your Local Market, Online

---

## Table of Contents

1. [Project Overview](#project-overview)
2. [Project Objectives](#project-objectives)
3. [Key Features](#key-features)
4. [User Roles](#user-roles)
5. [Technology Stack](#technology-stack)
6. [System Requirements](#system-requirements)
7. [Installation and Setup](#installation-and-setup)
8. [Configuration](#configuration)
9. [How to Use MasakaMart](#how-to-use-masakamart)
10. [Order and Payment Process](#order-and-payment-process)
11. [Administrator Guide](#administrator-guide)
12. [Integrations and External Services](#integrations-and-external-services)
13. [Security Considerations](#security-considerations)
14. [Testing](#testing)
15. [Deployment and Hosting](#deployment-and-hosting)
16. [Known Limitations](#known-limitations)
17. [Future Improvements](#future-improvements)
18. [Project Structure](#project-structure)
19. [Academic Context](#academic-context)
20. [Acknowledgements](#acknowledgements)

---

## Project Overview

MasakaMart is designed to support local online shopping by providing a central platform where customers can view products and submit orders.

The system follows an **e-tailer model**. Product listings, prices, stock quantities, discounts, and store policies are managed centrally by the administrator. It is not an open marketplace where independent sellers create and manage their own storefronts.

The platform supports the main stages of an online shopping process:

- Product discovery and browsing.
- Shopping cart management.
- Customer registration and authentication.
- Checkout and order placement.
- Payment and order processing.
- Delivery or market pickup.
- Order tracking and customer support.

## Project Objectives

The main objectives of MasakaMart are to:

- Provide customers in Masaka with convenient access to products online.
- Reduce the need for customers to visit physical shops just to check product availability.
- Provide an organized way to manage products, categories, prices, and inventory.
- Support online order placement and payment processing.
- Provide customers with information about their order progress.
- Improve communication between customers and the store.
- Demonstrate the practical application of electronic commerce concepts and technologies.

## Key Features

### Customer Features

- **Product browsing:** View available products and their details.
- **Product categories:** Browse products according to their categories.
- **Shopping cart:** Add products, update quantities, and remove items.
- **Customer accounts:** Register, sign in, and manage account information.
- **Checkout:** Submit orders using the available checkout options.
- **Delivery options:** Choose home delivery where supported or market pickup.
- **Payment options:** Use supported payment methods, including Mobile Money and cash on delivery where available.
- **Order tracking:** View order information and its current status.
- **Order history:** Review previous orders.
- **Order Again:** Use a previous order as a starting point for another purchase.
- **Product reviews:** Submit reviews after an eligible purchase.
- **Customer support:** Submit support requests through the platform.

### Administrator Features

The administration area supports central management of the online store, including:

- Product and category management.
- Product pricing and discounts.
- Inventory management.
- Order management and status updates.
- Customer management.
- Payment and order information.
- Delivery zones and delivery settings.
- Customer support requests.
- Dashboard reporting and business overview.

### Shopping Cart

Customers can add products to their shopping cart before proceeding to checkout. Cart quantities can be adjusted, and unwanted products can be removed.

The original system documentation describes cart persistence and cart merging after a customer signs in. Checkout requires a customer account.

### Order Management

Orders move through a defined processing workflow:

`Received → Processing → Preparing → Ready → Out for Delivery → Delivered`

Orders may also be cancelled where appropriate.

The administrator updates order statuses as orders progress through the fulfilment process.

---

## User Roles

### Customer

Customers use the public-facing website to:

- Browse products.
- Register and sign in.
- Manage their shopping cart.
- Place orders.
- Select available delivery or pickup options.
- Make payments using supported methods.
- Track orders.
- Submit eligible product reviews.
- Contact customer support.

### Administrator

The administrator manages the store through the administration area. Responsibilities include:

- Managing products and categories.
- Updating prices and stock.
- Processing customer orders.
- Managing delivery settings.
- Reviewing customer information.
- Handling support requests.
- Monitoring store activity.

*Dedicated delivery-personnel and general staff accounts are proposed future improvements, not features to assume are currently available.*

---

## Technology Stack

MasakaMart is built using web technologies intended to support a dynamic, database-driven e-commerce application.

| Technology | Purpose |
|---|---|
| PHP | Server-side application logic |
| MySQL / MariaDB | Relational database management |
| HTML5 | Page structure |
| CSS3 | Styling and responsive presentation |
| JavaScript | Client-side interactivity |
| PDO | Database access using prepared statements |
| Chart.js | Dashboard charts and visual reporting |

The project uses PHP without a full-stack PHP framework and uses vanilla JavaScript for client-side functionality.

---

## System Requirements

### Software Requirements

- PHP 8.0 or later.
- MySQL 5.7 or later, or MariaDB 10.3 or later.
- Apache or another compatible PHP web server.
- A modern web browser.
- PHP extensions:
  - `pdo_mysql`
  - `mbstring`
  - `fileinfo`

### Development Environment

A local development environment can be created using:

- XAMPP
- WAMP
- A compatible Apache, PHP, and MySQL installation

---

## Installation and Setup

The following steps describe a typical local installation. Use the actual database filename and configuration paths in the current project if they differ from the original project documentation.

### 1. Download or Clone the Project

Place the project folder in your local web server directory.

For XAMPP, this is commonly:

```text
C:\xampp\htdocs\
```

### 2. Create the Database

1. Start Apache and MySQL.
2. Open phpMyAdmin.
3. Create a database for MasakaMart.
4. Import the project's SQL schema file.
5. Import optional demo data if it is provided.

Use the SQL files included in the project repository. If they still contain legacy Katale names, retain the actual filenames and database identifiers until the application configuration and SQL references have also been updated.

### 3. Configure the Database Connection

Open the project's database configuration file and enter the correct local database credentials.

Typical configuration values include:

```text
Database host: localhost
Database name: Your database name
Database username: Your MySQL username
Database password: Your MySQL password
```

The exact configuration format depends on the configuration file used by the current source code.

### 4. Create an Administrator Account

If the project includes an administrator creation script, follow its instructions to create the initial administrator account.

Do not expose administrator credentials or setup scripts publicly.

### 5. Run the Application

Start Apache and MySQL, then open the local project URL in a browser.

For example:

```text
http://localhost/masakamart/
```

The URL may differ depending on the local project folder.

---

## Configuration

Before running MasakaMart in a production environment, review the following settings:

- Database connection details.
- Application base URL.
- Email/SMTP configuration.
- Payment gateway credentials.
- Payment callback or webhook URL.
- Product image upload permissions.
- Analytics configuration.
- Error reporting and production security settings.

Sensitive credentials should not be committed to a public Git repository. Keep secrets in protected configuration files or environment variables, according to the application's configuration design.

---

## How to Use MasakaMart

### Shopping as a Customer

1. Open the MasakaMart website.
2. Browse the available products and categories.
3. Select a product to view its details.
4. Add the product to the shopping cart.
5. Continue shopping or review the cart.
6. Sign in or create an account if required.
7. Proceed to checkout.
8. Provide the required customer and order information.
9. Select an available delivery or pickup option.
10. Select a supported payment method.
11. Submit the order.
12. Use the customer account to review order progress.

### Viewing Previous Orders

Customers can access their order history through their accounts. The Order Again option can help customers repeat a previous purchase.

### Contacting Support

Customers can use the platform's support functionality to submit questions or requests relating to their orders or shopping experience.

---

## Order and Payment Process

### Order Processing

The order workflow provides a way to monitor the progress of customer purchases.

| Status | Description |
|---|---|
| Received | The order has been submitted. |
| Processing | The order is being reviewed and processed. |
| Preparing | The ordered products are being prepared. |
| Ready | The order is ready for the next fulfilment step or collection. |
| Out for Delivery | The order has been dispatched for delivery. |
| Delivered | The order has been delivered. |
| Cancelled | The order has been cancelled. |

### Payment Methods

The platform documentation identifies the following payment options:

- MTN Mobile Money.
- Airtel Money.
- Cash on delivery, where available.

### PesaJet Integration

MasakaMart integrates with PesaJet for payment processing. The integration is deployed in the live production environment.

Payment-related configuration should be protected, and callback or webhook endpoints should be configured to match the deployed website.

Customers should receive clear feedback about payment outcomes and order confirmation.

---

## Administrator Guide

Administrators are responsible for managing the store's operations.

### Product Management

Administrators can manage product information, including:

- Product names and descriptions.
- Product categories.
- Prices.
- Product images.
- Stock quantities.
- Product availability.
- Discounts.

### Inventory Management

Inventory management helps keep product stock information updated. Administrators should update quantities when stock is received, sold, or otherwise adjusted.

### Order Management

Administrators can review customer orders and update their statuses as orders move through the fulfilment process.

### Delivery Management

The system documentation includes delivery-zone management. Administrators should maintain accurate delivery settings and communicate applicable delivery charges and collection options to customers.

### Customer Support

Administrators can review and respond to support requests submitted through the website.

---

## Integrations and External Services

MasakaMart uses external services to support selected business and technical functions.

### PesaJet

Used for payment processing. The integration is deployed in production.

### Brevo

Used for email communication. The project uses Brevo's email service for supported transactional or customer communication workflows.

### Google Analytics 4

Google Analytics 4 is installed on the live website and is reported to be working.

It provides website usage information that can help the project owner understand visitor activity. Actual traffic and conversion figures should be taken from the Analytics account rather than estimated.

### Google Search Console

Google Search Console is used to monitor the website's presence in Google Search.

Search indexing and SEO improvements are ongoing. Some pages may not yet be indexed, so the presence of the website in search results should not be treated as complete.

### OpenStreetMap-Related Services

OpenStreetMap-related services are used for location-related functionality. The exact geocoding, map display, or routing provider should be identified from the current implementation and documented accordingly.

### Marketing and Customer Awareness

The project uses several channels to build awareness:

- WhatsApp promotion.
- Promotional posters created with assistance from Gemini AI.
- A Google Business Profile.
- Direct outreach to potential local customers.

---

## Security Considerations

Security is important because the platform handles customer accounts, order details, and payment-related information.

The project documentation identifies the use of PDO prepared statements for database operations.

Additional security practices to maintain include:

- Use password hashing rather than storing plain-text passwords.
- Validate and sanitize user input.
- Use prepared statements for database queries.
- Protect administrator pages through authentication and authorization.
- Validate uploaded files and restrict upload types.
- Keep payment credentials and database passwords private.
- Use HTTPS on the production website.
- Avoid displaying sensitive technical errors to public visitors.
- Keep PHP, database software, and dependencies updated.
- Restrict access to setup and maintenance scripts.

Security should be reviewed regularly, especially before adding new user roles or expanding the platform.

---

## Testing

Testing should cover the main customer, administrator, payment, and security workflows.

### Customer Testing

- Register a new customer account.
- Sign in and sign out.
- Browse products and categories.
- Add products to the shopping cart.
- Update cart quantities.
- Remove products from the cart.
- Proceed through checkout.
- Select available delivery or pickup options.
- Submit an order.
- Review order history.
- Check order status.
- Submit an eligible product review.
- Submit a support request.

### Administrator Testing

- Sign in to the administration area.
- Create and update product categories.
- Add and edit products.
- Update stock quantities.
- Configure discounts.
- View customer orders.
- Update order statuses.
- Review customer information.
- Manage delivery zones.
- Review customer support requests.

### Integration Testing

- Verify the live payment integration using appropriate controlled transactions.
- Check payment callback handling.
- Verify email delivery through Brevo.
- Confirm Google Analytics 4 receives website activity.
- Check location-related functionality.
- Review the live website on desktop and mobile browsers.

### Security Testing

- Attempt to access administrator pages without authentication.
- Test form validation.
- Check file upload restrictions.
- Review session handling.
- Check that sensitive configuration files are not publicly accessible.
- Confirm that database queries use safe parameter handling.

Testing results should be documented using actual observations. Do not report successful transactions, test counts, or performance figures unless they have been recorded.

---

## Deployment and Hosting

MasakaMart is hosted on InfinityFree.

**Live website:** https://masakamart.xo.je/

Deployment requires the application files, database, and production configuration to be set up correctly.

### Deployment Checklist

- Upload the application files.
- Create and configure the production database.
- Import the database schema and required data.
- Set the correct production database credentials.
- Configure the production application URL.
- Configure payment gateway credentials and callback URLs.
- Configure email settings.
- Confirm file upload permissions.
- Enable HTTPS.
- Test customer registration and login.
- Test checkout and order submission.
- Verify payment and email integrations.
- Check the website on mobile and desktop devices.

Production credentials must be kept private and must not be included in public repository files.

---

## Known Limitations

The following limitations are identified in the original project documentation and should be reviewed against the current source code:

- The shopping-list database structure exists, but the corresponding user interface was not documented as implemented.
- The original documentation described Google Analytics event tracking beyond the basic page-view installation as incomplete. The current live Analytics setup should be checked before making claims about e-commerce event tracking.
- Some pages may not yet be indexed by Google.
- Dedicated delivery-personnel accounts and delivery assignment workflows are not established as existing features in the project documentation.
- Dedicated staff accounts with separate operational permissions are also proposed improvements rather than confirmed existing functionality.

---

## Future Improvements

The following improvements could extend MasakaMart's functionality and support future growth.

### 1. Expand Beyond Masaka

Extend the service to other towns and districts in Uganda. This would require configurable delivery zones, delivery fees, estimated delivery times, and product availability by service area.

### 2. Dedicated Delivery-Personnel Accounts

Introduce delivery-personnel accounts so delivery workers can sign in and view orders assigned to them.

Potential features include:

- Assigned order lists.
- Customer delivery details.
- Delivery addresses and contact information.
- Delivery status updates.
- Pickup and delivery timestamps.
- Proof of delivery.
- Delivery history.

### 3. Staff and Worker Accounts

Introduce separate staff accounts for employees responsible for:

- Order processing.
- Inventory management.
- Customer support.
- Dispatch and delivery coordination.

### 4. Role-Based Access Control

Create role-specific permissions so each employee can access only the functions required for their responsibilities.

Possible roles include:

- Administrator.
- Store manager.
- Inventory staff.
- Order-processing staff.
- Customer-support staff.
- Delivery personnel.

### 5. Delivery Assignment and Performance Tracking

Allow administrators or dispatch managers to assign orders to delivery workers and monitor delivery progress.

The system could record assignment history, delivery completion times, and delivery outcomes.

### 6. Area-Based Staff Management

Introduce area-based assignments so managers and workers can be responsible for particular towns, districts, or delivery zones.

### 7. Improved Analytics

Expand reporting to cover product performance, order trends, customer activity, and conversion rates. E-commerce events should be configured and verified before using them for business decisions.

### 8. Search Engine Optimization

Continue improving page titles, descriptions, structured data, internal links, sitemap coverage, and indexing through Google Search Console.

### 9. Customer Engagement

Consider loyalty rewards, promotional campaigns, product recommendations, and improved customer communication.

### 10. Scalability and Performance

As traffic and orders increase, review hosting capacity, database performance, image optimization, caching, and backup procedures.

---

## Project Structure

The original project documentation describes a PHP application organized into functional directories. The following is a representative structure; confirm names against the current repository before using it as an exact directory reference.

```text
masakamart/
│
├── admin/              # Administration pages
├── assets/             # CSS, JavaScript, and static assets
├── auth/               # Authentication functionality
├── cart/               # Shopping cart functionality
├── checkout/           # Checkout workflow
├── config/             # Application configuration
├── customer/           # Customer account pages
├── includes/           # Shared application components
├── orders/             # Order-related functionality
├── policies/           # Store policies
├── products/           # Product browsing and details
├── sql/                # Database schema and SQL files
├── support/            # Customer support functionality
├── uploads/
│   └── products/       # Product images
│
└── README.md
```

Some filenames and directory names may differ in the deployed or current development version.

---

## Academic Context

MasakaMart was developed as an individual progressive e-commerce coursework project for the course:

**BSIT 63102 — Electronic Commerce**

**Institution:** Uganda Martyrs University (UMU)

**Programme:** Bachelor of Science in Information Technology

The project demonstrates the application of electronic commerce concepts through the development of a working online shopping platform.

AI-assisted development tools were used during parts of the development process. These included ChatGPT, Claude, GitHub Copilot, OpenAI Codex, and Cursor. Gemini AI was also used to assist with promotional poster creation.

AI-generated suggestions and code require human review, testing, and verification before being used in the application.

---

## Acknowledgements

Thanks to Uganda Martyrs University for the academic opportunity to apply electronic commerce concepts in a practical project.

Thanks also to the developers and maintainers of the open-source technologies and external services used in the project.

---

## Project Status

MasakaMart is deployed and accessible online.

**Live website:** https://masakamart.xo.je/

Development and improvement remain ongoing, particularly in areas such as search engine indexing, customer acquisition, delivery operations, and future staff-management capabilities.

---

*MasakaMart — Your Local Market, Online.*

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
