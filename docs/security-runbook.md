# Security runbook — Niepodzielni-dev

Procedury operacyjne dla zdarzeń bezpieczeństwa i rutynowych zadań
hartujących.  Stosuj jako single-source-of-truth — NIE zapisuj sekretów w
ticketach Jira/Slack/itp.

---

## 1. Trellis Vault — szyfrowanie i rotacja

### 1.1. Stan wyjściowy

- `trellis/group_vars/{staging,production}/vault.yml` — **zaszyfrowane** AES256.
- `trellis/group_vars/{development,all}/vault.yml` — **plaintext** (dev defaults
  Trellis: `devpw`, `admin`, `example_dbpassword`, `smtp_password`).  Mają
  silne ostrzeżenie w pierwszej linii — patrz commit `security(stage1)`.
- `trellis/.vault_pass` — w `.gitignore`, **nigdy** nie commitujemy.

### 1.2. Pierwsze szyfrowanie dev/all

Wymaga lokalnie zainstalowanego `ansible-core` (≥2.10):

```bash
echo 'TWOJE_HASLO_VAULT' > trellis/.vault_pass
chmod 600 trellis/.vault_pass
ansible-vault encrypt trellis/group_vars/development/vault.yml --vault-password-file trellis/.vault_pass
ansible-vault encrypt trellis/group_vars/all/vault.yml          --vault-password-file trellis/.vault_pass
git add trellis/group_vars/development/vault.yml trellis/group_vars/all/vault.yml
git commit -m "ops: encrypt dev + all vault.yml"
```

Hasło `TWOJE_HASLO_VAULT` MUSI być identyczne z hasłem używanym do
`staging/vault.yml` i `production/vault.yml`, inaczej Ansible wysypie się
przy `deploy.yml`.

### 1.3. Rotacja hasła vault

```bash
ansible-vault rekey trellis/group_vars/development/vault.yml \
  --vault-password-file trellis/.vault_pass \
  --new-vault-password-file trellis/.vault_pass.new
# repeat for staging, production, all
mv trellis/.vault_pass.new trellis/.vault_pass
# update GitHub secret ANSIBLE_VAULT_PASS w repo settings
git add trellis/group_vars/*/vault.yml
git commit -m "ops: rotate ansible vault password"
```

### 1.4. Awaryjna procedura (np. wyciek hasła vault)

1. Natychmiast: zmień `ANSIBLE_VAULT_PASS` w GitHub repo secrets (placeholder
   uniemożliwia każdy kolejny deploy).
2. Zrotuj wszystkie sekrety zawarte w vaultach (DB, mail, API keys).
3. `ansible-vault rekey` (krok 1.3) na świeżych zaszyfrowanych vaultach.
4. Commituj zaszyfrowane vaulty.
5. Przywróć `ANSIBLE_VAULT_PASS` w GitHub na nowe hasło.

---

## 2. Worker secrets rotation (Cloudflare)

Sekrety używane przez `workers/ai-agent`:

| Sekret             | Gdzie                                 | Konsument                                        |
|--------------------|---------------------------------------|--------------------------------------------------|
| `WORKER_SECRET`    | `wrangler secret put` + WP `.env`     | WP→Worker `/sync` (`X-Worker-Secret`)            |
| `NP_AI_BOT_TOKEN`  | `wrangler secret put` + WP `.env`     | Front WP→Worker `/chat /search /feedback` (Bearer) |
| `WP_BOT_TOKEN`     | `wrangler secret put` + WP `.env`     | Worker→WP `/bot-feedback` (`X-API-Key`)           |
| `CF_AIG_TOKEN`     | `wrangler secret put` (tylko Worker)  | Worker→Cloudflare AI Gateway                     |

Procedura rotacji (per sekret):

```bash
# 1. Wygeneruj nowy sekret (32+ random bytes)
NEW=$(openssl rand -base64 32)

# 2. Zaktualizuj Worker (production env)
cd workers/ai-agent
echo "$NEW" | wrangler secret put NP_AI_BOT_TOKEN --env production

# 3. Zaktualizuj WP — przez ssh do prod hosta:
ssh root@185.201.113.217 "wp config set NP_AI_BOT_TOKEN '$NEW' \
  --path=/srv/www/new.niepodzielni.com/current/web/wp"

# 4. Walidacja: zalogowany na froncie chatbot powinien działać.
# 5. Stary sekret wygasa natychmiast po `wrangler deploy`.
```

`CF_AIG_TOKEN` rotuj przez Cloudflare Dashboard → AI Gateway → API tokens,
potem `wrangler secret put CF_AIG_TOKEN`.

---

## 3. Login throttle — zwolnienie zablokowanego IP

Login throttle (`13-login-throttle.php`) zapisuje lockout w transients.
Aby ręcznie zwolnić:

```bash
wp transient delete np_login_lockout_<ip-hash16>
# lub usuń wszystkie:
wp db query "DELETE FROM wp_options WHERE option_name LIKE '_transient_np_login_lockout_%'"
```

`<ip-hash16>` = `substr(sha256(ip), 0, 16)`.  IP klienta widoczny w nagłówku
`HTTP_CF_CONNECTING_IP` lub `wp_np_audit.ip` (audit log → tabela).

---

## 4. Audit log — przegląd i cleanup

### 4.1. Najczęstsze zapytania

```sql
-- Ostatnie 24h logowań (sukces + porażka)
SELECT ts, user_id, ip, action, target
FROM wp_np_audit
WHERE action IN ('login_success', 'login_failed', 'login_lockout')
  AND ts >= DATE_SUB(NOW(), INTERVAL 1 DAY)
ORDER BY id DESC;

-- Top 10 IP po login_failed w ciągu 7 dni
SELECT ip, COUNT(*) AS attempts
FROM wp_np_audit
WHERE action = 'login_failed' AND ts >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY ip ORDER BY attempts DESC LIMIT 10;
```

### 4.2. Retention

- Tabela `wp_np_audit` — wpisy >365 dni są kasowane przez cron
  `np_audit_purge_old` triggerowany z dziennego cron-a `np_purge_old_zgloszenia`
  (`15-retention-cron.php`).
- CPT `zgloszenie` — usuwane po 90 dniach (filtr
  `np_zgloszenia_retention_days` zmienia próg).

### 4.3. Eksport do SIEM

Audit log nie ma natywnego forwardowania.  Dla pełnego SIEM/Sentry pipeline:
patrz sekcja 7.

---

## 5. CSP — przejście z Report-Only do enforce

`16-security-headers.php` wystawia `Content-Security-Policy-Report-Only`
z domain whitelist (Turnstile, Bookero, jsdelivr, *.workers.dev).

### 5.1. Faza 1 — observe

Skonfiguruj endpoint odbierający raporty (np. https://report-uri.com lub
własny endpoint REST).  Dodaj `report-uri https://...` do dyrektywy CSP.

### 5.2. Faza 2 — enforce

Po 1-2 tygodniach bez nowych naruszeń, w `16-security-headers.php` zmień
nagłówek z `Content-Security-Policy-Report-Only` na `Content-Security-Policy`
zostawiając `report-uri` (raporty nadal się generują dla tracking).

---

## 6. HSTS preload — submission

`group_vars/production/wordpress_sites.yml` ma już:

```yaml
ssl:
  hsts_max_age: 63072000          # 2 lata
  hsts_include_subdomains: true
  hsts_preload: true
```

Po deployu produkcyjnym:

1. Zweryfikuj header: `curl -I https://niepodzielni.pl | grep -i strict-transport`
2. Złóż wniosek na https://hstspreload.org/?domain=niepodzielni.pl
3. ⚠ Po zatwierdzeniu (~6 mies.) odwołanie wymaga osobnej procedury i ~6 mies.
   propagacji w przeglądarkach — upewnij się że WSZYSTKIE subdomeny mają HTTPS.

---

## 7. Monitoring (Sentry / log forwarding)

Status: **opcja** — szkielet do zaimplementowania.

Plan integracji:

1. PHP: `composer require sentry/sdk`, hook `Sentry\init` w
   `niepodzielni-core.php` warunkowo na `defined('SENTRY_DSN')`.
2. Worker: `npm install @sentry/cloudflare`, instrumentacja w `index.ts`.
3. DSN-y trzymamy w `wrangler secret` (Worker) i `.env` (WP).

Audit log może być nadal lokalny — Sentry jest dla błędów aplikacyjnych,
audit log dla zdarzeń security.

---

## 8. `database-template.sql` — uwagi

Plik (~17 MB) zawiera strukturę WordPress + seed data Fundacji.  Przed
udostępnieniem template osobom spoza zespołu:

- `wp search-replace 'admin@niepodzielni.com.pl' 'admin@example.test'`
- Sprawdź `wp_users` — usuń realnych użytkowników poza pierwszym admin.
- Sprawdź `wp_comments` — usuń autorów, treści, IP.
- Sprawdź `wp_postmeta` (`_form_data`) — usuń lub zanonimizuj zgłoszenia.

Template w obecnej wersji **NIE jest** sanityzowany — przyjmujemy że
jest distribuowany tylko wewnątrz zespołu z umowami NDA.

---

## 9. Quick checklist przed merge na `main`

- [ ] `composer audit` — 0 high/critical
- [ ] `cd workers/ai-agent && npm audit --omit=dev --audit-level=high` — 0
- [ ] Gitleaks (CI security-checks.yml) — green
- [ ] Brak nowych endpointów REST z `permission_callback => '__return_true'`
      bez Turnstile lub Bearer
- [ ] Brak nowych endpointów Workera bez `requireBearer` / `requireHeaderSecret`
- [ ] Wszystkie sekrety wstrzykiwane przez env, nie w kodzie
- [ ] Vault dev/all — plaintext nadal akceptowalny tylko gdy wartości to
      pure-dev defaults; każde realne hasło/klucz wymaga `ansible-vault encrypt`
