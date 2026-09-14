# Isoko Ryacu — Phase 3 production hardening

Phase 3 adds the pieces needed before a real load test:

- optional APCu application cache helpers;
- lightweight API abuse/rate-limit helper (APCu only, no extra DB load when APCu is absent);
- `/api/v1/health/` health endpoint for uptime/load balancer checks;
- MySQL backup script using environment variables and `mysqldump --single-transaction`;
- k6 staged load-test script up to 2,000 virtual users;
- APCu and deployment examples.

## Important

2,000 registered accounts is not the same as 2,000 simultaneous users. The k6 test measures the latter and must be run on the actual staging/production-sized server. Do not start at 2,000 against a small XAMPP laptop.

Before testing:
1. Create the production DB user and apply `sql/phase2_performance.sql` and `sql/phase3_production.sql`.
2. Set `ISOKO_ENV=production` and `ISOKO_DEBUG=0`.
3. Configure HTTPS and PHP OPcache; APCu is recommended for a single PHP server.
4. Configure automated backups and perform a restore test.
5. Replace `BASE_URL` in the k6 command with the real staging URL.

Example:
`k6 run -e BASE_URL=https://staging.example.com deployment/load-test.js`
