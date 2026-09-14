-- ============================================================================
--  ISOKO RYACU — Seed Data (DEMO)
--  Run AFTER schema.sql.
--  Passwords are bcrypt hashes for:
--     Admin@12345  Seller@12345  Buyer@12345
--  All data below is clearly DEMO data — see the 'demo' tags in market_insights.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Clear demo rows (preserves schema)
TRUNCATE TABLE roles;
TRUNCATE TABLE users;
TRUNCATE TABLE categories;
TRUNCATE TABLE subcategories;
TRUNCATE TABLE listings;
TRUNCATE TABLE listing_images;
TRUNCATE TABLE listing_attributes;
TRUNCATE TABLE favorites;
TRUNCATE TABLE blog_posts;
TRUNCATE TABLE market_insights;
TRUNCATE TABLE site_settings;
TRUNCATE TABLE notifications;
SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- ROLES
-- ----------------------------------------------------------------------------
INSERT INTO roles (id, name, description) VALUES
(1, 'admin',  'Full platform management'),
(2, 'seller', 'Can publish listings + act as buyer'),
(3, 'buyer',  'Can browse, favorite, contact, request rentals');

-- ----------------------------------------------------------------------------
-- USERS  (password_hash = bcrypt)
-- ----------------------------------------------------------------------------
-- Hashes below are bcrypt for the README-documented passwords:
--     admin@isoko.rw  -> Admin@12345
--     seller@isoko.rw -> Seller@12345
--     eric.seller@isoko.rw -> Seller@12345
--     buyer@isoko.rw  -> Buyer@12345
INSERT INTO users (id, role_id, full_name, email, password_hash, phone, location, bio, avatar_path, is_verified, is_seller, status) VALUES
(1, 1, 'Platform Admin',     'admin@isoko.rw',  '$2y$10$XdY.1jpfSLXwayJRXTYoxu9HkQkkUGqDaaBp.c63km7IOXsJfbxiu', '+250 788 000 001', 'Kigali, Rwanda',  'Isoko Ryacu platform administrator.', NULL, 1, 0, 'active'),
(2, 2, 'Aline Uwase',        'seller@isoko.rw', '$2y$10$IA6zA.PZnRmTRd1ed2Zg.eSP.uBlr8gxli4fBdx35/oewIF6JrlEe', '+250 788 000 002', 'Kigali, Rwanda',  'Trusted seller of vehicles and electronics.', NULL, 1, 1, 'active'),
(3, 2, 'Eric Mugisha',       'eric.seller@isoko.rw', '$2y$10$IA6zA.PZnRmTRd1ed2Zg.eSP.uBlr8gxli4fBdx35/oewIF6JrlEe', '+250 788 000 003', 'Huye, Rwanda',  'Property agent and furniture maker.', NULL, 1, 1, 'active'),
(4, 3, 'Claire Iribagiza',   'buyer@isoko.rw',  '$2y$10$17WQlVdqnUMbp59F9CJlveqVIdSGNa/3Tv/BTFYukwXIJE7SB6YAa', '+250 788 000 004', 'Musanze, Rwanda', 'Looking for rental apartments and used phones.', NULL, 0, 0, 'active');

-- ----------------------------------------------------------------------------
-- CATEGORIES  (top-level, with display_order for homepage)
-- ----------------------------------------------------------------------------
INSERT INTO categories (id, name, slug, icon, description, parent_id, display_order, is_active) VALUES
(1,  'Vehicles',               'vehicles',           'car',           'Cars, motorcycles, trucks and machinery on wheels.', NULL, 1, 1),
(2,  'Phones & Electronics',    'phones-electronics', 'phone',         'Smartphones, tablets, audio, and accessories.',       NULL, 2, 1),
(3,  'Computers & Machines',    'computers-machines', 'laptop',       'Laptops, desktops, printers, and office equipment.',  NULL, 3, 1),
(4,  'Fashion',                 'fashion',            'shirt',         'Clothing, shoes, jewelry, and accessories.',          NULL, 4, 1),
(5,  'Homes & Land',            'homes-land',         'home',          'Houses, apartments, plots, and commercial property.', NULL, 5, 1),
(6,  'Furniture',               'furniture',          'sofa',          'Beds, sofas, tables, and office furniture.',           NULL, 6, 1),
(7,  'Construction Materials',  'construction',       'bricks',        'Cement, bricks, steel, paint, and tools.',             NULL, 7, 1),
(8,  'Agriculture & Livestock', 'agriculture',        'leaf',          'Seeds, livestock, equipment, and produce.',            NULL, 8, 1),
(9,  'Event Equipment',         'event-equipment',    'tent',          'Tents, chairs, sound systems, and décor.',              NULL, 9, 1),
(10, 'Services',                'services',           'wrench',        'Plumbing, electrical, catering, transport.',            NULL, 10, 1),
(11, 'Other Products',          'other-products',     'box',           'Anything that does not fit elsewhere.',                 NULL, 11, 1);

-- ----------------------------------------------------------------------------
-- SUBCATEGORIES  (sample — expandable)
-- ----------------------------------------------------------------------------
INSERT INTO subcategories (category_id, name, slug) VALUES
(1, 'Cars',         'cars'),
(1, 'Motorcycles',  'motorcycles'),
(1, 'Trucks',       'trucks'),
(2, 'Smartphones',  'smartphones'),
(2, 'Tablets',      'tablets'),
(2, 'Audio',        'audio'),
(3, 'Laptops',      'laptops'),
(3, 'Desktops',     'desktops'),
(3, 'Printers',     'printers'),
(5, 'Houses',       'houses'),
(5, 'Apartments',   'apartments'),
(5, 'Land',         'land'),
(5, 'Commercial',   'commercial'),
(6, 'Beds',         'beds'),
(6, 'Sofas',        'sofas'),
(6, 'Tables',       'tables'),
(10, 'Transport',   'transport'),
(10, 'Plumbing',    'plumbing'),
(10, 'Catering',    'catering'),
(10, 'Cleaning',    'cleaning');

-- ----------------------------------------------------------------------------
-- LISTINGS  (a varied demo set — sell + rent, multiple categories)
-- ----------------------------------------------------------------------------
INSERT INTO listings (id, seller_id, category_id, subcategory_id, title, slug, description, listing_type, price, currency, price_per_day, price_per_week, price_per_month, deposit, rental_terms, location, condition_state, availability, status, is_featured, views_count, favorites_count, created_at) VALUES
(1, 2, 1, 1,  'Toyota Corolla 2018',  'toyota-corolla-2018',  'Well-maintained Toyota Corolla 2018, single owner, full service history. Fuel-efficient and reliable.', 'sell',  9500000,  'RWF', NULL, NULL, NULL, NULL, NULL, 'Kigali, Kicukiro', 'used', 'available', 'active', 1, 1240, 86, NOW() - INTERVAL 2 DAY),
(2, 2, 1, 2,  'Honda CB 125 Motorcycle', 'honda-cb-125',     'Honda CB 125, low mileage, perfect for deliveries and commuting.', 'sell', 1450000, 'RWF', NULL, NULL, NULL, NULL, NULL, 'Kigali, Gasabo', 'used', 'available', 'active', 1, 640, 32, NOW() - INTERVAL 3 DAY),
(3, 2, 2, 4,  'iPhone 13 Pro 256GB',     'iphone-13-pro-256', 'iPhone 13 Pro 256GB, excellent condition, original box and charger included.', 'sell', 980000, 'RWF', NULL, NULL, NULL, NULL, NULL, 'Kigali, Nyarugenge', 'used', 'available', 'active', 1, 890, 71, NOW() - INTERVAL 1 DAY),
(4, 3, 5, 11, '3-Bedroom Apartment, Kigali', '3-bedroom-apartment-kigali', 'Modern 3-bedroom apartment in Kigali with parking, water, and electricity included.', 'rent', 0, 'RWF', 25000, 150000, 500000, 200000, 'One-month deposit, 3-month minimum lease.', 'Kigali, Kimironko', NULL, 'available', 'active', 1, 540, 42, NOW() - INTERVAL 4 DAY),
(5, 3, 5, 12, 'Residential Plot 600 sqm',    'residential-plot-600', '600 sqm residential plot with land title, ready to build.', 'sell', 18000000, 'RWF', NULL, NULL, NULL, NULL, NULL, 'Musanze', NULL, 'available', 'active', 0, 320, 18, NOW() - INTERVAL 6 DAY),
(6, 2, 3, 7,  'HP EliteBook 840 G7',         'hp-elitebook-840-g7',  'HP EliteBook 840 G7, Intel i7, 16GB RAM, 512GB SSD. Perfect for students and professionals.', 'sell', 720000, 'RWF', NULL, NULL, NULL, NULL, NULL, 'Kigali, Remera', 'used', 'available', 'active', 1, 470, 51, NOW() - INTERVAL 2 DAY),
(7, 3, 6, 14, 'Modern Sofa Set',               'modern-sofa-set',     'Three-seat modern sofa set, fabric, dark grey. Perfect for living rooms.', 'sell', 480000, 'RWF', NULL, NULL, NULL, NULL, NULL, 'Huye', 'new', 'available', 'active', 0, 210, 12, NOW() - INTERVAL 5 DAY),
(8, 2, 9, NULL,'Tent for Events (10x20m)',      'tent-events-10x20',   'Large event tent, 10x20 meters, suitable for weddings and conferences.', 'rent', 0, 'RWF', 35000, 200000, 700000, 100000, 'Damage deposit refundable on return.', 'Kigali, Gikondo', NULL, 'available', 'active', 1, 180, 22, NOW() - INTERVAL 3 DAY),
(9, 2, 8, NULL,'Irrigation Pump (Diesel)',     'irrigation-pump-diesel', 'Diesel irrigation pump, suitable for medium farms. Easy to maintain.', 'rent', 0, 'RWF', 12000, 70000, 250000, 50000, 'Renter pays for fuel and cleaning.', 'Musanze', 'used', 'available', 'active', 0, 95, 6, NOW() - INTERVAL 4 DAY),
(10,3, 10,17,'Airport Pickup Service',         'airport-pickup-service','Reliable airport pickup and drop-off in Kigali. Available 24/7.', 'sell', 15000, 'RWF', NULL, NULL, NULL, NULL, NULL, 'Kigali', NULL, 'available', 'active', 0, 320, 15, NOW() - INTERVAL 7 DAY),
(11,2, 2, 5,  'iPad Air 2022 64GB',            'ipad-air-2022-64',    'iPad Air 2022, 64GB WiFi. Excellent condition, no scratches.', 'sell', 760000, 'RWF', NULL, NULL, NULL, NULL, NULL, 'Kigali, Nyarugenge', 'used', 'available', 'active', 0, 290, 24, NOW() - INTERVAL 1 DAY),
(12,3, 5, 10, '4-Bedroom House, Kigali',       '4-bedroom-house-kigali','Spacious 4-bedroom house with garden and garage. Good neighborhood.', 'rent', 0, 'RWF', 45000, 280000, 900000, 500000, 'Two-month deposit, 1-year lease.', 'Kigali, Kanombe', NULL, 'available', 'active', 1, 410, 35, NOW() - INTERVAL 2 DAY);

-- ----------------------------------------------------------------------------
-- LISTING IMAGES  (paths to real photographs in assets/images/real/)
--   Real African-relevant stock photos downloaded with proper attribution.
--   Each listing has one primary image.  See assets/images/real/ for sources.
-- ----------------------------------------------------------------------------
INSERT INTO listing_images (listing_id, image_path, is_primary, display_order) VALUES
(1,  'assets/images/real/vehicle.jpg', 1, 0),
(2,  'assets/images/real/motorcycle.jpg', 1, 0),
(3,  'assets/images/real/phone.jpg', 1, 0),
(4,  'assets/images/real/apartment.jpg', 1, 0),
(5,  'assets/images/real/land.jpg', 1, 0),
(6,  'assets/images/real/laptop.jpg', 1, 0),
(7,  'assets/images/real/sofa.jpg', 1, 0),
(8,  'assets/images/real/tent.jpg', 1, 0),
(9,  'assets/images/real/agriculture.jpg', 1, 0),
(10, 'assets/images/real/service.jpg', 1, 0),
(11, 'assets/images/real/tablet.jpg', 1, 0),
(12, 'assets/images/real/house.jpg', 1, 0);

-- ----------------------------------------------------------------------------
-- LISTING ATTRIBUTES  (category-specific fields)
-- ----------------------------------------------------------------------------
INSERT INTO listing_attributes (listing_id, attr_key, attr_value) VALUES
(1,'make','Toyota'),(1,'model','Corolla'),(1,'year','2018'),(1,'mileage','78000'),(1,'fuel','petrol'),(1,'transmission','automatic'),
(2,'make','Honda'),(2,'model','CB 125'),(2,'year','2020'),(2,'mileage','22000'),(2,'fuel','petrol'),
(3,'brand','Apple'),(3,'model','iPhone 13 Pro'),(3,'storage','256GB'),(3,'color','graphite'),
(4,'property_type','apartment'),(4,'bedrooms','3'),(4,'bathrooms','2'),(4,'area_sqm','145'),(4,'furnished','no'),
(5,'property_type','land'),(5,'area_sqm','600'),(5,'title_deed','yes'),
(6,'brand','HP'),(6,'model','EliteBook 840 G7'),(6,'cpu','Intel i7-10610U'),(6,'ram_gb','16'),(6,'storage_gb','512'),(6,'storage_type','SSD'),
(7,'material','fabric'),(7,'color','dark grey'),(7,'seats','3'),
(8,'size_m','10x20'),(8,'capacity','200 people'),
(9,'power_source','diesel'),(9,'brand','Honda'),
(12,'property_type','house'),(12,'bedrooms','4'),(12,'bathrooms','3'),(12,'area_sqm','280'),(12,'furnished','yes');

-- ----------------------------------------------------------------------------
-- FAVORITES  (sample)
-- ----------------------------------------------------------------------------
INSERT INTO favorites (user_id, listing_id) VALUES
(4, 1), (4, 3), (4, 4), (4, 6);

-- ----------------------------------------------------------------------------
-- BLOG POSTS
-- ----------------------------------------------------------------------------
INSERT INTO blog_posts (id, author_id, title, slug, excerpt, body, cover_path, category, tags, status, views_count, published_at, created_at) VALUES
(1, 1, 'How to Buy Safely on Isoko Ryacu',  'how-to-buy-safely',
    'A practical checklist to avoid scams and protect your money when buying online.',
    '## 1. Meet in public places\nAlways meet sellers in busy, well-lit public spaces such as malls or police-monitored meeting points.\n\n## 2. Inspect before paying\nNever send money before seeing and inspecting the product yourself.\n\n## 3. Use verified sellers\nLook for the verified badge on seller profiles.\n\n## 4. Keep records\nSave chat screenshots and receipts until the transaction is complete.',
    'assets/images/real/blog-safety.jpg', 'Buying', 'safety,tips,buying', 'published', 1200, NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 10 DAY),
(2, 1, 'How to Sell Online Like a Pro',      'how-to-sell-online',
    'Take better photos, write clearer descriptions, and price your items competitively.',
    '## 1. Use good lighting\nNatural daylight is best. Avoid shadows on your product.\n\n## 2. Write honest descriptions\nMention both strengths and small defects — buyers trust honest sellers.\n\n## 3. Price fairly\nResearch similar listings before setting your price.\n\n## 4. Respond quickly\nReplies within an hour get 3x more deals.',
    'assets/images/real/blog-sell.jpg', 'Selling', 'tips,selling,photos', 'published', 980, NOW() - INTERVAL 9 DAY, NOW() - INTERVAL 9 DAY),
(3, 1, 'Renting Property in Rwanda: A Guide', 'renting-property-guide',
    'Everything you need to know about deposits, leases, and tenant rights in Rwanda.',
    '## 1. Understand deposits\nMost landlords ask for 1 to 2 months of rent as a deposit.\n\n## 2. Sign a written lease\nAlways insist on a written agreement — verbal leases are hard to enforce.\n\n## 3. Inspect the property\nTake photos when you move in so you are not blamed for old damage.\n\n## 4. Know your rights\nTenants in Rwanda are protected by the civil code — read it before signing.',
    'assets/images/real/blog-rent.jpg', 'Renting', 'renting,property,legal', 'published', 1450, NOW() - INTERVAL 8 DAY, NOW() - INTERVAL 8 DAY),
(4, 1, 'How to Avoid Scams',                  'avoiding-scams',
    'Five red flags that should make you walk away from a deal.',
    '## 1. Too good to be true\nIf a price is far below market value, it is probably a scam.\n\n## 2. Pressure to pay now\nScammers always push for immediate payment.\n\n## 3. Refuses to meet\nLegit sellers will meet in person.\n\n## 4. Asks for money before delivery\nNever send mobile money before inspecting the product.\n\n## 5. No verification\nCheck the seller profile, ratings, and listing history.',
    'assets/images/real/blog-scam.jpg', 'Safety', 'scams,security,safety', 'published', 1610, NOW() - INTERVAL 7 DAY, NOW() - INTERVAL 7 DAY),
(5, 1, 'Choosing the Right Used Laptop',    'choosing-used-laptop',
    'Specs that matter, specs that do not, and how to test before you pay.',
    '## 1. CPU and RAM matter most\nFor office work, an Intel i5 with 8GB RAM is the sweet spot.\n\n## 2. Storage type\nAn SSD will feel much faster than a hard drive, even with less capacity.\n\n## 3. Battery health\nAsk the seller for the battery cycle count and run time.\n\n## 4. Test before paying\nOpen several apps, play a video, check Wi-Fi and ports.',
    'assets/images/real/blog-laptop.jpg', 'Buying', 'laptops,tips,electronics', 'published', 760, NOW() - INTERVAL 6 DAY, NOW() - INTERVAL 6 DAY);

-- ----------------------------------------------------------------------------
-- MARKET INSIGHTS  (clearly DEMO data — labelled via period = 'demo')
-- ----------------------------------------------------------------------------
INSERT INTO market_insights (metric_key, metric_value, metric_count, period) VALUES
('top_searched_term', 'iPhone',         1240, 'demo'),
('top_searched_term', 'Toyota',          980,  'demo'),
('top_searched_term', 'House in Kigali', 870,  'demo'),
('top_searched_term', 'Used laptop',     720,  'demo'),
('top_searched_term', 'Plot of land',    540,  'demo'),
('most_active_category', 'Vehicles',            12, 'demo'),
('most_active_category', 'Homes & Land',         10, 'demo'),
('most_active_category', 'Phones & Electronics',  9, 'demo'),
('rental_trend', 'Apartments in Kigali rising', 15, 'demo'),
('avg_price_vehicles',  'RWF 7,500,000',  12, 'demo'),
('avg_price_laptops',   'RWF 650,000',     9, 'demo');

-- ----------------------------------------------------------------------------
-- SITE SETTINGS
-- ----------------------------------------------------------------------------
INSERT INTO site_settings (setting_key, setting_value, description) VALUES
('site_name',     'Isoko Ryacu', 'Public site name'),
('site_tagline',  'Discover Anything. Buy. Sell. Rent.', 'Homepage hero subtitle'),
('contact_email', 'support@isoko.rw', 'Public support email'),
('contact_phone', '+250 788 000 000', 'Public support phone'),
('default_currency', 'RWF', 'Default listing currency'),
('posts_per_page', '12', 'Listings per page on Explore'),
('allow_guest_contact', '1', 'Allow non-logged-in users to message sellers'),
('enable_dark_mode', '1', 'Show dark mode toggle in navbar');

-- ----------------------------------------------------------------------------
-- NOTIFICATIONS  (demo)
-- ----------------------------------------------------------------------------
INSERT INTO notifications (user_id, type, title, body, link, is_read) VALUES
(4, 'favorite', 'Listing favorited', 'Your saved iPhone 13 Pro had a price drop.', 'pages/listing-details.php?id=3', 0),
(4, 'system',   'Welcome to Isoko Ryacu', 'Complete your profile to get better recommendations.', 'pages/profile.php', 0),
(2, 'message',  'New contact request', 'Someone is interested in your Toyota Corolla.', 'pages/listing-details.php?id=1', 0);

-- End of seed.sql
