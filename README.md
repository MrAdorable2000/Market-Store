# ISOKO RYACU — Marketplace Platform

> **Isoko Ryacu** (Kinyarwanda: *"Our Market"*) is a multi-category marketplace where users can discover, buy, sell, and rent products, properties, vehicles, equipment, and services.

Built for a university final project. Backend: **PHP 8 + MySQL (XAMPP)**. Frontend: **HTML5 + CSS3 + Vanilla JS**. API: **PHP REST (JSON)** — designed so a future Android/cross-platform app can reuse the same backend.

---

## Table of Contents

1. [Project Architecture](#1-project-architecture)
2. [Folder Structure](#2-folder-structure)
3. [Database Schema (overview)](#3-database-schema)
4. [Main Pages](#4-main-pages)
5. [User Roles](#5-user-roles)
6. [Development Roadmap](#6-development-roadmap)
7. [Setup Instructions (XAMPP)](#7-setup-instructions-xampp)
8. [Default Accounts](#8-default-accounts)
9. [How Phase 1 Works (Defense Notes)](#9-how-phase-1-works-defense-notes)

---

## 1. Project Architecture

Isoko Ryacu uses a **layered, mobile-ready architecture**:

```
┌──────────────────────────────────────────────────────────────────────┐
│                         PRESENTATION LAYER                            │
│   HTML5 pages (pages/*.php)   +   assets/css, assets/js              │
│   Navbar, Footer, Hero, Listing cards, Forms                          │
└──────────────────────────────────────────────────────────────────────┘
                              │
┌──────────────────────────────────────────────────────────────────────┐
│                       APPLICATION LAYER (PHP)                          │
│   includes/auth.php        — register, login, sessions, role checks   │
│   includes/functions.php   — shared helpers (escaping, csrf, slug)   │
│   pages/*.php              — controllers + views for each page        │
└──────────────────────────────────────────────────────────────────────┘
                              │
┌──────────────────────────────────────────────────────────────────────┐
│                        REST API LAYER (JSON)                          │
│   api/v1/auth/register   api/v1/categories   api/v1/listings         │
│   api/v1/favorites        api/v1/rentals      api/v1/users           │
│   (Future mobile app will call these same endpoints.)                 │
└──────────────────────────────────────────────────────────────────────┘
                              │
┌──────────────────────────────────────────────────────────────────────┐
│                      DATA ACCESS LAYER (PDO)                          │
│   config/database.php     — PDO singleton, prepared statements        │
│   config/config.php       — app constants, DB credentials             │
└──────────────────────────────────────────────────────────────────────┘
                              │
┌──────────────────────────────────────────────────────────────────────┐
│                     DATABASE LAYER (MySQL 8)                          │
│   users, roles, categories, listings, favorites, blog_posts, etc.    │
└──────────────────────────────────────────────────────────────────────┘
```

### Design principles

| Principle | How it is achieved |
|-----------|--------------------|
| **Separation of concerns** | Presentation / Application / API / Data layers are isolated. |
| **Mobile-ready** | REST API is decoupled from HTML — future Android app just calls `/api/v1/*`. |
| **Security** | PDO prepared statements, `password_hash()`, CSRF tokens, role checks, output escaping with `e()`. |
| **Performance** | Pagination, indexes on hot columns, lazy-loaded images, default fallback images. |
| **Maintainability** | Small focused files, central config, clear naming, comments per file. |
| **Extensibility** | `listing_attributes` table allows category-specific fields without schema changes. |

---

## 2. Folder Structure

```
isoko-ryacu/
├── README.md                          ← this file
├── .gitignore
├── index.php                          ← entry point (homepage)
├── sql/
│   ├── schema.sql                     ← full database schema (DDL)
│   └── seed.sql                       ← demo data (categories, listings, blog)
├── config/
│   ├── config.php                     ← app constants + DB credentials
│   └── database.php                   ← PDO connection singleton
├── includes/
│   ├── functions.php                  ← helpers: e(), slugify(), csrf_*, redirect()
│   ├── auth.php                       ← register, login, logout, role guards
│   ├── header.php                     ← <head>, navbar, hero region
│   ├── footer.php                     ← footer scripts, closing tags
│   └── navbar.php                     ← responsive navigation (desktop + mobile)
├── assets/
│   ├── css/
│   │   ├── style.css                  ← design system: variables, base, layout
│   │   ├── components.css             ← cards, buttons, forms, badges, galleries
│   │   └── dashboard.css              ← seller + admin dashboard layouts
│   ├── js/
│   │   ├── main.js                    ← theme toggle, mobile nav, lazy load
│   │   ├── search.js                  ← search suggestions, filters
│   │   └── auth.js                    ← client-side validation helpers
│   └── images/
│       └── placeholders/             ← SVG placeholders (auto-fallback)
├── pages/
│   ├── home.php                       ← homepage sections (loaded by index.php)
│   ├── explore.php                    ← search results + filters
│   ├── categories.php                 ← all categories grid
│   ├── category.php                   ← single category with listings
│   ├── listing-details.php            ← product/property/vehicle details
│   ├── login.php  register.php  forgot.php  reset.php
│   ├── profile.php  favorites.php  notifications.php
│   ├── sell.php                       ← create listing form
│   ├── seller/
│   │   ├── dashboard.php
│   │   ├── listings.php
│   │   └── messages.php
│   ├── admin/
│   │   ├── dashboard.php
│   │   ├── listings.php
│   │   ├── users.php
│   │   └── reports.php
│   └── blog/
│       ├── index.php                  ← article list
│       └── post.php                   ← article details
├── api/
│   └── v1/
│       ├── index.php                  ← router / dispatcher
│       ├── auth/{register,login,logout}.php
│       ├── categories/index.php
│       ├── listings/index.php
│       ├── favorites/index.php
│       ├── rentals/index.php
│       └── users/index.php
└── scripts/
    └── generate_placeholders.php     ← utility: regenerate SVG placeholders
```

---

## 3. Database Schema

Full DDL is in `sql/schema.sql`. Highlights:

| Table | Purpose |
|-------|---------|
| `users` | Accounts (email, password_hash, role_id, status) |
| `roles` | admin, seller, buyer (a user can be both buyer & seller) |
| `categories` | Top-level categories (Vehicles, Properties, Fashion…) |
| `subcategories` | Sub-categories (Toyota, Apartments, Men's wear…) |
| `listings` | Title, description, price, currency, listing_type (sell/rent), status |
| `listing_images` | Multiple images per listing |
| `listing_attributes` | Category-specific key/value pairs (e.g. mileage, bedrooms) |
| `favorites` | User ↔ listing saved items |
| `rental_requests` | Requests to rent an item (date range, deposit) |
| `contact_requests` | Contact seller messages |
| `notifications` | In-app notifications |
| `reviews` | Seller / listing reviews |
| `reports` | User reports of bad listings/sellers |
| `blog_posts` | Marketplace blog articles |
| `market_insights` | Aggregate metrics (saved as demo data) |
| `site_settings` | Admin-tunable platform settings |

Indexes: `email`, `category_id`, `listing_type`, `status`, `location`, `created_at`.

---

## 4. Main Pages

| Route | Page | Notes |
|-------|------|-------|
| `/index.php` | Home | Hero, categories, trending, rentals, insights, blog |
| `/pages/explore.php` | Explore | Search + filters + pagination |
| `/pages/categories.php` | Categories | Grid of all categories |
| `/pages/category.php` | Category | Listings under a category |
| `/pages/listing-details.php` | Listing | Gallery, seller info, contact, similar |
| `/pages/login.php` | Login | Email + password |
| `/pages/register.php` | Register | Name, email, phone, password |
| `/pages/forgot.php` | Forgot password | Token-based reset flow |
| `/pages/profile.php` | Profile | Edit profile, picture, my activity |
| `/pages/favorites.php` | Favorites | Saved listings + recently viewed |
| `/pages/notifications.php` | Notifications | In-app alerts |
| `/pages/sell.php` | Create listing | Multi-step form (Phase 3) |
| `/pages/seller/dashboard.php` | Seller dashboard | Overview, stats |
| `/pages/admin/dashboard.php` | Admin dashboard | Approve listings, manage users |
| `/pages/blog/index.php` | Blog list | Article cards |
| `/pages/blog/post.php` | Blog post | Single article |

---

## 5. User Roles

| Role | Capabilities |
|------|-------------|
| **Guest** | Browse, search, view listings, register/login |
| **Buyer** | All guest + favorites, contact sellers, rental requests, reviews, notifications |
| **Seller** | All buyer + create/edit/delete listings, mark sold/rented, see listing views, messages |
| **Admin** | All seller + approve/reject listings, manage categories/users, handle reports, blog, settings |

A user can act as **both buyer and seller** — role_id points to their primary role, but the `listings` table allows any registered user to publish.

---

## 6. Development Roadmap

| Phase | Scope | Status |
|-------|-------|--------|
| **Phase 1** | Structure, DB schema, Auth, Layout, Homepage, Design system, Demo data | ✅ Done |
| Phase 2 | Categories page, Listings CRUD, Search results, Listing details page | Next |
| Phase 3 | Seller dashboard, Admin dashboard, Favorites, Rental functionality | Planned |
| Phase 4 | Blog, Market insights charts, Recommendations, Notifications | Planned |
| Phase 5 | REST API completion, Tests, Security hardening, Deployment prep | Planned |

---

## 7. Setup Instructions (XAMPP)

1. Install **XAMPP** (PHP 8.0+ and MySQL/MariaDB).
2. Copy the entire `isoko-ryacu/` folder to your htdocs directory. You may rename the folder to anything you like (e.g. `Market-store`, `isoko`, etc.) — the base URL is auto-detected from the request, so no config edits are required.
   - **Windows:** `C:\xampp\htdocs\isoko-ryacu\`
   - **macOS:** `/Applications/XAMPP/htdocs/isoko-ryacu/`
   - **Linux:** `/opt/lampp/htdocs/isoko-ryacu/`
3. Start **Apache** and **MySQL** from the XAMPP control panel.
4. Open **phpMyAdmin** (`http://localhost/phpmyadmin`) and create a new database named `isoko_ryacu` (the DB name is hardcoded in `config/config.php` — change it there if you use a different name).
5. Import **`sql/install_all.sql`** into the `isoko_ryacu` database (phpMyAdmin → Import, or `mysql -u root isoko_ryacu < sql/install_all.sql`).
   - This single file replaces the old two-step `schema.sql` + `seed.sql` import. It contains the base schema, demo data, **and every migration** (`phase2_migrate.sql`, `seller_upgrade.sql`, `product_form_upgrade.sql`, `product_management_upgrade.sql`, `category_catalog_mega_upgrade.sql`, etc.) in the verified, tested order.
   - **Why this matters:** several pages — the Seller Center product list, the product editor, the homepage, category pages, search, and the admin dashboard — query columns (`categories.name_key`, `listings.sku`, `listings.stock_quantity`, `listings.reserved_quantity`, `listings.is_published`, `listings.published_at`, etc.) that only exist after those migrations run. Importing `schema.sql` alone (the old instructions) leaves those columns missing, and those pages fail with a fatal database error the moment they're opened. `install_all.sql` avoids that entirely.
   - If you already have an existing `isoko_ryacu` database from before and don't want to drop it, you can instead import the individual files listed above in that same order — just make sure every one of them runs.
6. Edit `config/config.php` if your MySQL user/password differs from XAMPP defaults (`root` / no password).
7. Visit `http://localhost/<your-folder-name>/` in your browser.

> ✅ The project ships with demo data so the homepage is never empty.
> ✅ The base URL is auto-detected — you do **not** need to edit `APP_URL` manually, regardless of the folder name you choose.

---

## 8. Default Accounts

These accounts are created by `sql/seed.sql`. **Change them after first login** in production.

| Email | Password | Role |
|-------|----------|------|
| `admin@isoko.rw` | `Admin@12345` | Admin |
| `seller@isoko.rw` | `Seller@12345` | Seller |
| `buyer@isoko.rw` | `Buyer@12345` | Buyer |

---

## 9. How Phase 1 Works (Defense Notes)

For a university defense, you can explain:

- **Requirements analysis** — see README §1–6, mirroring the brief.
- **Database design** — `sql/schema.sql` uses normalization (3NF), FK constraints, indexes.
- **System architecture** — layered: presentation → application → API → data (see diagram above).
- **Authentication** — `includes/auth.php` uses `password_hash(PASSWORD_BCRYPT)` and PDO prepared statements; sessions carry `user_id` + `role_id`.
- **CRUD** — Phase 1 ships Create/Read for users; Phase 2 adds full CRUD for listings.
- **Search & filtering** — Phase 2 (search scaffolding visible on homepage hero).
- **Role-based access** — `require_role('admin')` guard in `includes/auth.php`.
- **API** — `api/v1/*` returns JSON; same backend can serve a mobile app later.
- **Responsive UI** — CSS Grid + Flexbox, mobile-nav drawer, dark mode toggle.
- **Real-world problem solving** — multi-category marketplace solving Rwanda's fragmented buy/sell/rent discovery.

---

## License

Educational project. Use freely for academic purposes. Replace placeholder images with properly licensed assets before any commercial use.

## Payment Provider Configuration (Super Admin)

The system includes a Super Admin-only **Payment Providers & API** section under **Admin → Settings**. It is designed so production API credentials are entered from the dashboard instead of being hard-coded in PHP.

1. Import `sql/payment_provider_settings.sql` after the main schema/upgrades.
2. Keep `storage/payment.key` private and never commit it to Git. For production, prefer the `MARKETSTORE_PAYMENT_KEY` environment variable (32+ characters) and remove the local key file.
3. Configure providers from Admin → Settings: MTN MoMo, Airtel Money, Card, Bank, and Crypto.
4. Use Sandbox first. Only enable Production after the provider account, HTTPS callback/webhook and server-side verification are ready.
5. Secrets are encrypted at rest and blank secret fields mean “keep the existing credential”.

The payment configuration page does **not** invent provider credentials. The merchant must obtain real credentials from each provider's official onboarding/developer portal.


## Public Community Users / Presence

The homepage now includes a real **People on Isoko Ryacu** section backed by the existing `users` table. It shows active registered users with profile photo (or initials), full name, phone contact, WhatsApp button when a WhatsApp number exists, and a real online/inactive indicator.

- `sql/user_presence_migration.sql` adds `users.whatsapp_number` and `users.last_seen_at`.
- Logged-in activity updates `last_seen_at` with a throttled server-side heartbeat.
- Presence is refreshed on the homepage every 30 seconds from `api/v1/users/online-status.php`.
- `pages/users.php` provides the full public users directory.
- `pages/user-profile.php` provides a public profile/contact page.
- Users can add/update their WhatsApp number from `pages/profile.php`.
- Only accounts with `status = active` are exposed; passwords, tokens and other credentials are never selected.

Run `sql/user_presence_migration.sql` once on an existing database before using the new section.

### Product Management Upgrade
Run `sql/product_management_upgrade.sql` after `sql/seller_upgrade.sql`. It keeps `listings` as the single product entity and adds published/archive lifecycle metadata plus catalog indexes. Sellers can create/edit products, manage stock, publish/unpublish approved products, and archive/restore products without a marketplace removal fee.

## Idle session security

- Authenticated sessions automatically expire after **30 minutes of inactivity**.
- The server enforces the timeout on every authenticated request.
- The browser also monitors interaction so an open protected page is signed out when the user remains inactive.
- While the user is actively interacting with an open page, a lightweight authenticated heartbeat refreshes the session.
- Change `IDLE_SESSION_TIMEOUT` and `IDLE_SESSION_HEARTBEAT` in `config/config.php` to adjust the policy.

## Phase 3 production hardening
See `deployment/PHASE3-README.md` for APCu caching, health checks, backups and staged k6 load testing.


## Mobile app installation (PWA)

Isoko Ryacu is now prepared as a **Progressive Web App (PWA)**. It can be installed from
Chrome/Edge on Android and from Safari's **Add to Home Screen** on iPhone/iPad without
rewriting the PHP backend as a separate mobile application.

Requirements:
- Serve the project over `http://localhost/...` for local development or **HTTPS** in production.
- Keep `manifest.webmanifest` and `service-worker.js` at the project root.
- Keep the PWA icons under `assets/images/logo/`.

After opening the site on a supported phone browser, use the browser's **Install app**
or **Add to Home Screen** option. The installed app opens in a standalone window.

For a true Play Store/App Store application later, the existing `/api/v1/*` REST API can
be reused by a Flutter/Android client.
