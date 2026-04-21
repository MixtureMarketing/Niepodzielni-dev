-- DB Cleanup — 2026-04
-- Uruchom przez WP-CLI: wp db query < db-cleanup-2026-04.sql
-- lub bezpośrednio w phpMyAdmin / SSH MySQL.
--
-- Co usuwa:
--   _biogram           — duplikaty pola biogram (identyczna wartość, brak ref. w kodzie)
--   _product_attributes — legacy WooCommerce na CPT psycholog (brak WC na stronie)
--   ast-*              — meta Astra Theme (aktywny motyw: Sage/niepodzielni-theme)
--   _seopress_gsc_*    — stare dane Google Search Console (SEOPress)
--   jet_apb_post_meta  — legacy JetEngine
--
-- Łącznie ~577 wierszy, ~0.35 MB

-- ── 1. Expired transients (na produkcji bez Redis są w DB) ───────────────────
DELETE FROM wp_options
WHERE option_name LIKE '_transient_timeout_%'
  AND CAST(option_value AS UNSIGNED) < UNIX_TIMESTAMP();

DELETE o FROM wp_options o
LEFT JOIN wp_options t
  ON t.option_name = REPLACE(o.option_name, '_transient_', '_transient_timeout_')
WHERE o.option_name LIKE '_transient_%'
  AND o.option_name NOT LIKE '_transient_timeout_%'
  AND t.option_id IS NULL;

-- ── 2. Legacy postmeta ───────────────────────────────────────────────────────
DELETE FROM wp_postmeta
WHERE meta_key IN ('_biogram', '_product_attributes')
   OR meta_key LIKE 'ast-%'
   OR meta_key IN ('_seopress_gsc_inspect_url_data', 'jet_apb_post_meta');

-- ── 3. Zwolnij fizyczne miejsce ──────────────────────────────────────────────
OPTIMIZE TABLE wp_postmeta;
OPTIMIZE TABLE wp_options;
