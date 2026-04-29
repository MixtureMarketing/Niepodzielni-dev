# Kompleksowa Lista Plików Projektu

Spis wszystkich plików źródłowych i konfiguracyjnych tworzonych w ramach projektu (z pominięciem bibliotek zewnętrznych `vendor/`, rdzenia WordPress `wp/` oraz skompilowanych plików tłumaczeń `.mo`/`.l10n.php`).

## 🛠️ Konfiguracja Główna i Infrastruktura
- [x] `.dockerignore`
- [x] `.env.example`
- [x] `.gitignore`
- [x] `.htaccess`
- [x] `Dockerfile`
- [x] `LICENSE.md`
- [x] `README.md`
- [x] `composer.json`
- [x] `composer.lock`
- [x] `database-template.sql`
- [x] `docker-compose.yml`
- [x] `phpstan.neon`
- [x] `phpunit.xml.dist`
- [x] `pint.json`
- [x] `wp-cli.yml`
- [x] `web/wp-config.php`
- [x] `web/.htaccess`
- [x] `config/application.php`
- [x] `config/environments/development.php`
- [x] `config/environments/staging.php`

### Docker (`docker/`)
- [x] `docker/apache/000-default.conf`
- [x] `docker/cron/bookero`
- [x] `docker/db-init/02-update-urls.sql`
- [x] `docker/entrypoint.sh`
- [x] `docker/nginx/default.conf`
- [x] `docker/nginx/fastcgi_cache.conf`
- [x] `docker/php-fpm/www.conf`
- [x] `docker/php/php.ini`
- [x] `docker/web.htaccess`

## 🧠 Core Logic (`web/app/mu-plugins/niepodzielni-core/`)

### Admin
- [x] `web/app/mu-plugins/niepodzielni-core/admin/5-admin-dashboard.php`
- [x] `web/app/mu-plugins/niepodzielni-core/admin/6-admin-product-columns.php`
- [x] `web/app/mu-plugins/niepodzielni-core/admin/7-admin-settings.php`
- [x] `web/app/mu-plugins/niepodzielni-core/admin/8-login-page.php`
- [x] `web/app/mu-plugins/niepodzielni-core/admin/9-psycholog-role.php`
- [x] `web/app/mu-plugins/niepodzielni-core/admin/10-psycholog-admin-cols.php`
- [x] `web/app/mu-plugins/niepodzielni-core/admin/11-psycholog-account-metabox.php`

### API & Integracje
- [x] `web/app/mu-plugins/niepodzielni-core/api/9-bookero-sync.php`
- [x] `web/app/mu-plugins/niepodzielni-core/api/10-ajax-handlers.php`
- [x] `web/app/mu-plugins/niepodzielni-core/api/11-bookero-shortcodes.php`
- [x] `web/app/mu-plugins/niepodzielni-core/api/12-bookero-enqueue.php`
- [x] `web/app/mu-plugins/niepodzielni-core/api/13-bookero-worker-sync.php`
- [x] `web/app/mu-plugins/niepodzielni-core/api/14-bk-shared-calendar.php`
- [x] `web/app/mu-plugins/niepodzielni-core/api/15-matchmaker-shortcode.php`
- [x] `web/app/mu-plugins/niepodzielni-core/api/18-ai-sync.php`
- [x] `web/app/mu-plugins/niepodzielni-core/api/19-ai-endpoints.php`
- [x] `web/app/mu-plugins/niepodzielni-core/api/20-ai-feedback.php`
- [x] `web/app/mu-plugins/niepodzielni-core/api/22-media-helpers.php`
- [x] `web/app/mu-plugins/niepodzielni-core/api/30-panel-psycholog.php`

### Custom Post Types (CPT)
- [x] `web/app/mu-plugins/niepodzielni-core/cpt/14-cpt-psycholog.php`
- [x] `web/app/mu-plugins/niepodzielni-core/cpt/16-cpt-aktualnosci.php`
- [x] `web/app/mu-plugins/niepodzielni-core/cpt/17-cpt-wydarzenia.php`
- [x] `web/app/mu-plugins/niepodzielni-core/cpt/18-cpt-warsztaty.php`
- [x] `web/app/mu-plugins/niepodzielni-core/cpt/19-cpt-grupy-wsparcia.php`
- [x] `web/app/mu-plugins/niepodzielni-core/cpt/20-cpt-metaboxes.php`
- [x] `web/app/mu-plugins/niepodzielni-core/cpt/21-carbon-fields.php`

### Klasy PHP (Domain Logic)
- [x] `web/app/mu-plugins/niepodzielni-core/src/Bookero/AccountConfig.php`
- [x] `web/app/mu-plugins/niepodzielni-core/src/Bookero/BookeroApiClient.php`
- [x] `web/app/mu-plugins/niepodzielni-core/src/Bookero/BookeroApiException.php`
- [x] `web/app/mu-plugins/niepodzielni-core/src/Bookero/BookeroRateLimitException.php`
- [x] `web/app/mu-plugins/niepodzielni-core/src/Bookero/BookeroSyncService.php`
- [x] `web/app/mu-plugins/niepodzielni-core/src/Bookero/PsychologistRepository.php`
- [x] `web/app/mu-plugins/niepodzielni-core/src/Bookero/SharedCalendarService.php`
- [x] `web/app/mu-plugins/niepodzielni-core/src/Bookero/SyncResult.php`
- [x] `web/app/mu-plugins/niepodzielni-core/src/Bookero/WorkerRecord.php`

### Pozostałe Core
- [x] `web/app/mu-plugins/niepodzielni-core/misc/1-helpers.php`
- [x] `web/app/mu-plugins/niepodzielni-core/misc/db-cleanup-2026-04.sql`
- [x] `web/app/mu-plugins/niepodzielni-core.php`
- [x] `web/app/mu-plugins/bedrock-autoloader.php`
- [x] `web/app/mu-plugins/suppress-vendor-deprecations.php`

## 🎨 Motyw (`web/app/themes/niepodzielni-theme/`)

### Logic & Controllers
- [x] `web/app/themes/niepodzielni-theme/app/Providers/ThemeServiceProvider.php`
- [x] `web/app/themes/niepodzielni-theme/app/Services/EventsListingService.php`
- [x] `web/app/themes/niepodzielni-theme/app/Services/PsychologistListingService.php`
- [x] `web/app/themes/niepodzielni-theme/app/View/Composers/App.php`
- [x] `web/app/themes/niepodzielni-theme/app/View/Composers/Post.php`
- [x] `web/app/themes/niepodzielni-theme/app/View/Composers/TemplateAktualnosci.php`
- [x] `web/app/themes/niepodzielni-theme/app/View/Composers/TemplatePsyListing.php`
- [x] `web/app/themes/niepodzielni-theme/app/View/Composers/TemplatePsychoedukacja.php`
- [x] `web/app/themes/niepodzielni-theme/app/View/Composers/TemplateWarsztatyGrupy.php`
- [x] `web/app/themes/niepodzielni-theme/app/View/Composers/TemplateWydarzenia.php`
- [x] `web/app/themes/niepodzielni-theme/app/contact-form.php`
- [x] `web/app/themes/niepodzielni-theme/app/events-listing.php`
- [x] `web/app/themes/niepodzielni-theme/app/filters.php`
- [x] `web/app/themes/niepodzielni-theme/app/psy-listing.php`
- [x] `web/app/themes/niepodzielni-theme/app/seo.php`
- [x] `web/app/themes/niepodzielni-theme/app/setup.php`
- [x] `web/app/themes/niepodzielni-theme/app/shortcodes/shortcodes-bookero.php`
- [x] `web/app/themes/niepodzielni-theme/app/shortcodes/shortcodes-profile.php`
- [x] `web/app/themes/niepodzielni-theme/app/shortcodes/shortcodes-ui.php`

### JavaScript (`resources/js/`)
- [x] `web/app/themes/niepodzielni-theme/resources/js/app.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/bk-shared-calendar.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/bookero-form-listener.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/bookero-init.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/custom-accordion.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/editor.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/events-listing.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/matchmaker.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/mega-menu.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/panel.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/psy-listing-atomic.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/tabs.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/matchmaker/ScoringEngine.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/matchmaker/State.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/matchmaker/Templates.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/components/ai-chat.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/components/appointment-widget.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/components/bookero-date.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/components/dynamic-content.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/components/slider.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/utils/debounce.js`
- [x] `web/app/themes/niepodzielni-theme/resources/js/utils/listing.js`

### CSS / Styles (`resources/css/`)
- [x] (Wszystkie pliki w `resources/css/atoms/`, `molecules/`, `organisms/`, `templates/`)
- [x] `web/app/themes/niepodzielni-theme/resources/css/app.css`
- [x] `web/app/themes/niepodzielni-theme/resources/css/editor.css`

### Widoki Blade (`resources/views/`)
- [x] (Wszystkie pliki `.blade.php` w `resources/views/` w tym podfoldery components, forms, layouts, partials, sections)

## 🤖 AI Worker (`workers/ai-agent/`)
- [x] `workers/ai-agent/src/index.ts`
- [x] `workers/ai-agent/src/embed.ts`
- [x] `workers/ai-agent/src/cors.ts`
- [x] `workers/ai-agent/src/types.ts`
- [x] `workers/ai-agent/src/routes/chat.ts`
- [x] `workers/ai-agent/src/routes/feedback.ts`
- [x] `workers/ai-agent/src/routes/search.ts`
- [x] `workers/ai-agent/src/routes/sync.ts`
- [x] `workers/ai-agent/package.json`
- [x] `workers/ai-agent/tsconfig.json`
- [x] `workers/ai-agent/wrangler.toml`

## 🧪 Dokumentacja i Testy
- [x] `docs/ARCHITECTURE_AND_ONBOARDING.md`
- [x] `docs/BOOKERO-ANALYSIS.md`
- [x] `docs/REFACTORING_BASELINE.md`
- [x] `docs/project-files.md`
- [x] `docs/plan.md`
- [x] `tests/Pest.php`
- [x] `tests/Feature/ExampleTest.php`
- [x] `tests/Unit/BookeroSyncServiceTest.php`
- [x] `tests/Unit/SharedCalendarServiceTest.php`
- [x] `web/app/themes/niepodzielni-theme/tests/Unit/BookeroMatchingTest.php`
- [x] `web/app/themes/niepodzielni-theme/tests/Unit/HelpersTest.php`
- [x] `web/app/themes/niepodzielni-theme/tests/e2e/booking.spec.js`

## 📂 Przykłady i Inne
- [x] `example/bookeropl/bookero-plugin.php` (oraz powiązane widoki i biblioteki w tym folderze)
- [x] `example/dowod.har`, `example/localhost.har`, `example/niepodzielni.com.har`
- [x] `stubs/wordpress.php`
