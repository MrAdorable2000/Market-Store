# Phase 2 — Marketplace Core ✅

Phase 2 transforms Isoko Ryacu from a Phase 1 demo into a real working multi-category marketplace with multi-language support, listings CRUD, search, filters, favorites, seller profiles, rental requests, reports, and admin moderation.

---

## 1. Files Created (Phase 2 only)

### Database
- `sql/phase2_migrate.sql` — migration script (idempotent, safe to re-run):
  - Adds `name_key` columns to `categories` and `subcategories` for translation
  - Adds `country`, `province`, `district`, `area` columns to `listings` (structured location)
  - Creates `seller_profiles` table (business info, rating, response rate, socials)
  - Creates `recently_viewed` table (per-user browsing history)
  - Adds new subcategories per the brief (Buses, Spare Parts, TVs, Cameras, Fashion, Offices, Cement, Bricks, Steel, Paint, Tools, Seeds, Livestock, Produce, Tents, Chairs, Sound Systems, Décor, Electrical, etc.)
  - Renames "Furniture" → "Furniture & Home"
  - Seeds seller_profiles for existing demo sellers
  - Seeds demo reviews + rental requests + a report

### Internationalization
- `lang/en.php` — English translations (default; ~250 keys)
- `lang/rw.php` — Kinyarwanda translations
- `lang/fr.php` — Français translations
- `lang/sw.php` — Kiswahili translations
- `includes/i18n.php` — `t()`, `current_lang()`, `set_lang()`, `available_langs()`, `lang_switch_url()`, `t_category()` helpers
- `includes/language_selector.php` — reusable flag + name dropdown component

### Pages (new)
- `pages/seller-profile.php` — public seller profile with avatar, rating, listings, reviews

### API endpoints (new)
- `api/v1/contact/index.php` — POST: send message to seller
- `api/v1/reports/index.php` — POST: submit a report
- `api/v1/seller-profile/index.php` — GET: seller profile + listings (JSON)
- `api/v1/listings-action/index.php` — POST: mark_sold / mark_rented / relist / delete / approve / reject / resolve_report

### JavaScript (new)
- `assets/js/favorites.js` — AJAX favorites toggle (no page reload)

### Scripts (helper utilities, not part of project)
- `scripts/translate_home.py`, `translate_auth.py`, `translate_other_pages.py`, `add_back_buttons.py`, `add_dashboard_back_buttons.py`

---

## 2. Files Modified (Phase 2 changes)

| File | Change |
|------|--------|
| `includes/functions.php` | Added `handle_image_upload()` + `optimize_image_inplace()` (GD-based), loads `i18n.php` at end |
| `includes/auth.php` | `register_user()` now uses `t()` for all error messages |
| `includes/navbar.php` | Fully translated + includes language selector |
| `includes/footer.php` | Fully translated |
| `includes/header.php` | Injects `csrf-token` + `user-logged-in` meta tags (for JS); `<html lang>` uses `current_lang()` |
| `assets/css/components.css` | Added `.back-btn` styles + `.lang-switcher` styles (~150 lines) |
| `assets/js/main.js` | Language selector dropdown toggle logic |
| `api/v1/index.php` | Routes for contact, reports, seller-profile, listings-action |
| `api/v1/favorites/index.php` | Upgraded: handles add/remove/toggle, AJAX + form post, updates `favorites_count` |
| `pages/home.php` | All hero/section strings use `t()`; listing cards use `t()`; category cards translate via `t_category()`; fav buttons wired with `data-fav-listing` |
| `pages/explore.php` | Added price range, condition, availability, date filters; mobile filter drawer; pagination; full translations |
| `pages/listing-details.php` | Full translations; working favorite button; contact/rental/report modals; recently viewed tracking (DB + cookie); similar + "you may also like" sections; seller profile link |
| `pages/categories.php` | Translated category names via `t_category()` |
| `pages/category.php` | Translated + shows subcategory chips |
| `pages/favorites.php` | Translated + recently viewed section |
| `pages/sell.php` | Real implementation: validates fields, uploads + optimizes images, inserts listing + images + attributes, marks user as seller |
| `pages/login.php` | All UI strings translated; error messages use `t()` |
| `pages/register.php` | All UI strings translated |
| `pages/forgot.php` | All UI strings translated |
| `pages/reset.php` | All UI strings translated |
| `pages/notifications.php` | Translated |
| `pages/market-insights.php` | Translated |
| `pages/profile.php` | Translated |
| `pages/blog/index.php` | Translated |
| `pages/seller/dashboard.php` | Translated stats + recent listings + messages |
| `pages/seller/listings.php` | Translated + working actions (mark_sold, mark_rented, relist, delete) via forms POSTing to `/api/v1/listings-action/` |
| `pages/seller/messages.php` | Translated |
| `pages/admin/dashboard.php` | Translated + working approve/reject/resolve_report actions |
| `pages/admin/listings.php` | Translated |
| `pages/admin/users.php` | Translated |
| `pages/admin/reports.php` | Translated |
| `pages/admin/categories.php` | Translated |

---

## 3. Database Changes

Run `sql/phase2_migrate.sql` after the Phase 1 schema + seed (no Phase 1 data is destroyed).

| Change | Table(s) |
|--------|----------|
| Add `name_key` column for translation keys | `categories`, `subcategories` |
| Add structured location columns | `listings` (`country`, `province`, `district`, `area`) |
| Add indexes for fast filtering | `listings` (country, province, district) |
| New `seller_profiles` table | (business_name, response_rate, rating_average, rating_count, total_sales, total_rentals, social links, verified_at) |
| New `recently_viewed` table | (user_id, listing_id, viewed_at) |
| Add ~25 new subcategories | `subcategories` (Buses, Spare Parts, TVs, Cameras, Fashion men/women/children/shoes/bags, Offices, Kitchen, Cement, Bricks, Steel, Paint, Tools, Seeds, Livestock, Produce, Tents, Chairs, Sound Systems, Décor, Electrical) |
| Rename "Furniture" category to "Furniture & Home" | `categories` (slug stays `furniture`) |
| Seed seller_profiles for existing demo sellers | `seller_profiles` (users 2, 3) |
| Seed demo reviews | `reviews` (so seller profiles have ratings to show) |
| Seed demo rental requests | `rental_requests` (3 demo requests) |
| Seed a demo report | `reports` (so admin dashboard has something to moderate) |

---

## 4. New Features

### Multi-language system 🌍
- 4 languages: 🇬🇧 English (default), 🇷🇼 Kinyarwanda, 🇫🇷 Français, 🇹🇿 Kiswahili
- Language selector in navbar (desktop + mobile drawer)
- Flag + native name shown; click opens dropdown with all 4
- Language persists via session + cookie (1 year)
- Resolution order: URL `?lang=xx` → session → cookie → browser `Accept-Language` → default
- ~250 translation keys covering: navigation, buttons, categories, subcategories, hero, sections, forms, listings, auth, seller dashboard, admin dashboard, errors, flash messages, empty states, footer, common terms
- **User-generated content is NOT translated** (listing titles, descriptions stay as entered)
- Category names translate via `t_category(name_key)` helper

### Categories & Subcategories
- 11 top-level categories (unchanged from Phase 1, but now with name_key + translated)
- ~40+ subcategories covering all examples from the brief
- Database-driven (admin can extend without code changes)
- Categories page and category page both show translated names

### Real listing creation (`pages/sell.php`)
- Full form: title, description, category, subcategory, type, price, currency, condition, structured location (country/province/district/area), photos (up to 5), rental fields (per-day/week/month, deposit, terms)
- Image upload with full validation:
  - Real MIME-type check (not just client-provided)
  - Max 5MB per image, max 5 images
  - Auto-resize to max 1280px via PHP GD
  - Re-encoded as JPEG quality 82 to optimize size
  - Safe random filenames
- Category-specific attributes (vehicles: make/model/year/mileage/fuel/transmission; properties: bedrooms/bathrooms/area/furnished/parking) — dynamic JS shows fields based on category
- Auto-promotes buyer → seller when first listing is created
- Inserts listing, all images (first is primary), and all attributes atomically

### Powerful search (`pages/explore.php`)
- Filters: keyword, type (buy/rent), category, subcategory, location (text + structured), price range (min/max), condition, availability, date posted (today/this week/this month)
- Sort: relevance, newest, oldest, price low→high, price high→low, most viewed
- Pagination (12 per page)
- Mobile filter drawer (toggle button on small screens)
- Empty state with "Clear all filters" button

### Listing details (`pages/listing-details.php`)
- Image gallery with thumbnails
- All listing info translated
- **Working favorite button** (AJAX, no page reload)
- Contact seller modal (sends to `/api/v1/contact/`)
- Rental request modal (sends to `/api/v1/rentals/`) — only on rent listings
- Report listing modal (sends to `/api/v1/reports/`)
- Share button (uses native `navigator.share` if available, falls back to clipboard)
- Similar listings (same category) + You may also like (same type, different category)
- Recently viewed tracking (DB for logged-in, cookie for guests)

### Favorites (real)
- AJAX toggle on every listing card (no page reload)
- Updates favorites count live
- Persists across sessions (logged-in users → DB; guests are asked to log in)
- Favorites page shows saved + recently viewed

### Seller profiles (`pages/seller-profile.php`)
- Avatar, name, verified badge, location, member since
- Rating (★ X.X with N reviews)
- Stats: active listings, sold, rented, total views
- All seller's active listings
- Recent reviews (5)
- WhatsApp link if social_whatsapp is set

### Rental request workflow
- Listing details → "Request rental" → modal with start/end date + message → POST to API
- Seller sees request in messages (Phase 1 already had seller/messages.php)
- No payment processing (per the brief)

### Report listing
- Modal with reason dropdown (scam, prohibited, misleading, duplicate, other) + optional details
- All admins get a notification
- Admin dashboard shows open reports with "Review" (resolve) button

### Seller dashboard actions
- Mark as sold (sell listings)
- Mark as rented (rent listings)
- Make available again (relist)
- Delete (with confirm dialog)
- All wired to `/api/v1/listings-action/` (CSRF-protected)

### Admin moderation
- Approve / reject pending listings
- Resolve open reports
- Approve sends a notification to the seller
- Reject sends a notification to the seller

### Mobile responsiveness
- Mobile filter drawer on Explore
- Mobile language selector in nav drawer
- All modals work on mobile (max-width: 90vw)
- Listing grids collapse to 2 columns on tablet, 1 column on phone

### Recently viewed tracking
- Logged-in users: stored in `recently_viewed` table
- Guests: stored in `isoko_recent` cookie (last 10 listings, 30-day expiry)
- Shown on Favorites page

---

## 5. Testing Instructions

### Setup
1. Make sure Phase 1 is set up and working (XAMPP, MySQL `isoko_ryacu` database with schema.sql + seed.sql imported)
2. **Run `sql/phase2_migrate.sql`** in phpMyAdmin against the `isoko_ryacu` database
3. Replace the project folder with the updated Phase 2 version

### Tests
Visit `http://localhost/<your-folder>/` and test the following:

| # | Test | Expected result |
|---|------|-----------------|
| 1 | **Language switching** — click 🇬🇧 in navbar → select 🇷🇼 Kinyarwanda | All nav, hero, buttons, footer switch to Kinyarwanda. Listing titles stay in original language. |
| 2 | Switch to 🇫🇷 Français → 🇹🇿 Kiswahili → 🇬🇧 English | All 4 languages work. Refresh page — selection persists. |
| 3 | **Categories** — visit `/pages/categories.php` | 11 translated categories shown. |
| 4 | Click "Vehicles" category | Subcategories (Cars, Motorcycles, Trucks, Buses, Spare Parts) shown as chips. |
| 5 | **Create listing** — log in as `seller@isoko.rw` / `Seller@12345`, visit `/pages/sell.php` | Form with all fields. |
| 6 | Fill form, upload 2-3 photos, submit | Listing is created (status=pending). You're redirected to the listing details page. |
| 7 | Log in as `admin@isoko.rw` / `Admin@12345`, visit admin dashboard | Your pending listing shows in the approval queue. |
| 8 | Click "Approve" on your listing | Listing status changes to active. Seller gets a notification. |
| 9 | **Search** — visit `/pages/explore.php?q=toyota` | Listings matching "toyota" shown. |
| 10 | **Filters** — set price range 0-1000000, condition=used, date=this week | Only matching listings shown. |
| 11 | **Mobile filters** — resize window to <880px | Filter toggle button appears. Click → filter drawer opens. |
| 12 | **Listing details** — click any listing | Gallery, badges, contact/save/share/report buttons. Click favorite heart — it toggles (AJAX). |
| 13 | **Contact seller** (logged in) — click "Contact seller" | Modal opens. Submit → success flash. Seller sees message in their messages page. |
| 14 | **Report listing** — click "Report listing" | Modal opens. Select reason → submit → success flash. Admin sees report. |
| 15 | **Rental request** — on a rental listing, click "Request rental" | Modal with date pickers. Submit → success flash. |
| 16 | **Seller profile** — click "View seller profile" on any listing | Public profile with stats, listings, reviews. |
| 17 | **Seller dashboard** — log in as seller, visit `/pages/seller/dashboard.php` | Stats, recent listings, messages. |
| 18 | Click "My listings" | Table of your listings with mark-sold/mark-rented/relist/delete buttons. Click "Mark sold" → availability updates. |
| 19 | **Admin moderation** — log in as admin, visit admin dashboard | Approve/reject pending listings. Resolve reports. All actions work. |
| 20 | **Recently viewed** — visit several listings → go to Favorites | Recently viewed section appears at the bottom. |
| 21 | **Empty states** — search for a non-existent keyword | Friendly empty state with translated message + "Clear all filters" button. |
| 22 | **API** — `GET /api/v1/listings` | JSON response with listing data. |
| 23 | `GET /api/v1/seller-profile?id=2` | JSON with seller + their listings. |
| 24 | `POST /api/v1/favorites?id=1&action=add` (with CSRF header) | Returns JSON `{success:true, state:"saved", favorites_count:N}` |
| 25 | **Back button** — on any auth/listing/blog page, click "Back" | Returns to previous page via `history.back()` (or fallback URL). |

### Languages
Test that switching between 🇬🇧 / 🇷🇼 / 🇫🇷 / 🇹🇿 updates:
- Navbar links
- Hero title + subtitle
- All section headings on homepage
- All buttons (Search, Save, Contact, etc.)
- Listing card badges ("For sale" / "For rent")
- Form labels
- Empty states
- Footer
- Seller dashboard headings
- Admin dashboard headings

### Notes
- Some Kinyarwanda / Kiswahili translations may benefit from review by a native speaker
- User-generated content (listing titles, descriptions, seller names) is NEVER translated
- All category names ARE translated via `t_category(name_key)` because they are system-defined

---

## 6. Known Limitations

1. **Image optimization**: Files are re-encoded as JPEG even if uploaded as PNG/WebP. The file extension may not always match the content type (browsers sniff and display correctly, but it's not strictly correct).
2. **Kinyarwanda translations**: Some translations are best-effort and may benefit from review by a native Kinyarwanda speaker.
3. **No payment processing**: Rental requests are workflow-only (per the brief). No money changes hands on the platform.
4. **No edit listing page yet**: Sellers can mark sold/rented/relist/delete but cannot edit listing details after creation. (Phase 3)
5. **No chat system**: Contact is one-shot messages, not back-and-forth chat. (Phase 3)
6. **No image deletion**: Once uploaded, images can only be removed by deleting the whole listing. (Phase 3)
7. **Search suggestions are static**: The autocomplete on the hero search shows hardcoded popular terms, not real indexed searches. (Phase 3)
8. **No password reset email**: The reset link is shown directly in dev mode (Phase 1 limitation preserved).
9. **Reviews cannot be added yet**: The seller profile shows existing reviews but there's no UI for buyers to leave reviews. (Phase 3)
10. **Some text in admin sub-pages (admin/users.php, admin/listings.php, admin/reports.php) may still have untranslated strings** in the table headers — these will be polished in a Phase 2.1 patch.
11. **Categories admin page** still has disabled "Edit" buttons — full category CRUD lands in Phase 3.

---

## 7. Phase 3 Roadmap

- **Edit listing page** (`pages/edit-listing.php`) — sellers can update listing details, add/remove images
- **Chat system** — back-and-forth messaging between buyer and seller
- **Reviews** — buyers can leave star ratings + comments for sellers
- **Notifications** — wire all notification types (favorite received, listing approved/rejected, rental request received, message received)
- **Search autocomplete** — real indexed search suggestions
- **Admin category CRUD** — full add/edit/delete categories with name_key for translation
- **Image management** — delete individual listing images
- **Payment preparation** — structure for future payment integration (still no real payments)
- **Mobile app API** — bearer-token auth for stateless mobile clients
- **Performance** — caching layer, image CDN preparation
- **Tests** — automated test suite (PHPUnit for backend, Jest for JS)
- **Deployment** — Docker / deployment scripts
