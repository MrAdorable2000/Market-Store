# Deployment Runbook — Isoko Ryacu on Vercel

This covers everything left after the production-readiness audit (see git log
for commits `cbc8709`, `f6a53d3`, `3e3b033` and onward). Everything here needs
your credentials/accounts, so it couldn't be done from the audit sandbox —
this is the exact sequence to run it yourself.

## 0. Before you start

Read the warnings at the top of `sql/install_all.sql` and `sql/seed.sql`.
Both seed a demo super-admin / demo accounts with **documented, public
passwords**. Step 3 below tells you exactly when to rotate them — don't skip
it.

## 1. Push the audited code

```bash
git push origin main
```
This is a clean fast-forward (3 commits ahead, no force needed, nothing to
reconcile).

## 2. Build and smoke-test the Docker image locally

Needs Docker installed (not available in the audit sandbox, so this step
hasn't been run yet — everything else in this runbook has been verified,
this one hasn't):

```bash
docker build -f Dockerfile.vercel -t market-store:vercel-test .

docker run --rm -p 8080:8080 \
  -e ISOKO_ENV=production \
  -e ISOKO_DEBUG=false \
  -e ISOKO_DB_HOST=<your-db-host> \
  -e ISOKO_DB_PORT=3306 \
  -e ISOKO_DB_NAME=isoko_ryacu \
  -e ISOKO_DB_USER=<db-user> \
  -e ISOKO_DB_PASS=<db-password> \
  -e MARKETSTORE_PAYMENT_KEY=<random-32-byte-hex> \
  market-store:vercel-test

curl localhost:8080/
curl localhost:8080/api/v1/health/
```
Expect `{"ok":true,...,"database":"ok"}` from the health check. If `database`
comes back anything else, check the DB env vars and that the DB host is
reachable from wherever you're running this (a local Docker container may
not reach a DB that only allows connections from Vercel's IP ranges — test
against a DB you can actually reach from your machine first).

Generate the payment key once, store it, reuse it everywhere (don't let it
auto-generate to disk in production — `storage/*.key` won't persist anyway
on Vercel's stateless filesystem):
```bash
openssl rand -hex 32
```

## 3. Provision the production database

Any MySQL-compatible external host works (PlanetScale, Aiven, AWS RDS,
DigitalOcean Managed MySQL, etc.) — just not inside the Vercel container
itself (see brief section 21).

```bash
mysql -h <host> -u <user> -p <dbname> < sql/install_all.sql
```

**Immediately after this runs**, rotate the demo super-admin:
```sql
-- Log in once as ethiennemugisha35@gmail.com / password to confirm access,
-- then either change its password or delete the account outright:
UPDATE users SET password_hash = '<new bcrypt hash>' WHERE email = 'ethiennemugisha35@gmail.com';
-- or, if you don't need this specific account:
DELETE FROM users WHERE email = 'ethiennemugisha35@gmail.com';
```
Same for any of the `sql/seed.sql` demo accounts if you load that file too
(`admin@isoko.rw` / `Admin@12345`, etc.) — only load `seed.sql` if you
actually want demo listings/users in production, which is unusual.

The app's own production health check will remind you if you forget this:
once `ISOKO_ENV=production`, `/api/v1/health/` triggers a throttled
server-log warning (not shown to visitors) if either known demo password
hash is still present in the `users` table.

## 4. Vercel project setup

Environment Variables (Project Settings → Environment Variables, scoped to
Production):

| Variable | Value |
|---|---|
| `ISOKO_ENV` | `production` |
| `ISOKO_DEBUG` | `false` |
| `ISOKO_DB_HOST` | your DB host |
| `ISOKO_DB_PORT` | `3306` (or your provider's port) |
| `ISOKO_DB_NAME` | `isoko_ryacu` |
| `ISOKO_DB_USER` | your DB user |
| `ISOKO_DB_PASS` | your DB password |
| `MARKETSTORE_PAYMENT_KEY` | the value from step 2 |

Connect the GitHub repo, confirm it's building from `main` via
`Dockerfile.vercel`, and deploy.

## 5. Post-deploy verification

Against the real production URL:

- [ ] `GET /` — homepage loads, no console errors
- [ ] `GET /api/v1/health/` — `"ok":true`, `"database":"ok"`
- [ ] Log in with a real (non-demo) account
- [ ] Browse marketplace, open a listing, view a seller's public profile
- [ ] Add to cart → checkout with wallet (confirm the order actually
      completes — this exact flow was broken before commit `3e3b033`)
- [ ] Seller dashboard and admin dashboard both load for their respective
      roles
- [ ] Toggle dark mode and re-check the homepage, blog, and search bar
      specifically (these had real bugs fixed this round)
- [ ] Check server/function logs once for the `[SECURITY WARNING]` line
      about demo credentials — should be silent

## 6. Storage note (already handled, no action needed)

Listing-image uploads now correctly go straight to database-blob storage
in production (matching the already-proven avatar pattern), instead of
risking the silent-404 failure mode a stateless/multi-replica filesystem
would otherwise cause. This is verified and shipped — see
`docs/object-storage-migration-plan.md` for the detail and the longer-term
real-object-storage option (not urgent, correctness issue is closed).

## 7. Known limitation: email/SMS notifications won't deliver yet

Order-update and password-reset notifications land correctly in-app
(verified), but the email/SMS channels rely on PHP's `mail()`, which
needs a local MTA that the container doesn't have. Nothing crashes —
delivery just silently fails and gets logged. See
`docs/email-sms-delivery-gap.md` for the fix (an HTTP-based transactional
email API — Resend/Postmark/SendGrid/Mailgun all work well for this) and
exactly what changes once you have a provider account.


## CSS / static asset deployment hardening
- CSS and JS URLs are generated through `asset_url()` with the app base URL and deployment version.
- The version query (`?v=1.2.0`) prevents stale CSS after a new Vercel deployment.
- The service worker is registered from the detected app root and its cache includes the public CSS/JS assets.
- On Vercel, keep `Dockerfile.vercel` at repository root so the container serves the entire `/app` tree, including `/assets`.
