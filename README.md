# Niepodzielni — WordPress

Strona internetowa [Fundacji Niepodzielni](https://niepodzielni.com) oparta na Bedrock + Sage.

## Stack

| Warstwa | Technologia |
|---|---|
| CMS | WordPress (Bedrock) |
| Motyw | Sage (Acorn / Laravel Blade) |
| Frontend | Vite + Tailwind CSS |
| Booking | Bookero API v2 |
| Pola meta | Carbon Fields |
| Testy | Pest · PHPStan · Pint · Vitest |
| Deploy | GitHub Actions → rsync → lh.pl |
| Hosting | lh.pl (PHP 8.4, open_basedir = `web/`) |

## Wymagania lokalne

- PHP 8.3+
- Composer 2
- Node.js 20+
- Docker (opcjonalnie, do bazy danych)

## Szybki start

```bash
git clone https://github.com/MixtureMarketing/Niepodzielni-dev.git
cd Niepodzielni-dev

# Zależności PHP
composer install

# Zależności JS + build
cd web/app/themes/niepodzielni-theme
npm ci && npm run dev

# Konfiguracja środowiska
cp .env.example .env   # uzupełnij DB_*, WP_HOME, klucze Bookero
```

Serwer deweloperski: uruchom lokalny stos (np. Herd / Laravel Sail / Docker) z DocumentRoot w `web/`.

## Zmienne środowiskowe

Plik `.env` w katalogu `web/` (lub root przy lokalnym dev). Wymagane klucze:

```
WP_ENV=development
WP_HOME=https://twoja-domena.local
DB_NAME=
DB_USER=
DB_PASSWORD=
DB_HOST=

NP_BOOKERO_CAL_ID_PELNY=
NP_BOOKERO_CAL_ID_NISKO=
NP_BOOKERO_API_KEY_PELNY=
NP_BOOKERO_API_KEY_NISKO=
```

## Struktura katalogów

```
├── config/               # Konfiguracja WordPress (application.php)
├── vendor/               # Composer (nie w git)
└── web/                  # DocumentRoot
    ├── app/
    │   ├── mu-plugins/niepodzielni-core/   # Logika biznesowa, Bookero, CF
    │   ├── plugins/                         # Wtyczki WP (nie w git)
    │   ├── themes/niepodzielni-theme/       # Motyw Sage
    │   │   ├── app/                         # PHP (Composers, shortcodes, setup)
    │   │   ├── resources/css|js|views/      # Frontend sources
    │   │   └── public/build/                # Vite output (nie w git)
    │   └── uploads/                         # Media (S3 via media.niepodzielni.com)
    ├── wp/                                  # WordPress core (nie w git)
    └── wp-config.php
```

## CI/CD

Pipeline uruchamia się automatycznie przy push do `main`:

1. **static-analysis** — Pint + PHPStan (level 8)
2. **backend-tests** — Pest (PHP 8.3 + 8.4)
3. **frontend-build** — Vite build → artefakt GitHub
4. **frontend-unit** — Vitest
5. **deploy** — rsync do lh.pl (tylko `main`)

### Sekrety GitHub Actions

| Sekret | Opis |
|---|---|
| `PROD_SSH_PRIVATE_KEY` | Klucz ed25519 do serwera lh.pl |
| `PROD_ENV` | Zawartość pliku `.env` dla produkcji |

### Specyfika lh.pl

Hosting lh.pl blokuje dostęp PHP poza `web/` (`open_basedir`). Workflow automatycznie:
- kopiuje `vendor/` → `web/vendor/`
- kopiuje `config/` → `web/config/`
- łata ścieżki w autoloaderze Composera
- kopiuje assety Carbon Fields → `web/carbon-fields/`

## Testy lokalne

```bash
# PHP
vendor/bin/pest
vendor/bin/phpstan analyse
vendor/bin/pint --test

# JS (w katalogu motywu)
npm run test
npm run build
```

## Cron

WP-Cron jest wyłączony (`DISABLE_WP_CRON=true`). Na serwerze lh.pl skonfiguruj cron:

```
* * * * *  curl -s https://twoja-domena.pl/wp-cron.php?doing_wp_cron
```
