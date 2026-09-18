# Dispute resolution: designed, never built — IMPLEMENTED

**Update: this has now been implemented**, using reasonable defaults
where the original writeup flagged product decisions (kept intentionally
minimal — no seller-response window, no evidence upload, admin resolves
directly from the dispute's existing reason/description):

- `pages/admin/disputes.php` — a list view (counts by status, filterable)
  with three resolve actions per open dispute: refund buyer in full,
  release to seller in full, or a split (admin enters the buyer's share,
  the rest goes to the seller).
- `dispute_resolve` action in `api/v1/admin-action/index.php` — uses
  `wallet_refund()`/`wallet_release()` directly rather than
  `release_escrow_to_seller()`, since that function is buyer-authorized
  and explicitly refuses disputed orders (correct for its own purpose,
  wrong fit for admin resolution). Wrapped in one transaction: fund
  movement, order status update, dispute record update, and both
  parties' notifications either all happen or none do.
- Blocks re-resolving an already-resolved dispute, and blocks resolving
  an order with no funds actually held (already refunded/released some
  other way) — both return a clean message rather than silently
  double-moving money.
- Added a sidebar nav entry with an open-dispute count badge, matching
  the existing reports/rentals pattern.

Verified end-to-end against a live database: opened a dispute, resolved
it as a split (buyer got exactly the specified amount, seller got exactly
the remainder), then a separate dispute resolved as a full refund
(buyer's wallet credited to the exact franc, order correctly marked
cancelled), then confirmed attempting to resolve either dispute a second
time is cleanly blocked with zero further fund movement to either party.

Original writeup preserved below for context.

## What exists

- Buyers can open a dispute on an order via `pages/orders.php` (`action=open_dispute`)
  — this works, is CSRF-protected, and correctly moves the order to
  `status='disputed'`, freezing it out of the normal fulfillment flow.
- The `disputes` table schema (`sql/install_all.sql`) is **fully designed**
  for a complete resolution workflow:
  - `status`: `open` → `under_review` → `waiting_buyer` / `waiting_seller`
    → `resolved` / `closed`
  - `resolution`: `pending`, `refund_full`, `refund_partial`,
    `release_to_seller`, `split`
  - `refund_amount`, `admin_id` (who handled it), `resolved_at`
- `pages/admin/super-dashboard.php` shows a **read-only count** of disputed
  orders as a KPI tile. That's the entire admin-facing surface today.

## What's missing

There is no admin action, page, or API endpoint anywhere in the codebase
that actually **resolves** a dispute — no way to move it out of `open`,
no way to trigger a refund or release funds from within a dispute, no
way to record which admin handled it. I checked every action type in
`api/v1/admin-action/index.php` (24 of them) and every admin page —
nothing dispute-related exists beyond the count.

**Practical effect:** once a buyer opens a dispute, the order's escrowed
funds are frozen with no code path to ever release them. This isn't a bug
in existing logic (there's nothing broken to fix) — the feature's data
model was planned but the resolution workflow itself was never
implemented.

## Suggested shape (using the schema that's already there)

An admin dispute-resolution page/action would need to, at minimum:

1. List orders with `status='disputed'`, joined to their `disputes` row.
2. Let an admin move a dispute to `under_review` and record `admin_id`.
3. Provide a resolution action with three outcomes, reusing the existing
   `release_escrow_to_seller()` and `wallet_refund()` functions (both
   already transaction-safe as of this audit):
   - **Full refund to buyer** — `wallet_refund()` for the full
     `grand_total`, set `resolution='refund_full'`.
   - **Release to seller** — `release_escrow_to_seller()`, set
     `resolution='release_to_seller'`.
   - **Split/partial** — needs a decision on how the admin specifies the
     split (a single amount field going to whichever party, or two
     fields that must sum to the order total) and would need a small new
     wallet function (partial refund + partial release in one
     transaction) since the existing two functions each move the full
     amount to one side.
4. Set `disputes.status='resolved'`, `resolved_at=NOW()`, and update the
   order's own `status` back to something terminal (`completed` or
   `cancelled`, depending on outcome) so it exits the disputed state.
5. Notify both buyer and seller of the outcome (the existing
   `notify_critical()` / `notifications` pattern used elsewhere in
   `orders.php` covers this).

This is real feature work — product decisions about evidence, response
windows, and partial-split UX belong to you, not something to guess at
and ship silently. Flagging it here rather than building it.
