# Isoko Ryacu — Production / 2,000-user launch checklist

## Before DNS goes live
- [ ] Create a dedicated MySQL user; do not use root.
- [ ] Create a strong unique DB password and set `ISOKO_DB_*` environment variables.
- [ ] Set `ISOKO_ENV=production` and `ISOKO_DEBUG=0`.
- [ ] Put the project behind HTTPS with a valid TLS certificate.
- [ ] Do not commit `.env`, payment keys, uploads, logs, or database dumps.
- [ ] Review `storage/payment.key`; rotate it if existing encrypted production data does not depend on it, and store production payment secrets outside the web root.
- [ ] Import/migrate the database using the appropriate migration files. Do NOT run demo/test seed files against an existing production database.
- [ ] Create a real SMTP provider and verify SPF/DKIM/DMARC before enabling password reset/transaction emails.
- [ ] Configure backups for MySQL and user-uploaded media to storage outside the web server.
- [ ] Test a full restore from backup.
- [ ] Configure monitoring for uptime, disk, CPU/RAM, PHP errors, 5xx responses and MySQL health.

## Security
- [ ] HTTPS redirect enabled.
- [ ] Secure + HttpOnly + SameSite cookies enabled.
- [ ] CSRF protection verified on state-changing forms/API calls.
- [ ] Login lockout/rate limiting tested.
- [ ] Idle logout tested.
- [ ] Admin/Super Admin authorization tested server-side.
- [ ] Upload directories cannot execute PHP.
- [ ] `config/`, `includes/`, `sql/`, `scripts/`, `storage/` are not web-readable.
- [ ] No real passwords/API keys appear in source, Git history, or public SQL dumps.

## Performance for ~2,000 registered users
- [ ] Use PHP-FPM + Nginx or tuned Apache, not XAMPP.
- [ ] Use MySQL 8/MariaDB with production indexes and slow-query logging.
- [ ] Enable OPcache.
- [ ] Serve images through optimized thumbnails/WebP/AVIF and preferably a CDN/object storage for scale.
- [ ] Keep Explore/search paginated.
- [ ] Add Redis only when measurements show it is useful; cache high-read, low-change data first.
- [ ] Load-test at 100, 500, 1,000 and 2,000 concurrent sessions before launch.

## Launch validation
- [ ] Register/login/logout.
- [ ] Password reset/email.
- [ ] Add/edit/view listing.
- [ ] Image upload and gallery.
- [ ] Buy/rent flows.
- [ ] Favorites/contact/report.
- [ ] Admin moderation and user management.
- [ ] Kinyarwanda/English/French/Swahili on desktop + mobile.
- [ ] 390px, 768px and desktop layouts.
- [ ] Confirm no horizontal overflow and no PHP warnings in production.

## Phase 2 — performance deployment

### Database
- Import `sql/phase2_performance.sql` once after the main schema/migration.
- Run it during a maintenance window on a large existing database because `ALTER TABLE` can temporarily consume resources.
- Confirm indexes with `SHOW INDEX FROM listings;` and inspect slow queries with MySQL slow-query logging.
- Keep pagination enabled; never remove the `LIMIT`/page boundaries from marketplace lists.

### PHP
- Enable OPcache using `deployment/php-opcache.ini.example` as a baseline.
- With `opcache.validate_timestamps=0`, restart PHP-FPM/Apache after every PHP deployment.
- Use PHP-FPM + Nginx or a properly tuned Apache installation for production rather than XAMPP.

### Apache/static assets
- Enable the modules referenced by `deployment/apache-performance.conf.example`.
- Keep uploads protected from PHP execution (already included in the project upload `.htaccess`).
- Prefer WebP/optimized thumbnails for listing images and lazy-load below-the-fold images.

### Load testing
Test authenticated and guest flows separately at 100, 500, 1,000 and 2,000 concurrent connections. Measure p95 latency, error rate, CPU, RAM, DB connections and slow queries. Do not claim 2,000-concurrent-user readiness until the target host passes these tests.
