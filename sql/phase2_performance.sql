-- Isoko Ryacu — Phase 2 performance indexes
-- Safe to run on an existing Phase 1/2 database.
-- These indexes target the marketplace's common filter/sort paths.

SET @db = DATABASE();

-- listings: active marketplace browsing, seller dashboards, moderation queues
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='listings' AND index_name='idx_list_status_availability_created')=0,
 'ALTER TABLE listings ADD INDEX idx_list_status_availability_created (status, availability, created_at DESC)', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='listings' AND index_name='idx_list_category_status_created')=0,
 'ALTER TABLE listings ADD INDEX idx_list_category_status_created (category_id, status, created_at DESC)', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='listings' AND index_name='idx_list_subcategory_status_created')=0,
 'ALTER TABLE listings ADD INDEX idx_list_subcategory_status_created (subcategory_id, status, created_at DESC)', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='listings' AND index_name='idx_list_type_status_created')=0,
 'ALTER TABLE listings ADD INDEX idx_list_type_status_created (listing_type, status, created_at DESC)', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='listings' AND index_name='idx_list_seller_status_created')=0,
 'ALTER TABLE listings ADD INDEX idx_list_seller_status_created (seller_id, status, created_at DESC)', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='listings' AND index_name='idx_list_district_status_created')=0,
 'ALTER TABLE listings ADD INDEX idx_list_district_status_created (district, status, created_at DESC)', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Messaging/notifications: unread and seller inbox queries
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='contact_requests' AND index_name='idx_contact_seller_read_created')=0,
 'ALTER TABLE contact_requests ADD INDEX idx_contact_seller_read_created (seller_id, is_read, created_at DESC)', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='notifications' AND index_name='idx_notif_user_read_created')=0,
 'ALTER TABLE notifications ADD INDEX idx_notif_user_read_created (user_id, is_read, created_at DESC)', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Rental/report moderation queues
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='rental_requests' AND index_name='idx_rent_renter_status_created')=0,
 'ALTER TABLE rental_requests ADD INDEX idx_rent_renter_status_created (renter_id, status, created_at DESC)', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='reports' AND index_name='idx_rep_status_created')=0,
 'ALTER TABLE reports ADD INDEX idx_rep_status_created (status, created_at DESC)', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Keep MySQL statistics current after a large migration/import.
ANALYZE TABLE listings, users, categories, subcategories, favorites, rental_requests, contact_requests, notifications, reports;
