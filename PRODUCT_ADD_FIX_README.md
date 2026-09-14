# Add Product Fix

This build includes a professional hardening of the seller Add Product flow.

## What was fixed
- Browser native validation can no longer silently block the form because the form now uses explicit visible validation.
- Product storage is checked before the form is processed; missing `listings`, `listing_images`, or `listing_attributes` tables are reported clearly.
- The database connection supports the canonical `isoko_ryacu` database and safely falls back to the older `market_db` database if the canonical database does not exist.
- Server-side validation remains authoritative.
- Product image upload and database insertion remain transactional.
- PHP syntax was checked across the project.

## Important
Do not drop the `listings` table blindly. Back up the database first. If the existing database schema is incomplete, import `sql/install_all.sql` into the intended database after taking a backup.
