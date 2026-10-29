# AI Development Disclosure

Uganda Martyrs University permits responsible, disclosed use of AI tools in
coursework. This document explains how AI assistance was used in building
KATALE.

## Which AI tools were used

Claude (Anthropic) was used as a coding assistant throughout the
development of this project.

## What the AI was used for

- Generating the initial database schema, PHP backend logic (authentication,
  cart, checkout, order processing), and admin panel CRUD interfaces based
  on a detailed functional specification I provided.
- Implementing security patterns (prepared statements, CSRF tokens, password
  hashing, server-side price/stock validation) according to standard PHP
  security practice.
- Drafting the CSS design system and page layouts.
- Writing this documentation set (README, testing checklist, academic
  documentation notes).

## Which parts were AI-assisted

Effectively all source files in this repository were drafted with AI
assistance, following the numbered specification I supplied covering
business rules (e.g. no hardcoded products, historical order pricing,
discount windows, popularity scoring) and the required technology
constraints (PHP/MySQL, no framework, WAMP/XAMPP compatible).

## How the generated code was reviewed

[Student to complete before submission: describe the review you personally
performed — e.g. "I read through every file, ran the installation steps
on my own WAMP setup, traced the checkout flow line-by-line to confirm I
could explain each step, and modified X, Y, Z after finding issues during
testing."]

## How bugs were tested

The testing checklist in `README.md` §8 was followed manually: customer
flows (registration through checkout and order tracking), admin flows
(category/product/order/zone/basket management), security checks (CSRF,
RBAC, stock/price tampering attempts), and business-logic edge cases
(expired discounts, out-of-stock handling, historical pricing).
[Student to complete: note any bugs found and fixed during your own
testing pass, and any additional test cases you ran.]

## How I (the student) understand the implementation

[Student to complete before your academic presentation: a short paragraph
in your own words explaining, e.g., how the cart-to-order flow works, how
discounts are calculated without overwriting the regular price, and how
the popularity score is computed — since you will need to explain these
live.]
