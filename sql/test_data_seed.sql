-- ============================================================================
--  ISOKO RYACU — Complete Test Data Seed
--  =====================================================================
--  Creates realistic test accounts, categories, products, and images.
--  Idempotent — safe to re-run (uses INSERT ... ON DUPLICATE KEY UPDATE).
--  Password for ALL accounts: "password" (bcrypt-hashed)
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 0. Password hash for "password" (bcrypt cost 10)
--    $2y$10$up5zrOoUlz6T0i3MvNGtmOYrDD7CP9EVcubl3e5um0MYA2RslC7Pm
-- ----------------------------------------------------------------------------
SET @pwd = '$2y$10$up5zrOoUlz6T0i3MvNGtmOYrDD7CP9EVcubl3e5um0MYA2RslC7Pm';

-- ----------------------------------------------------------------------------
-- 1. TEST SELLER ACCOUNTS (5 sellers)
-- ----------------------------------------------------------------------------
INSERT INTO users (id, role_id, full_name, email, password_hash, phone, location, is_verified, is_seller, status) VALUES
(300, 2, 'Kigali Electronics Seller', 'seller.electronics@test.local', @pwd, '+250788000300', 'Kigali, Rwanda', 1, 1, 'active'),
(301, 2, 'Rwanda Fashion Seller',    'seller.fashion@test.local',     @pwd, '+250788000301', 'Huye, Rwanda', 1, 1, 'active'),
(302, 2, 'Home & Furniture Seller',   'seller.home@test.local',        @pwd, '+250788000302', 'Musanze, Rwanda', 1, 1, 'active'),
(303, 2, 'Rwanda Agriculture Seller', 'seller.agriculture@test.local',  @pwd, '+250788000303', 'Rubavu, Rwanda', 1, 1, 'active'),
(304, 2, 'Beauty & Personal Care Seller', 'seller.beauty@test.local',   @pwd, '+250788000304', 'Kigali, Rwanda', 1, 1, 'active')
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), status = 'active';

-- Seller profiles
INSERT INTO seller_profiles (user_id, business_name, business_description) VALUES
(300, 'Kigali Electronics', 'Your trusted source for phones, laptops, and electronics in Kigali.'),
(301, 'Rwanda Fashion Store', 'Quality clothing, shoes, and accessories for men and women.'),
(302, 'Kigali Home Store', 'Furniture, kitchen, and home essentials for every Rwandan home.'),
(303, 'Rwanda Agriculture Store', 'Seeds, tools, and supplies for Rwandan farmers.'),
(304, 'Rwanda Beauty Store', 'Skincare, hair care, and beauty products you love.')
ON DUPLICATE KEY UPDATE business_name = VALUES(business_name);

-- Seller wallets
INSERT IGNORE INTO wallets (user_id, available_balance) VALUES
(300, 100000), (301, 50000), (302, 30000), (303, 20000), (304, 15000);

-- Seller payment methods
INSERT INTO withdrawal_methods (user_id, type, label, provider, phone, is_preferred, verification_status, status) VALUES
(300, 'momo', 'MTN MoMo', 'MTN Mobile Money', '+250788000300', 1, 'verified', 'active'),
(301, 'momo', 'Airtel Money', 'Airtel Money', '+250788000301', 1, 'verified', 'active'),
(302, 'bank', 'Bank of Kigali', NULL, NULL, 1, 'verified', 'active'),
(303, 'momo', 'MTN MoMo', 'MTN Mobile Money', '+250788000303', 1, 'verified', 'active'),
(304, 'momo', 'MTN MoMo', 'MTN Mobile Money', '+250788000304', 1, 'verified', 'active')
ON DUPLICATE KEY UPDATE is_preferred = VALUES(is_preferred);

-- ----------------------------------------------------------------------------
-- 2. TEST CUSTOMER ACCOUNTS (5 customers)
-- ----------------------------------------------------------------------------
INSERT INTO users (id, role_id, full_name, email, password_hash, phone, location, is_verified, is_seller, status) VALUES
(310, 3, 'Customer One',   'customer1@test.local', @pwd, '+250788000310', 'Kigali, Rwanda', 1, 0, 'active'),
(311, 3, 'Customer Two',   'customer2@test.local', @pwd, '+250788000311', 'Gitarama, Rwanda', 1, 0, 'active'),
(312, 3, 'Customer Three', 'customer3@test.local', @pwd, '+250788000312', 'Huye, Rwanda', 1, 0, 'active'),
(313, 3, 'Customer Four',  'customer4@test.local', @pwd, '+250788000313', 'Musanze, Rwanda', 1, 0, 'active'),
(314, 3, 'Customer Five',  'customer5@test.local', @pwd, '+250788000314', 'Rubavu, Rwanda', 1, 0, 'active')
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), status = 'active';

-- Customer wallets (for wallet payment testing)
INSERT IGNORE INTO wallets (user_id, available_balance) VALUES
(310, 5000000), (311, 5000000), (312, 5000000), (313, 5000000), (314, 5000000);

-- ----------------------------------------------------------------------------
-- 3. TEST ADMIN ACCOUNT
-- ----------------------------------------------------------------------------
INSERT INTO users (id, role_id, full_name, email, password_hash, phone, location, is_verified, is_seller, status) VALUES
(320, 1, 'Test Admin', 'admin.test@marketstore.local', @pwd, '+250788000320', 'Kigali, Rwanda', 1, 0, 'active')
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), status = 'active';

-- ----------------------------------------------------------------------------
-- 4. ADDITIONAL CATEGORIES (if not already existing)
--    Existing categories from seed: Vehicles, Phones & Electronics, Computers,
--    Fashion, Homes & Land, Furniture, Construction, Agriculture, Events, Services, Other
--    We need: Electronics subcategories, Beauty category
-- ----------------------------------------------------------------------------
-- Add Beauty category if not exists
INSERT IGNORE INTO categories (id, name, slug, icon, description, display_order, is_active) VALUES
(20, 'Beauty & Personal Care', 'beauty-personal-care', 'lipstick', 'Skincare, hair care, perfumes, makeup.', 12, 1);

-- Subcategories for Electronics (category 2)
INSERT IGNORE INTO subcategories (id, category_id, name, slug) VALUES
(101, 2, 'Smartphones', 'smartphones'),
(102, 2, 'Laptops', 'laptops'),
(103, 2, 'Tablets', 'tablets'),
(104, 2, 'Headphones', 'headphones'),
(105, 2, 'Chargers & Accessories', 'chargers-accessories');

-- Subcategories for Fashion (category 4)
INSERT IGNORE INTO subcategories (id, category_id, name, slug) VALUES
(111, 4, 'Men Clothing', 'men-clothing'),
(112, 4, 'Women Clothing', 'women-clothing'),
(113, 4, 'Shoes', 'shoes'),
(114, 4, 'Watches', 'watches'),
(115, 4, 'Bags', 'bags');

-- Subcategories for Furniture (category 6)
INSERT IGNORE INTO subcategories (id, category_id, name, slug) VALUES
(121, 6, 'Furniture', 'furniture-sub'),
(122, 6, 'Kitchen', 'kitchen'),
(123, 6, 'Lighting', 'lighting'),
(124, 6, 'Appliances', 'appliances');

-- Subcategories for Agriculture (category 8)
INSERT IGNORE INTO subcategories (id, category_id, name, slug) VALUES
(131, 8, 'Seeds', 'seeds'),
(132, 8, 'Farm Tools', 'farm-tools'),
(133, 8, 'Animal Feed', 'animal-feed'),
(134, 8, 'Livestock Equipment', 'livestock-equipment');

-- Subcategories for Beauty (category 20)
INSERT IGNORE INTO subcategories (id, category_id, name, slug) VALUES
(141, 20, 'Skincare', 'skincare'),
(142, 20, 'Hair Care', 'hair-care'),
(143, 20, 'Perfumes', 'perfumes'),
(144, 20, 'Makeup', 'makeup');

-- ----------------------------------------------------------------------------
-- 5. TEST PRODUCTS (60 products across all sellers and categories)
--    Every product uses an existing real image from assets/images/real/
-- ----------------------------------------------------------------------------

-- Electronics (seller 300, category 2)
INSERT INTO listings (id, seller_id, category_id, subcategory_id, title, slug, description, listing_type, price, currency, location, condition_state, availability, status, is_featured, stock_quantity, is_published, views_count, favorites_count) VALUES
(100, 300, 2, 101, 'iPhone 15 128GB', 'iphone-15-128gb', 'Brand new iPhone 15 128GB. Dual camera, A16 Bionic chip, USB-C.', 'sell', 1150000, 'RWF', 'Kigali, Nyarugenge', 'new', 'available', 'active', 1, 15, 1, 890, 45),
(101, 300, 2, 101, 'iPhone 15 Pro 256GB', 'iphone-15-pro-256gb', 'iPhone 15 Pro 256GB Titanium. Pro camera system, A17 Pro chip.', 'sell', 1450000, 'RWF', 'Kigali, Nyarugenge', 'new', 'available', 'active', 1, 10, 1, 1240, 86),
(102, 300, 2, 101, 'iPhone 16 256GB', 'iphone-16-256gb', 'Latest iPhone 16 256GB with Apple Intelligence and advanced camera.', 'sell', 1650000, 'RWF', 'Kigali, Nyarugenge', 'new', 'available', 'active', 1, 8, 1, 2100, 120),
(103, 300, 2, 101, 'Samsung Galaxy A15', 'samsung-galaxy-a15', 'Samsung Galaxy A15, affordable smartphone with Super AMOLED display.', 'sell', 185000, 'RWF', 'Kigali, Gasabo', 'new', 'available', 'active', 0, 25, 1, 340, 12),
(104, 300, 2, 101, 'Samsung Galaxy A25', 'samsung-galaxy-a25', 'Samsung Galaxy A25 5G, 6.5" display, 50MP triple camera.', 'sell', 295000, 'RWF', 'Kigali, Gasabo', 'new', 'available', 'active', 0, 20, 1, 410, 18),
(105, 300, 2, 101, 'Samsung Galaxy S24', 'samsung-galaxy-s24', 'Samsung Galaxy S24 with AI features, 6.2" Dynamic AMOLED, 200MP camera.', 'sell', 1250000, 'RWF', 'Kigali, Gasabo', 'new', 'available', 'active', 1, 12, 1, 890, 71),
(106, 300, 2, 101, 'Samsung Galaxy S24 Ultra', 'samsung-galaxy-s24-ultra', 'Samsung Galaxy S24 Ultra 512GB, S Pen included, titanium frame.', 'sell', 1650000, 'RWF', 'Kigali, Gasabo', 'new', 'available', 'active', 1, 6, 1, 1560, 95),
(107, 300, 2, 101, 'Google Pixel 9', 'google-pixel-9', 'Google Pixel 9 with advanced AI photography and Tensor G4 chip.', 'sell', 1100000, 'RWF', 'Kigali, Kicukiro', 'new', 'available', 'active', 0, 10, 1, 620, 31),
(108, 300, 2, 102, 'MacBook Air M3', 'macbook-air-m3', 'MacBook Air M3 13-inch, 8GB RAM, 256GB SSD. Thin, light, powerful.', 'sell', 1850000, 'RWF', 'Kigali, Nyarugenge', 'new', 'available', 'active', 1, 7, 1, 980, 52),
(109, 300, 2, 102, 'HP EliteBook 840 G7', 'hp-elitebook-840-g7', 'HP EliteBook 840 G7, Intel i7, 16GB RAM, 512GB SSD. Perfect for professionals.', 'sell', 720000, 'RWF', 'Kigali, Remera', 'used', 'available', 'active', 1, 5, 1, 470, 51),
(110, 300, 2, 102, 'Dell Latitude 5420', 'dell-latitude-5420', 'Dell Latitude 5420, Intel i5, 8GB RAM, 256GB SSD. Business laptop.', 'sell', 650000, 'RWF', 'Kigali, Nyarugenge', 'used', 'available', 'active', 0, 8, 1, 320, 22),
(111, 300, 2, 102, 'Lenovo ThinkPad T14', 'lenovo-thinkpad-t14', 'Lenovo ThinkPad T14, AMD Ryzen 7, 16GB RAM, 512GB SSD. Durable and fast.', 'sell', 890000, 'RWF', 'Kigali, Gasabo', 'used', 'available', 'active', 0, 4, 1, 280, 15),
(112, 300, 2, 103, 'iPad Air 2022 64GB', 'ipad-air-2022-64', 'iPad Air 2022, 64GB WiFi. M1 chip, 10.9" Liquid Retina display.', 'sell', 760000, 'RWF', 'Kigali, Nyarugenge', 'used', 'available', 'active', 0, 6, 1, 290, 24),
(113, 300, 2, 103, 'Samsung Galaxy Tab S9', 'samsung-galaxy-tab-s9', 'Samsung Galaxy Tab S9 11", 128GB, S Pen included. AMOLED display.', 'sell', 980000, 'RWF', 'Kigali, Kicukiro', 'new', 'available', 'active', 0, 5, 1, 210, 9),
(114, 300, 2, 104, 'Sony WH-1000XM5 Headphones', 'sony-wh-1000xm5', 'Sony WH-1000XM5 wireless noise-cancelling headphones. 30-hour battery.', 'sell', 320000, 'RWF', 'Kigali, Nyarugenge', 'new', 'available', 'active', 0, 15, 1, 450, 28),
(115, 300, 2, 104, 'JBL Flip 6 Speaker', 'jbl-flip-6', 'JBL Flip 6 portable Bluetooth speaker. Waterproof, 12-hour battery.', 'sell', 145000, 'RWF', 'Kigali, Gasabo', 'new', 'available', 'active', 0, 20, 1, 380, 19),
(116, 300, 2, 105, 'Anker 65W Charger', 'anker-65w-charger', 'Anker 65W USB-C fast charger. Compatible with iPhone, Samsung, MacBook.', 'sell', 45000, 'RWF', 'Kigali, Nyarugenge', 'new', 'available', 'active', 0, 50, 1, 520, 14),
(117, 300, 2, 105, 'USB-C Cable 2m', 'usb-c-cable-2m', 'Premium USB-C to USB-C cable, 2 meters. Fast charging and data transfer.', 'sell', 12000, 'RWF', 'Kigali, Nyarugenge', 'new', 'available', 'active', 0, 100, 1, 890, 5)
ON DUPLICATE KEY UPDATE title = VALUES(title);

-- Fashion (seller 301, category 4)
INSERT INTO listings (id, seller_id, category_id, subcategory_id, title, slug, description, listing_type, price, currency, location, condition_state, availability, status, is_featured, stock_quantity, is_published, views_count, favorites_count) VALUES
(120, 301, 4, 111, 'Men Cotton T-Shirt', 'men-cotton-t-shirt', 'Premium cotton t-shirt for men. Available in black, white, navy.', 'sell', 15000, 'RWF', 'Huye, Rwanda', 'new', 'available', 'active', 0, 50, 1, 210, 8),
(121, 301, 4, 111, 'Men Slim Fit Jeans', 'men-slim-fit-jeans', 'Slim fit denim jeans for men. Comfortable stretch fabric.', 'sell', 35000, 'RWF', 'Huye, Rwanda', 'new', 'available', 'active', 0, 30, 1, 180, 6),
(122, 301, 4, 113, 'Men Sport Sneakers', 'men-sport-sneakers', 'Lightweight sport sneakers for men. Breathable mesh upper.', 'sell', 55000, 'RWF', 'Huye, Rwanda', 'new', 'available', 'active', 0, 20, 1, 320, 15),
(123, 301, 4, 112, 'Women Summer Dress', 'women-summer-dress', 'Elegant summer dress for women. Floral print, lightweight fabric.', 'sell', 42000, 'RWF', 'Huye, Rwanda', 'new', 'available', 'active', 1, 25, 1, 540, 42),
(124, 301, 4, 115, 'Women Leather Handbag', 'women-leather-handbag', 'Genuine leather handbag for women. Spacious with multiple pockets.', 'sell', 68000, 'RWF', 'Huye, Rwanda', 'new', 'available', 'active', 1, 15, 1, 430, 31),
(125, 301, 4, 113, 'Women Running Sneakers', 'women-running-sneakers', 'Comfortable running sneakers for women. Cushioned sole, breathable.', 'sell', 48000, 'RWF', 'Huye, Rwanda', 'new', 'available', 'active', 0, 18, 1, 290, 12),
(126, 301, 4, 111, 'Leather Belt', 'leather-belt', 'Genuine leather belt, adjustable. Black and brown available.', 'sell', 18000, 'RWF', 'Huye, Rwanda', 'new', 'available', 'active', 0, 40, 1, 150, 4),
(127, 301, 4, 114, 'Wrist Watch Classic', 'wrist-watch-classic', 'Classic analog wrist watch. Stainless steel case, leather strap.', 'sell', 85000, 'RWF', 'Huye, Rwanda', 'new', 'available', 'active', 1, 10, 1, 670, 38),
(128, 301, 4, 111, 'Sunglasses UV400', 'sunglasses-uv400', 'UV400 protection sunglasses. Polarized lenses, stylish design.', 'sell', 25000, 'RWF', 'Huye, Rwanda', 'new', 'available', 'active', 0, 35, 1, 220, 9),
(129, 301, 4, 111, 'Men Winter Jacket', 'men-winter-jacket', 'Warm winter jacket for men. Waterproof, with hood.', 'sell', 95000, 'RWF', 'Huye, Rwanda', 'new', 'available', 'active', 0, 12, 1, 180, 7)
ON DUPLICATE KEY UPDATE title = VALUES(title);

-- Home & Furniture (seller 302, category 6)
INSERT INTO listings (id, seller_id, category_id, subcategory_id, title, slug, description, listing_type, price, currency, location, condition_state, availability, status, is_featured, stock_quantity, is_published, views_count, favorites_count) VALUES
(130, 302, 6, 121, 'Office Chair Ergonomic', 'office-chair-ergonomic', 'Ergonomic office chair with lumbar support. Adjustable height and armrests.', 'sell', 125000, 'RWF', 'Musanze, Rwanda', 'new', 'available', 'active', 1, 10, 1, 380, 22),
(131, 302, 6, 121, 'Dining Table Set 6', 'dining-table-set-6', 'Wooden dining table with 6 chairs. Solid construction, modern design.', 'sell', 350000, 'RWF', 'Musanze, Rwanda', 'new', 'available', 'active', 0, 5, 1, 210, 12),
(132, 302, 6, 121, 'Modern Sofa Set', 'modern-sofa-set', '3-seat modern sofa set, fabric upholstery. Dark grey, comfortable.', 'sell', 480000, 'RWF', 'Musanze, Rwanda', 'new', 'available', 'active', 1, 4, 1, 210, 12),
(133, 302, 6, 121, 'Queen Size Bed Frame', 'queen-size-bed-frame', 'Queen size wooden bed frame. Sturdy, easy to assemble.', 'sell', 280000, 'RWF', 'Musanze, Rwanda', 'new', 'available', 'active', 0, 7, 1, 190, 8),
(134, 302, 6, 121, 'Wooden Wardrobe', 'wooden-wardrobe', '3-door wooden wardrobe with mirror. Ample storage space.', 'sell', 320000, 'RWF', 'Musanze, Rwanda', 'new', 'available', 'active', 0, 3, 1, 150, 5),
(135, 302, 6, 123, 'LED Desk Lamp', 'led-desk-lamp', 'Adjustable LED desk lamp with USB charging port. 3 brightness levels.', 'sell', 22000, 'RWF', 'Musanze, Rwanda', 'new', 'available', 'active', 0, 30, 1, 280, 7),
(136, 302, 6, 122, 'Kitchen Blender 1.5L', 'kitchen-blender-15l', '1.5L kitchen blender with 3 speeds. Stainless steel blades.', 'sell', 65000, 'RWF', 'Musanze, Rwanda', 'new', 'available', 'active', 0, 15, 1, 320, 10),
(137, 302, 6, 124, 'Microwave Oven 20L', 'microwave-oven-20l', '20L digital microwave oven. 6 power levels, defrost function.', 'sell', 145000, 'RWF', 'Musanze, Rwanda', 'new', 'available', 'active', 0, 8, 1, 260, 8),
(138, 302, 6, 124, 'Electric Kettle 1.7L', 'electric-kettle-17l', '1.7L electric kettle, fast boiling, auto shut-off. Stainless steel.', 'sell', 35000, 'RWF', 'Musanze, Rwanda', 'new', 'available', 'active', 0, 25, 1, 420, 6),
(139, 302, 6, 121, 'Blackout Curtains Pair', 'blackout-curtains-pair', 'Blackout curtains, pair. 1.5m x 2.1m. Energy saving, noise reducing.', 'sell', 38000, 'RWF', 'Musanze, Rwanda', 'new', 'available', 'active', 0, 20, 1, 180, 4)
ON DUPLICATE KEY UPDATE title = VALUES(title);

-- Agriculture (seller 303, category 8)
INSERT INTO listings (id, seller_id, category_id, subcategory_id, title, slug, description, listing_type, price, currency, location, condition_state, availability, status, is_featured, stock_quantity, is_published, views_count, favorites_count) VALUES
(140, 303, 8, 131, 'Hybrid Maize Seeds 1kg', 'hybrid-maize-seeds-1kg', 'High-yield hybrid maize seeds, 1kg bag. Drought resistant variety.', 'sell', 4500, 'RWF', 'Rubavu, Rwanda', 'new', 'available', 'active', 0, 200, 1, 320, 6),
(141, 303, 8, 131, 'Bean Seeds 1kg', 'bean-seeds-1kg', 'Quality bean seeds, 1kg. Climbing variety, high yield.', 'sell', 5000, 'RWF', 'Rubavu, Rwanda', 'new', 'available', 'active', 0, 150, 1, 180, 4),
(142, 303, 8, 131, 'Vegetable Seeds Mixed', 'vegetable-seeds-mixed', 'Mixed vegetable seeds: tomato, cabbage, carrot, onion. 10 varieties.', 'sell', 3500, 'RWF', 'Rubavu, Rwanda', 'new', 'available', 'active', 0, 300, 1, 250, 5),
(143, 303, 8, 132, 'Farming Hoe Steel', 'farming-hoe-steel', 'Steel farming hoe with wooden handle. Durable, sharp blade.', 'sell', 8000, 'RWF', 'Rubavu, Rwanda', 'new', 'available', 'active', 0, 80, 1, 150, 3),
(144, 303, 8, 132, 'Garden Shovel', 'garden-shovel', 'Steel garden shovel with wooden handle. Digging and moving soil.', 'sell', 12000, 'RWF', 'Rubavu, Rwanda', 'new', 'available', 'active', 0, 60, 1, 120, 2),
(145, 303, 8, 132, 'Watering Can 10L', 'watering-can-10l', '10L watering can with shower head. Lightweight, durable plastic.', 'sell', 6500, 'RWF', 'Rubavu, Rwanda', 'new', 'available', 'active', 0, 100, 1, 210, 4),
(146, 303, 8, 133, 'Chicken Feed 25kg', 'chicken-feed-25kg', 'Premium chicken feed, 25kg bag. Layer and broiler formula.', 'sell', 18000, 'RWF', 'Rubavu, Rwanda', 'new', 'available', 'active', 0, 80, 1, 380, 8),
(147, 303, 8, 134, 'Chicken Feeder 5kg', 'chicken-feeder-5kg', 'Automatic chicken feeder, 5kg capacity. Reduces waste.', 'sell', 15000, 'RWF', 'Rubavu, Rwanda', 'new', 'available', 'active', 0, 40, 1, 180, 3),
(148, 303, 8, 134, 'Livestock Water Trough', 'livestock-water-trough', 'Galvanized steel water trough for livestock. 40L capacity.', 'sell', 28000, 'RWF', 'Rubavu, Rwanda', 'new', 'available', 'active', 0, 25, 1, 95, 2),
(149, 303, 8, 132, 'Farm Boots Size 42', 'farm-boots-size-42', 'Waterproof farm boots, size 42. Steel toe, anti-slip sole.', 'sell', 22000, 'RWF', 'Rubavu, Rwanda', 'new', 'available', 'active', 0, 50, 1, 220, 5)
ON DUPLICATE KEY UPDATE title = VALUES(title);

-- Beauty (seller 304, category 20)
INSERT INTO listings (id, seller_id, category_id, subcategory_id, title, slug, description, listing_type, price, currency, location, condition_state, availability, status, is_featured, stock_quantity, is_published, views_count, favorites_count) VALUES
(150, 304, 20, 141, 'Face Moisturizer 50ml', 'face-moisturizer-50ml', 'Hydrating face moisturizer, 50ml. For all skin types. SPF 15.', 'sell', 18000, 'RWF', 'Kigali, Rwanda', 'new', 'available', 'active', 0, 40, 1, 320, 12),
(151, 304, 20, 141, 'Sunscreen SPF50 100ml', 'sunscreen-spf50-100ml', 'Sunscreen SPF50+, 100ml. Broad spectrum UVA/UVB protection.', 'sell', 22000, 'RWF', 'Kigali, Rwanda', 'new', 'available', 'active', 0, 35, 1, 280, 9),
(152, 304, 20, 142, 'Anti-Dandruff Shampoo 400ml', 'anti-dandruff-shampoo-400ml', 'Anti-dandruff shampoo, 400ml. For oily and dry scalp.', 'sell', 12000, 'RWF', 'Kigali, Rwanda', 'new', 'available', 'active', 0, 60, 1, 210, 5),
(153, 304, 20, 142, 'Hair Conditioner 300ml', 'hair-conditioner-300ml', 'Nourishing hair conditioner, 300ml. For damaged and dry hair.', 'sell', 14000, 'RWF', 'Kigali, Rwanda', 'new', 'available', 'active', 0, 50, 1, 180, 4),
(154, 304, 20, 141, 'Body Lotion 400ml', 'body-lotion-400ml', 'Moisturizing body lotion, 400ml. Shea butter, 24-hour hydration.', 'sell', 16000, 'RWF', 'Kigali, Rwanda', 'new', 'available', 'active', 0, 45, 1, 250, 7),
(155, 304, 20, 143, 'Eau de Parfum 100ml', 'eau-de-parfum-100ml', 'Luxury eau de parfum, 100ml. Long-lasting floral fragrance.', 'sell', 55000, 'RWF', 'Kigali, Rwanda', 'new', 'available', 'active', 1, 20, 1, 480, 28),
(156, 304, 20, 144, 'Matte Lipstick Set', 'matte-lipstick-set', 'Set of 6 matte lipsticks. Long-lasting, vibrant colors.', 'sell', 25000, 'RWF', 'Kigali, Rwanda', 'new', 'available', 'active', 0, 30, 1, 360, 15),
(157, 304, 20, 142, 'Hair Dryer 2200W', 'hair-dryer-2200w', 'Professional hair dryer, 2200W. 3 heat settings, 2 speed settings.', 'sell', 45000, 'RWF', 'Kigali, Rwanda', 'new', 'available', 'active', 0, 15, 1, 290, 11),
(158, 304, 20, 142, 'Hair Growth Oil 100ml', 'hair-growth-oil-100ml', 'Natural hair growth oil, 100ml. Rosemary and castor oil blend.', 'sell', 15000, 'RWF', 'Kigali, Rwanda', 'new', 'available', 'active', 0, 55, 1, 420, 18),
(159, 304, 20, 141, 'Body Wash 500ml', 'body-wash-500ml', 'Moisturizing body wash, 500ml. Aloe vera and honey.', 'sell', 9000, 'RWF', 'Kigali, Rwanda', 'new', 'available', 'active', 0, 70, 1, 190, 3)
ON DUPLICATE KEY UPDATE title = VALUES(title);

-- ----------------------------------------------------------------------------
-- 6. PRODUCT IMAGES — assign real images from assets/images/real/
--    Map each product to an appropriate real image
-- ----------------------------------------------------------------------------
-- Clear existing test images for these listings
DELETE FROM listing_images WHERE listing_id >= 100 AND listing_id <= 159;

-- Electronics images (use phone.jpg, laptop.jpg, tablet.jpg, motorcycle.jpg for variety)
INSERT INTO listing_images (listing_id, image_path, is_primary, display_order) VALUES
(100, 'assets/images/real/phone.jpg', 1, 0),
(101, 'assets/images/real/phone.jpg', 1, 0),
(102, 'assets/images/real/phone.jpg', 1, 0),
(103, 'assets/images/real/phone.jpg', 1, 0),
(104, 'assets/images/real/phone.jpg', 1, 0),
(105, 'assets/images/real/phone.jpg', 1, 0),
(106, 'assets/images/real/phone.jpg', 1, 0),
(107, 'assets/images/real/phone.jpg', 1, 0),
(108, 'assets/images/real/laptop.jpg', 1, 0),
(109, 'assets/images/real/laptop.jpg', 1, 0),
(110, 'assets/images/real/laptop.jpg', 1, 0),
(111, 'assets/images/real/laptop.jpg', 1, 0),
(112, 'assets/images/real/tablet.jpg', 1, 0),
(113, 'assets/images/real/tablet.jpg', 1, 0),
(114, 'assets/images/real/phone.jpg', 1, 0),
(115, 'assets/images/real/phone.jpg', 1, 0),
(116, 'assets/images/real/phone.jpg', 1, 0),
(117, 'assets/images/real/phone.jpg', 1, 0),

-- Fashion images (use service.jpg, seller-eric.jpg, avatar-buyer.jpg for variety)
(120, 'assets/images/real/service.jpg', 1, 0),
(121, 'assets/images/real/service.jpg', 1, 0),
(122, 'assets/images/real/service.jpg', 1, 0),
(123, 'assets/images/real/service.jpg', 1, 0),
(124, 'assets/images/real/service.jpg', 1, 0),
(125, 'assets/images/real/service.jpg', 1, 0),
(126, 'assets/images/real/service.jpg', 1, 0),
(127, 'assets/images/real/service.jpg', 1, 0),
(128, 'assets/images/real/service.jpg', 1, 0),
(129, 'assets/images/real/service.jpg', 1, 0),

-- Home & Furniture (use sofa.jpg, house.jpg, apartment.jpg)
(130, 'assets/images/real/sofa.jpg', 1, 0),
(131, 'assets/images/real/sofa.jpg', 1, 0),
(132, 'assets/images/real/sofa.jpg', 1, 0),
(133, 'assets/images/real/sofa.jpg', 1, 0),
(134, 'assets/images/real/sofa.jpg', 1, 0),
(135, 'assets/images/real/sofa.jpg', 1, 0),
(136, 'assets/images/real/sofa.jpg', 1, 0),
(137, 'assets/images/real/sofa.jpg', 1, 0),
(138, 'assets/images/real/sofa.jpg', 1, 0),
(139, 'assets/images/real/sofa.jpg', 1, 0),

-- Agriculture (use agriculture.jpg, land.jpg, tent.jpg)
(140, 'assets/images/real/agriculture.jpg', 1, 0),
(141, 'assets/images/real/agriculture.jpg', 1, 0),
(142, 'assets/images/real/agriculture.jpg', 1, 0),
(143, 'assets/images/real/agriculture.jpg', 1, 0),
(144, 'assets/images/real/agriculture.jpg', 1, 0),
(145, 'assets/images/real/agriculture.jpg', 1, 0),
(146, 'assets/images/real/agriculture.jpg', 1, 0),
(147, 'assets/images/real/agriculture.jpg', 1, 0),
(148, 'assets/images/real/agriculture.jpg', 1, 0),
(149, 'assets/images/real/agriculture.jpg', 1, 0),

-- Beauty (use service.jpg, blog-sell.jpg)
(150, 'assets/images/real/service.jpg', 1, 0),
(151, 'assets/images/real/service.jpg', 1, 0),
(152, 'assets/images/real/service.jpg', 1, 0),
(153, 'assets/images/real/service.jpg', 1, 0),
(154, 'assets/images/real/service.jpg', 1, 0),
(155, 'assets/images/real/service.jpg', 1, 0),
(156, 'assets/images/real/service.jpg', 1, 0),
(157, 'assets/images/real/service.jpg', 1, 0),
(158, 'assets/images/real/service.jpg', 1, 0),
(159, 'assets/images/real/service.jpg', 1, 0)
ON DUPLICATE KEY UPDATE image_path = VALUES(image_path);

SET FOREIGN_KEY_CHECKS = 1;

-- End of test_data_seed.sql
