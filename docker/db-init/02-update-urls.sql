-- Podmiana URL WordPress po imporcie database-template.sql
-- Uruchamiany automatycznie po 01-database.sql (database-template.sql)
UPDATE wp_options
SET option_value = 'http://localhost:8000'
WHERE option_name IN ('siteurl', 'home');
