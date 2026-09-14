-- Run after category_catalog_mega_upgrade.sql
SELECT COUNT(*) AS active_main_categories FROM categories WHERE is_active = 1 AND parent_id IS NULL;
SELECT COUNT(*) AS active_subcategories FROM subcategories s JOIN categories c ON c.id=s.category_id WHERE c.is_active=1;
SELECT c.id, c.name, c.slug, COUNT(s.id) AS subcategory_count
FROM categories c LEFT JOIN subcategories s ON s.category_id=c.id
WHERE c.is_active=1 AND c.parent_id IS NULL
GROUP BY c.id, c.name, c.slug
ORDER BY c.display_order, c.name;
