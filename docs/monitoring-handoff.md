# Monitoring — handoff dla wykonawcy

Dokument operacyjny dla agenta (lub człowieka) który ma **dostęp do
produkcyjnego VPS, hasła Trellis vault, wrangler CLI zalogowanego do
Cloudflare i konta Admina**. Mam za zadanie: zmergować 3 PR-y i
zdeployować całość warstwy monitoringu.

Ten dokument zawiera:
- gotowy prompt do wklejenia (sekcja 1),
- listę PR-ów do merge w kolejności (sekcja 2),
- komendy krok po kroku (sekcje 3–6),
- definition of done (sekcja 7),
- procedurę rollbacku (sekcja 8).

Powiązane dokumenty:
- `docs/monitoring-runbook.md` — koncepcja i wybór narzędzi (jeden poziom wyżej, czemu Cockpit, Sentry, Better Stack).
- `docs/security-runbook.md` — vault, secrets, rotation.

---

## 1. Gotowy prompt (do wklejenia)

> Skopiuj poniższe między linie `BEGIN PROMPT` i `END PROMPT` do nowej sesji
> agenta z pełnym dostępem.

```
=== BEGIN PROMPT ===

Jesteś agentem SRE-class pracującym nad projektem Niepodzielni
(MixtureMarketing/Niepodzielni-dev). Strona psychoterapeutyczna na
WordPress Bedrock + Trellis (Ansible) + Cloudflare Worker (TypeScript).
Produkcja: new.niepodzielni.com (branch `main`).
Staging: dev.niepodzielni.com (branch `dev`).

Masz dostęp do:
1. Repo `MixtureMarketing/Niepodzielni-dev` (read+write, GitHub MCP).
2. SSH do produkcyjnego VPS `185.201.113.217` (user: root lub deploy).
3. Hasło Trellis vault (`ansible-vault edit ...` działa).
4. `wrangler` CLI zalogowany do konta Cloudflare Niepodzielni.
5. WP-CLI na produkcji (`/srv/www/new.niepodzielni.com/current/web/wp/`).

Czego NIE masz:
1. Kont SaaS (Sentry, Better Stack, Netdata Cloud) — Admin musi je
   utworzyć ręcznie w panelach. Twoja rola: dostarczyć Adminowi listę
   sekretów do wpisania i wgranie ich do vault/wrangler po otrzymaniu.
2. Konta Discord — Admin generuje 4 webhooki (instrukcje w
   `docs/monitoring-runbook.md` sekcja 2). Ty wpisujesz tylko jeden
   ("WordPress Audit") do vault.

Twoje zadanie:

1. Zweryfikuj że PR-y #11, #12, #14, #15 mają zielone CI i nie mają
   nieadresowanych review comments (CodeQL itp.). Jeśli któryś ma
   błędy — popraw zanim mergujesz.
2. Zmerguj PR-y w tej kolejności (każdy do `dev`, NIE `main`):
   #11 (runbook, dokument) → #12 (audit digest) → #14 (Query Monitor)
   → #15 (Sentry).
3. Dla każdego PR-a po merge wykonaj operator checklist z jego
   description:
   a. Edycja vault (`ansible-vault edit group_vars/{production,staging}/vault.yml`).
   b. Deploy (`ansible-playbook deploy.yml -e env=<env> -e site=<site>`).
   c. Smoke test wymieniony w PR description.
4. Po wszystkich merge: poinformuj Admina że może utworzyć status page
   na Better Stack i włączyć Cloudflare Web Analytics + Notifications.
   Te kroki nie wymagają Twojego dostępu.
5. Dodatkowo (poza zakresem PR-ów, opisane w runbooku 3.1+4.1):
   instalacja Cockpit i Netdata na produkcyjnym VPS przez SSH.
6. Definition of Done — sekcja 7 w `docs/monitoring-handoff.md`.
   Każdy z 9 punktów ma być sprawdzony i potwierdzony.

Zasady:
- NIE commituj sekretów do repo. Wszystkie webhook URL, DSN, tokeny
  trafiają TYLKO do vault (`ansible-vault edit`) lub `wrangler secret
  put`. Jeśli Admin wkleił sekret w Discord/Slack/email — natychmiast
  zaproponuj mu rotację, bo te kanały nie są bezpiecznym storage.
- NIE pushuj force do `dev` ani `main`.
- Zmiany Ansible playbook → najpierw na staging
  (`-e env=staging -e site=dev.niepodzielni.com`), dopiero potem
  produkcja po smoke test.
- Każdy deploy potwierdź smoke testem zanim przejdziesz do następnego.
- Discord webhook do alertów: kanał `#alerts-niepodzielni`. Z czterech
  webhooków (Sentry / Better Stack / Netdata / WordPress Audit) tylko
  WordPress Audit trafia do vault — pozostałe 3 Admin sam wkleja w
  panele SaaS po utworzeniu kont.

Po skończonej pracy: zaktualizuj `docs/monitoring-handoff.md` sekcja 7
(Definition of Done) zaznaczając ukończone punkty `[x]` i pchnij commit
do brancha `claude/monitoring-handoff-completion` (nowy, na bazie `dev`).

=== END PROMPT ===
```

---

## 2. PR-y do merge — w kolejności

| # | Tytuł | Branch | Co dotyka | Bazuje na |
|---|---|---|---|---|
| **#11** | docs: monitoring runbook | `claude/monitoring-runbook` | tylko `docs/` | `main` |
| **#12** | feat(monitoring): audit digest + lockout burst alert | `claude/monitoring-audit-digest` | mu-pluginy 17/19 + Trellis env | `dev` |
| **#14** | feat(monitoring): Query Monitor (DEV only) + QM_DISABLED | `claude/monitoring-query-monitor` | composer + production.php | `dev` |
| **#15** | feat(monitoring): Sentry — error tracking PHP + Worker | `claude/monitoring-sentry` | mu-plugin 18 + Sentry SDK + Trellis env + Worker | `dev` |

Plus PR `claude/monitoring-handoff` (ten dokument) — merge na końcu razem.

> **Uwaga o bazie**: PR #11 ma base `main` z pomyłki — przed merge zmień
> bazę na `dev` przez `gh pr edit 11 --base dev` albo w UI GitHub. PR #12,
> #14, #15 i niniejszy mają już base `dev`.

### 2.1. Pre-flight check (przed pierwszym merge)

```bash
# Pobierz najnowszy stan
git fetch origin

# Sprawdź CI każdego PR-a
for pr in 11 12 14 15; do
  gh pr checks $pr --repo MixtureMarketing/Niepodzielni-dev
done

# Sprawdź review comments (CodeQL alerts)
for pr in 11 12 14 15; do
  echo "=== PR #$pr ==="
  gh pr view $pr --repo MixtureMarketing/Niepodzielni-dev --json reviewDecision,statusCheckRollup
done
```

Wszystko musi być **zielone** zanim mergujesz. Jeśli któryś check
upadł — pobierz logi (`gh run view <run-id> --log-failed`) i napraw
przed merge.

### 2.2. Merge sekwencyjny

```bash
# 1. Runbook (najmniej ryzyka, dokument)
gh pr merge 11 --repo MixtureMarketing/Niepodzielni-dev --squash --delete-branch

# 2. Audit digest
gh pr merge 12 --repo MixtureMarketing/Niepodzielni-dev --squash --delete-branch
# → po merge wykonaj sekcję 3 (audit digest deployment)

# 3. Query Monitor
gh pr merge 14 --repo MixtureMarketing/Niepodzielni-dev --squash --delete-branch
# → po merge wykonaj sekcję 4 (Query Monitor deployment)

# 4. Sentry
gh pr merge 15 --repo MixtureMarketing/Niepodzielni-dev --squash --delete-branch
# → po merge wykonaj sekcję 5 (Sentry deployment)

# 5. Handoff doc (ten dokument)
gh pr merge <handoff-pr-number> --repo MixtureMarketing/Niepodzielni-dev --squash --delete-branch
```

> **Pomiędzy merge a deployem każdego PR-a**: zaktualizuj vault i
> uruchom Ansible. NIE merguj kolejnego dopóki poprzedni nie jest
> zweryfikowany na produkcji. Pojedynczy zepsuty merge jest łatwy do
> rollbacku — kilka naraz to chaos.

---

## 3. Deploy PR-A (#12) — audit digest

### 3.1. Wymagane sekrety

| Klucz | Skąd wziąć |
|---|---|
| `np_discord_webhook_url` | Admin generuje webhook „WordPress Audit" w Discord (`docs/monitoring-runbook.md` sekcja 2). Przekazuje URL TYLKO bezpiecznym kanałem (1Password / Bitwarden / w cztery oczy) — NIE Discord/email/Slack. |

### 3.2. Vault edit

```bash
cd trellis
ansible-vault edit group_vars/production/vault.yml
```

W edytorze, w sekcji `vault_wordpress_sites['new.niepodzielni.com']['env']`,
dopisać:

```yaml
np_discord_webhook_url: 'https://discord.com/api/webhooks/.../...'
```

Analogicznie dla staging (jeśli Admin chce alerty z dev — opcjonalnie):

```bash
ansible-vault edit group_vars/staging/vault.yml
```

W `vault_wordpress_sites['dev.niepodzielni.com']['env']` dopisać klucz
`np_discord_webhook_url` z URL drugiego webhooka (osobny kanał
`#alerts-niepodzielni-dev` zalecany) **lub** ten sam URL co prod (alerty
będą się mieszać).

### 3.3. Deploy

```bash
cd trellis
ansible-playbook deploy.yml -e env=production -e site=new.niepodzielni.com
```

### 3.4. Smoke test

```bash
ssh root@185.201.113.217
cd /srv/www/new.niepodzielni.com/current/web/wp
wp cron event run np_audit_digest_daily
```

Oczekiwane: w kanale `#alerts-niepodzielni` Discord pojawia się
wiadomość od bota „WordPress Audit" z monospace blokiem („Niepodzielni —
bezpieczeństwo wczoraj, Udane logowania: X, …").

Jeśli nie:
- `wp config get NP_DISCORD_WEBHOOK_URL` — czy jest URL z vault?
- `tail -100 /srv/www/new.niepodzielni.com/current/web/app/debug.log` —
  czy `wp_remote_post` nie loguje błędu?
- Jeśli `wp_remote_post` działa, ale Discord nie odbiera — sprawdź
  webhook w panelu Discord (czy nie został usunięty/zrotowany).

Test burst alert (opcjonalnie, ~5 min):

```bash
# Lokalnie z dowolnego IP, 21× nieudane próby loginu
for i in $(seq 1 21); do
  curl -s -X POST https://new.niepodzielni.com/wp-login.php \
    -d "log=admin&pwd=zlehasla$i" -o /dev/null
  sleep 2
done
```

Po przekroczeniu progu (20 lockoutów w 1h) Discord dostaje
🚨 Brute-force alert. Próg: `13-login-throttle.php` (5 nieudanych
prób/15min → lockout per IP) × 4-5 razy żeby uzbrojony licznik w
`19-lockout-burst-alert.php` przekroczył 20.

### 3.5. Verify

- [ ] Pierwszy digest dotarł do Discord (po manualnym `wp cron event run`).
- [ ] WP-Cron `np_audit_digest_daily` zaplanowany na jutro 7:00:
  ```bash
  wp cron event list --fields=hook,next_run_relative | grep np_audit_digest
  ```
- [ ] Email do `admin_email` też dotarł (kopia digest).

---

## 4. Deploy PR-C (#14) — Query Monitor (DEV only)

### 4.1. Wymagane sekrety

Brak — to czysta zmiana composer.

### 4.2. Deploy na staging (najpierw)

```bash
cd trellis
ansible-playbook deploy.yml -e env=staging -e site=dev.niepodzielni.com
```

Trellis na staging robi `composer install` z dev deps → Query Monitor
zaciągnięty.

### 4.3. Smoke test staging

- Otwórz `https://dev.niepodzielni.com/wp-admin` jako admin.
- W górnym pasku admina pojawia się panel QM z liczbą zapytań SQL,
  czasem, hookami.
- Jeśli nie: `wp plugin activate query-monitor` na dev:

  ```bash
  ssh root@185.201.113.217
  cd /srv/www/dev.niepodzielni.com/current/web/wp
  wp plugin activate query-monitor
  ```

### 4.4. Deploy na produkcję

```bash
cd trellis
ansible-playbook deploy.yml -e env=production -e site=new.niepodzielni.com
```

Trellis robi `composer install --no-dev` → QM **nie** wleci.

### 4.5. Smoke test prod (sanity check)

- Otwórz `https://new.niepodzielni.com/wp-admin` jako admin.
- Pasek admina **NIE** powinien mieć panelu QM.

Jeśli ma:
- `wp config get QM_DISABLED` na prod — powinno być `true`.
- Sprawdź `composer install --no-dev` log w deploy:
  ```bash
  ssh root@185.201.113.217 'cat /srv/www/new.niepodzielni.com/shared/deploy.log | grep composer'
  ```

### 4.6. Verify

- [ ] Query Monitor widoczny na dev dla zalogowanego admina.
- [ ] Query Monitor **niewidoczny** na prod dla zalogowanego admina.

---

## 5. Deploy PR-B (#15) — Sentry

### 5.1. Wymagane sekrety

| Klucz | Gdzie go zdobyć | Gdzie wpisać |
|---|---|---|
| `sentry_dsn` (production WP) | Sentry → Create Project → PHP (Generic) → `niepodzielni-wp` → Settings → Client Keys → DSN | vault `production/vault.yml` → `vault_wordpress_sites['new.niepodzielni.com']['env']['sentry_dsn']` |
| `sentry_dsn` (staging WP) | Drugi projekt: `niepodzielni-wp-dev` → DSN | vault `staging/vault.yml` → `vault_wordpress_sites['dev.niepodzielni.com']['env']['sentry_dsn']` |
| `np_release` (production) | SHA `git rev-parse HEAD` lub semver typu `1.0.0` | vault production — klucz `np_release` |
| `np_release` (staging) | jw. lub `'staging'` | vault staging — klucz `np_release` |
| `SENTRY_DSN` (Worker production) | Trzeci projekt: `niepodzielni-worker` → Cloudflare Workers platform → DSN | `wrangler secret put SENTRY_DSN --env production` |
| `SENTRY_DSN` (Worker staging) | Czwarty projekt: `niepodzielni-worker-dev` → DSN | `wrangler secret put SENTRY_DSN --env staging` |
| `SENTRY_ENV` (Worker production) | string literal | `wrangler secret put SENTRY_ENV --env production` → wpisz `production` |
| `SENTRY_ENV` (Worker staging) | string literal | `wrangler secret put SENTRY_ENV --env staging` → wpisz `staging` |

> **Dlaczego 4 projekty Sentry, a nie 1 z 4 environments?** Sentry
> free tier daje 5k events/mies **per projekt**. Cztery projekty = 20k
> events/mies wspólnie. Jeden projekt = 5k dla wszystkich. Wybór:
> 4 projekty (więcej quoty + łatwiej ograniczyć alerty per stack).

> **Zadanie dla Admina**: utworzyć 4 projekty w Sentry i przekazać Ci
> 4 DSN-y bezpiecznym kanałem (1Password / Bitwarden). Nie email,
> Slack, Discord.

### 5.2. Vault edit (production + staging WP)

```bash
cd trellis
ansible-vault edit group_vars/production/vault.yml
```

Dopisać w `vault_wordpress_sites['new.niepodzielni.com']['env']`:

```yaml
sentry_dsn: 'https://abc@sentry.io/123'
np_release: 'main'    # albo SHA z git rev-parse, albo semver
```

Analogicznie dla `dev.niepodzielni.com` w `vault_wordpress_sites`
(klucze `sentry_dsn`, `np_release: 'staging'`).

### 5.3. Worker secrets (production + staging)

```bash
cd workers/ai-agent

wrangler secret put SENTRY_DSN --env production
# → wkleić DSN z projektu niepodzielni-worker

wrangler secret put SENTRY_ENV --env production
# → wpisać: production

wrangler secret put SENTRY_DSN --env staging
# → wkleić DSN z projektu niepodzielni-worker-dev

wrangler secret put SENTRY_ENV --env staging
# → wpisać: staging
```

### 5.4. Konfiguracja Sentry → Discord (Admin samodzielnie)

Dla każdego z 4 projektów Sentry powtórzyć:

1. Sentry → Project → Settings → **Integrations → Webhooks (Legacy)** → Add.
2. URL: webhook „Sentry" z Discord (ten zrotowany po wcześniejszej ekspozycji)
   **+ sufix `/slack`** na końcu (Discord rozumie format Slack).
3. Project → Alerts → Create Alert Rule:
   - Trigger: „A new issue is created" (tylko nowe, nie powtórki).
   - Action: „Send a notification via Webhook" → wybierz Discord.

Bonus oszczędność quoty: Sentry → Settings → Inbound Filters → włącz
„Filter Out Errors From Web Crawlers" + „Filter Out Health Check
Failures".

### 5.5. Deploy WP

```bash
cd trellis

# Najpierw staging
ansible-playbook deploy.yml -e env=staging -e site=dev.niepodzielni.com

# Smoke test PII na staging — patrz 5.7
# Jeśli OK:
ansible-playbook deploy.yml -e env=production -e site=new.niepodzielni.com
```

### 5.6. Deploy Worker

```bash
cd workers/ai-agent
npm install
npm run deploy -- --env staging
# Smoke test, jeśli OK:
npm run deploy -- --env production
```

### 5.7. Smoke test PII (KRYTYCZNE — przed produkcją)

To jest jedyny test który **musi** zostać wykonany przed prod
rolloutem. Sprawdza, że `before_send`/`beforeSend` wycina dane wrażliwe
zanim event trafi do Sentry.

**PHP** (test na staging):

```bash
# Wymuś walidację formularza kontaktowego z wrażliwymi danymi
curl -X POST https://dev.niepodzielni.com/wp-json/niepodzielni/v1/forms/contact/submit \
  -H 'Content-Type: application/json' \
  -d '{"email": "test@example.com", "telefon": "+48123456789", "tresc": "wrażliwy tekst pacjenta"}'
```

→ jeśli walidacja rzuci wyjątek (np. pominięte wymagane pole), Sentry
dostaje event. Sprawdź w Sentry:

- Issues → kliknij nowy event → tab **„Additional data"**.
- `request.data` powinno być **`[Filtered]`** lub w ogóle nieobecne.
- `request.cookies` analogicznie.
- Jeśli widać `email`/`telefon`/`tresc` — `before_send` nie zadziałał.
  **Nie idziesz na produkcję**, fix przed dalszym deployem
  (najczęściej: Sentry SDK 4.x zmieniło API, sprawdź źródło filtru
  w `web/app/mu-plugins/niepodzielni-core/admin/18-sentry-init.php`).

**Worker** (test na staging):

```bash
curl -X POST https://niepodzielni-ai-agent-staging.<ACCOUNT>.workers.dev/chat \
  -H 'Authorization: Bearer <NP_AI_BOT_TOKEN_DEV>' \
  -d 'malformed-json-not-valid'
```

→ Worker rzuci błąd parsowania JSON, Sentry dostaje event w projekcie
`niepodzielni-worker-dev`. Sprawdź `request.data` analogicznie.

### 5.8. Verify

- [ ] Po deploy WP: w produkcyjnym Sentry pojawiły się eventy (lub
  cisza — czysty stan).
- [ ] PII filter działa: `request.data` puste/`[Filtered]` w
  testowym evencie.
- [ ] Worker errors trafiają do osobnego projektu `niepodzielni-worker`.
- [ ] Discord webhook „Sentry" odbiera nowy event po wymuszeniu
  testowego błędu (raz, nie spam).

---

## 6. Po wszystkich merge — kroki niezależne (poza zakresem PR-ów)

Te zadania nie są w żadnym PR-ze — są opisane w
`docs/monitoring-runbook.md`. Wykonujesz je po skończonym merge dla
kompletności faza-1+2.

### 6.1. Cockpit na VPS (~30 min, runbook 3.1)

```bash
ssh root@185.201.113.217

apt update
apt install -y cockpit cockpit-pcp
systemctl enable --now cockpit.socket

# Sprawdź swój IP z laptopa Admina:  curl ifconfig.me
ufw allow from <ADMIN_IP> to any port 9090 proto tcp
ufw reload
```

Admin testuje `https://new.niepodzielni.com:9090` (self-signed cert,
zaakceptuj raz). Login = SSH credentials.

- [ ] Cockpit dostępny dla Admina, niedostępny z innych IP.

### 6.2. Netdata Cloud (~30 min, runbook 4.1)

Admin loguje się na https://app.netdata.cloud → New Space → Add Node →
Linux → kopiuje pełen kickstart command z claim token. Przekazuje Ci
komendę. Ty:

```bash
ssh root@185.201.113.217
wget -O /tmp/netdata-kickstart.sh https://my-netdata.io/kickstart.sh
sh /tmp/netdata-kickstart.sh --stable-channel \
  --claim-token <TOKEN> --claim-rooms <ROOM_ID>
```

Po claim Admin konfiguruje Discord notifications w app.netdata.cloud
(webhook „Netdata" z runbooka).

- [ ] Netdata widzi node niepodzielni-prod, monitoruje Redis/MySQL/nginx.

### 6.3. Better Stack uptime (~30 min, Admin samodzielnie)

Admin samodzielnie w betterstack.com. Zadanie poza Twoim zakresem,
ale weryfikuj że status page działa po:
- Admin tworzy 7 monitorów (lista w runbooku 4.2)
- Admin ustawia CNAME `status.niepodzielni.pl` → BetterStack target
  (DNS w Cloudflare → DNS only, gray cloud)

- [ ] `https://status.niepodzielni.pl` zwraca status page Better Stack.
- [ ] Better Stack alerty trafiają na Discord webhook „Better Stack".

### 6.4. Cloudflare Web Analytics (~5 min, Admin samodzielnie)

Admin w `dash.cloudflare.com` → Niepodzielni zone → Analytics & Logs →
Web Analytics → Enable + Web Vitals.

- [ ] Po 24h dane ruchu są widoczne w panelu CF.

### 6.5. Cloudflare Notifications (Admin samodzielnie)

Admin w `dash.cloudflare.com` → Notifications → Add → Origin Error
Rate / DDoS / Workers errors → Webhook → URL z webhook „Sentry" + `/slack`
(lub osobny webhook „Cloudflare" w Discord).

- [ ] CF wysyła alerty origin errors > 5% przez 10 min na Discord.

---

## 7. Definition of Done

Po wszystkim Admin powinien móc odpowiedzieć **TAK** na każde z poniżej.
Zaznacz `[x]` w tym pliku gdy punkt jest zweryfikowany na produkcji.

- [ ] PR #11 (runbook) zmergowany do `dev`.
- [ ] PR #12 (audit digest) zmergowany; `wp cron event run
      np_audit_digest_daily` produkuje wiadomość Discord + email.
- [ ] PR #14 (Query Monitor) zmergowany; QM widoczny na dev,
      niewidoczny na prod.
- [ ] PR #15 (Sentry) zmergowany; Sentry odbiera testowy event,
      `request.data` jest `[Filtered]`.
- [ ] Cockpit `https://new.niepodzielni.com:9090` działa, dostęp
      ograniczony do IP Admina przez ufw.
- [ ] Netdata Cloud widzi node, Redis hit ratio + MySQL slow queries
      monitorowane.
- [ ] Better Stack 7 monitorów uptime aktywne, status page na
      `status.niepodzielni.pl` zwraca zielony stan.
- [ ] Cloudflare Web Analytics aktywne, Core Web Vitals zbierane.
- [ ] CF Notifications + Sentry + Better Stack + Netdata wysyłają
      alerty na właściwe webhooki Discord (4 osobne boty: Sentry / Better
      Stack / Netdata / WordPress Audit).
- [ ] Admin wie gdzie zaglądać (Discord = alerty codziennie, status
      page = przegląd tygodniowy, Sentry = przegląd miesięczny).

---

## 8. Rollback plan

Każdy PR jest niezależny. Jeśli któryś po deployu powoduje problem:

```bash
# 1. Revert merge na GitHub
gh pr revert <PR_NUMBER> --repo MixtureMarketing/Niepodzielni-dev

# 2. Re-deploy żeby cofnąć efekt na serwerze
cd trellis
ansible-playbook deploy.yml -e env=production -e site=new.niepodzielni.com

# 3. (Sentry/Worker only) Cofnij Worker
cd workers/ai-agent
git checkout <last-good-sha> -- src/index.ts
wrangler deploy --env production
git checkout - -- src/index.ts
```

Najczęstsze problemy i rozwiązania:

| Symptom | Przyczyna | Fix |
|---|---|---|
| Audit digest nie idzie do Discord | webhook URL nie wczytany do `.env` po deploy | `wp config get NP_DISCORD_WEBHOOK_URL` na prod; jeśli puste — vault edit + re-deploy |
| Sentry `request.data` zawiera PII | SDK API zmienił nazwy pól | Zaktualizuj `before_send` w `18-sentry-init.php` używając aktualnej dokumentacji Sentry PHP 4.x |
| Worker po deploy zwraca 500 dla wszystkich requestów | `Sentry.withSentry` źle owinięty | Rollback Worker (`wrangler rollback --env production`) i debug lokalnie |
| Query Monitor wyciekł na produkcję | `composer install --no-dev` nie zadziałał albo plugin został aktywowany ręcznie | `wp plugin deactivate query-monitor` + `wp config set QM_DISABLED true --raw` (już jest, ale potwierdź) + ponowny deploy |

---

## 9. Pre-flight checklist (zanim odpalisz)

Przed pierwszym `gh pr merge`:

- [ ] Mam SSH do `185.201.113.217` i mogę zalogować się jako root/deploy.
- [ ] Mam hasło Trellis vault — `ansible-vault edit
      group_vars/production/vault.yml` otwiera plaintext.
- [ ] Mam `wrangler` CLI zalogowany — `wrangler whoami` zwraca konto
      Cloudflare Niepodzielni.
- [ ] Admin przekazał (bezpiecznym kanałem):
  - URL webhooka „WordPress Audit" Discord.
  - 4 DSN-y Sentry (jeśli już utworzył projekty).
  - lub powiadomienie że to robi po Twoim merge.
- [ ] Wszystkie 4 PR-y mają zielone CI.
- [ ] Wszystkie review comments rozwiązane (CodeQL, etc.).

Jeśli któryś punkt nie jest spełniony — **nie zaczynaj merge**, najpierw
ureguluj braki.

---

## 10. Po zakończeniu

1. Zaktualizuj sekcję 7 (Definition of Done) — zaznacz wszystkie
   ukończone punkty `[x]`.
2. Commit zmiany na branchu `claude/monitoring-handoff-completion`
   (nowy, na bazie `dev`).
3. Push, otwórz PR „docs(monitoring): handoff completed", merge.
4. Powiadom Admina że monitoring jest live i pokaż mu:
   - Discord `#alerts-niepodzielni` — jak wyglądają alerty.
   - https://status.niepodzielni.pl — co widzi publika.
   - Sentry → Issues — jak wygląda lista błędów (puste = OK).
   - Cockpit https://...:9090 — graficzny panel zamiast SSH.

Po tym wszystkim Admin ma działający stack monitoringu. Twoja rola
wykonana.
