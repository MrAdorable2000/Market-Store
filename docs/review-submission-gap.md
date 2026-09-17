# Reviews: displayed everywhere, submittable nowhere

## What exists

- The `reviews` table is fully designed: `reviewer_id`, `seller_id`,
  `listing_id`, `rating` (1–5, DB-level CHECK constraint), `comment`, and
  a `UNIQUE(reviewer_id, seller_id, listing_id)` constraint that already
  prevents duplicate reviews for the same purchase.
- Review **display and moderation** are both implemented: seller
  profiles, the seller dashboard, admin analytics, and
  `pages/admin/reviews.php` (moderation/deletion) all read from this
  table.

## What's missing

There is no code path anywhere — no page, no form, no API endpoint —
that inserts a row into `reviews`. Checked every `INSERT INTO reviews`
occurrence in the codebase (zero) and every plausible naming convention
for a review-submission action (also zero). There's also no automatic
review creation tied to order completion (checked `release_escrow_to_seller()`
and the order-completion path in `orders.php` — neither touches this table).

**Practical effect:** every rating/review count shown anywhere in the app
(seller profile stars, seller dashboard stats, admin analytics) is
permanently zero/empty in a real deployment, because there is no way for
that data to ever be created. This is the same shape of gap as dispute
resolution (`docs/dispute-resolution-gap.md`) — schema and consumption
side both built, creation side never was.

## Suggested shape (using the schema that's already there)

A review-submission feature would need, at minimum:

1. A form on the buyer's completed-order view (`pages/orders.php`, likely
   alongside the existing "Confirm Receipt" action) — a star rating input
   plus an optional comment, shown only when `status === 'completed'`
   (or `'delivered'`, depending on the intended timing) and no review
   already exists for that `(reviewer_id, seller_id, listing_id)` triple.
2. A new `action=submit_review` case in `pages/orders.php`'s existing
   POST handler, following the same authorization pattern already used
   there (`$isBuyer` check against the order), inserting into `reviews`
   with a validated `rating` (1–5) and the order's `seller_id`/`listing_id`.
   The existing `UNIQUE` constraint means a duplicate attempt fails
   cleanly at the DB level — worth catching that specific case (same
   pattern as the `listing_delete` fix elsewhere in this audit) to show
   "You've already reviewed this order" instead of a raw error.
3. Decide whether editing/deleting your own review afterward is in scope
   (the schema doesn't prevent it, but there's no UI for it either way).

This is real feature work with a product decision or two embedded in it
(exactly when a review becomes eligible, whether sellers can respond) —
documented here rather than guessed at and shipped silently, consistent
with how the dispute-resolution gap was handled.
