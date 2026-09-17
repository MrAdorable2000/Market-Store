# Reviews: displayed everywhere, submittable nowhere — IMPLEMENTED

**Update: this has now been implemented.** See `pages/orders.php`
(`action=submit_review` in the POST handler, and the "Leave a Review"
card on the order detail view). Verified end-to-end: submitted a real
5-star review through the actual UI, confirmed it lands in `reviews`
with correct `reviewer_id`/`seller_id`/`listing_id`, confirmed a second
submission attempt for the same order is cleanly blocked by the existing
`UNIQUE(reviewer_id, seller_id, listing_id)` constraint (shows "You've
already reviewed this order" instead of a raw DB error), and confirmed
the UI itself swaps the form for a "✓ You've reviewed this order"
confirmation once one exists, so a buyer never even sees a resubmittable
form.

Kept deliberately simple relative to the "suggested shape" below: no
edit/delete-your-own-review UI (not needed to close the gap), and
eligibility is `status IN ('delivered', 'completed')` rather than a more
elaborate window — matches how "Confirm Receipt" already gates on
`delivered` elsewhere on the same page.

Original writeup preserved below for context.

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
