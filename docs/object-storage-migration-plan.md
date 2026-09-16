# Listing-image storage: making it safe for Vercel's stateless filesystem

## Current state (as of this audit)

The app actually has **two different storage strategies already
implemented**, at opposite priorities:

- **Avatars** (`pages/profile.php`, read via `api/v1/profile/avatar.php`):
  stored as a database BLOB **by default** (`users.avatar_blob` /
  `avatar_mime`), falling back to disk (`avatar_path`) only as a
  last-resort, for installations where the `avatar_blob` column doesn't
  exist yet. This is already the safe priority for a stateless/serverless
  filesystem — no changes needed here.

- **Listing images** (`pages/sell.php`, read via
  `api/v1/listing-image/index.php`): stored to
  **disk first** (`assets/uploads/...`), falling back to a database BLOB
  (`listing_images.image_blob` / `image_mime`, addressed as
  `db-blob:<id>`) only if the disk write fails outright at the moment of
  upload.

The disk-first path is the problem. It works fine on a traditional
always-on server (XAMPP, a VPS), but on Vercel's container-based
deployment:

- A disk write can **succeed** during the request that handles it, and
  still not be visible to a **later** request if that request is served
  by a different container replica, or if the container is recycled
  before the next deploy.
- The existing fallback only triggers on an **immediate write failure**
  (e.g. a permissions error), so it does not protect against this
  delayed-invisibility failure mode at all.

Net effect: sellers can upload listing photos that appear to succeed, then
silently 404 for buyers shortly after, with no error anywhere.

## Recommended fix, in order of effort

### Option A (smallest change, ships today) — IMPLEMENTED

**Update: this has now been implemented** (see `includes/functions.php`,
`handle_image_upload()`). When `APP_ENV === 'production'` (i.e.
`ISOKO_ENV=production`) and a caller opted into blob fallback, the disk
write is skipped entirely and the image goes straight to the same
DB-blob path already proven for avatars — not just as a failure fallback
anymore, but as production's actual storage strategy. `local`/XAMPP
installs are completely unaffected (still disk-first, unchanged).

Verified end-to-end against a live database in both environments:
- `ISOKO_ENV=local`: published a listing with a photo → stored as a real
  file under `assets/uploads/`, exactly as before.
- `ISOKO_ENV=production`: published the identical listing with the same
  photo → stored as `db-blob:<id>` with the image bytes in
  `listing_images.image_blob`, zero new files written to disk. Confirmed
  the image serves back correctly (`GET /api/v1/listing-image/index.php?id=`)
  and the listing detail page renders normally.

Only listing images are affected — `admin/blog.php`'s cover-photo upload
doesn't opt into blob fallback, so it's untouched by this change.

Original writeup below, kept for context:


Make listing images default to DB-blob storage the same way avatars
already do, instead of only using it as a failure fallback. Concretely, in
`pages/sell.php`'s upload loop:

- Attempt the disk write as today, but treat **success or failure** the
  same way avatars do: always also consider blob storage the safe default
  in a production/Vercel environment (e.g. gate on `APP_ENV === 'production'`
  from `config/config.php`), and only use disk storage in `local`/XAMPP
  environments where the filesystem is genuinely persistent.
- `api/v1/listing-image/index.php` already reads `db-blob:<id>` correctly
  (confirmed working — this is the same code path the current failure
  fallback already exercises), so no changes needed on the read side.
- Trade-off: images become part of the `listing_images` table's storage
  footprint (MySQL row/BLOB size), and every image request becomes a DB
  read instead of a static file serve — fine at small-to-medium scale
  (this is exactly what avatars already do in this app today), but worth
  moving to Option B before the catalog gets large.

This is a same-day change with no schema migration and no new
infrastructure — it just makes listing images behave like avatars already
do.

### Option B (right long-term answer): real object storage (S3-compatible)

- Add a small storage abstraction (e.g. `includes/storage.php`) with two
  functions: `storage_put(string $key, string $bytes, string $mime): string`
  and `storage_url(string $key): string`, backed by any S3-compatible
  bucket (AWS S3, Cloudflare R2, Backblaze B2, DigitalOcean Spaces all
  work with the same S3 API and a small PHP HTTP client — no SDK
  dependency is required, a signed-request implementation is
  straightforward, or `league/flysystem-aws-s3-v3` via Composer if
  dependency management is acceptable).
- Store the returned key in `listing_images.image_path` (already a
  free-form string column, so no schema change needed — just a new prefix
  convention like `s3:<bucket>/<key>` alongside the existing plain-path
  and `db-blob:<id>` conventions `image_or_default()` already branches on).
- Update `api/v1/listing-image/index.php` and `image_or_default()`
  (`includes/functions.php`) to recognize the new prefix and either
  redirect to a signed URL or proxy the bytes, following the same
  branching pattern already used for `db-blob:`.
- New environment variables needed: `STORAGE_BUCKET`, `STORAGE_REGION`,
  `STORAGE_ACCESS_KEY`, `STORAGE_SECRET_KEY`, `STORAGE_PUBLIC_URL` (or
  provider-equivalent names).
- This removes the MySQL row-size concern entirely and lets images be
  served directly from the object store's CDN instead of through PHP.

## Recommendation

**Option A is now shipped** (see above) — production listing images go
straight to the already-proven DB-blob path instead of risking the
silent-404 failure mode. Plan **Option B** (real object storage) as
follow-up feature work once there's a bucket/provider decision to make —
it removes the MySQL row-size growth concern and lets images serve from a
CDN instead of through PHP, but isn't urgent now that the correctness
issue is closed.
