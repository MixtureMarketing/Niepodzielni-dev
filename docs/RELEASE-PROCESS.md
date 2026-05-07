# Release process

Dokument operacyjny: jak przeprowadzić zmianę od PR-a → przez staging na VPS → na produkcję.

## Architektura

```
                          ┌──────────────────────────────────────────────┐
                          │  185.201.113.217  (jeden VPS produkcyjny)   │
                          │                                              │
   feature branch ───PR───┼─► dev branch ──────► dev.niepodzielni.com   │
                          │                       (cache off, branch dev)│
                          │                                              │
   dev (po akceptacji) ──┼─► main branch ─────► niepodzielni.pl         │
                          │                       (cache on, branch main)│
                          └──────────────────────────────────────────────┘

   Cloudflare Workers:
     niepodzielni-ai-agent           ← deploy.yml (push main)
     niepodzielni-ai-agent-staging   ← dev-deploy.yml (push dev)
```

**Trellis multi-site**: oba vhosty siedzą w `trellis/group_vars/production/wordpress_sites.yml`
i są deployowane przez `ansible-playbook deploy.yml -e env=production -e site=<host>`.
Nie ma osobnego env staging — to ten sam serwer, ten sam vault, ten sam SSH key.

## Jednorazowe przygotowanie infrastruktury

Przed pierwszym deployem na `dev.niepodzielni.com`:

1. **DNS** — A-rekord `dev.niepodzielni.com` → `185.201.113.217` (Cloudflare proxy off,
   żeby Let's Encrypt mógł wystawić cert).
2. **Vault** — dorzucić sekrety dla nowego site:
   ```bash
   cd trellis
   ansible-vault edit group_vars/production/vault.yml
   ```
   Dodać klucz `vault_wordpress_sites['dev.niepodzielni.com']` z analogiczną strukturą
   jak `new.niepodzielni.com`: `env.{db_password, auth_key, ..., np_ai_bot_token, ...}`.
3. **Provisioning** (z lokalnego komputera, jeden raz):
   ```bash
   cd trellis
   ansible-playbook server.yml -e env=production
   ```
   Trellis idempotentnie dorzuci nginx vhost dla `dev.niepodzielni.com`, certyfikat LE,
   MySQL DB+user, dir layout `/srv/www/dev.niepodzielni.com/{current,releases,shared}`.
   **Bez utraty istniejącej produkcji.**
4. **GitHub secrets** — w Settings → Secrets and variables → Actions:
   - `CLOUDFLARE_API_TOKEN` — token z Workers Scripts:Edit (do worker-deploy job)
   - `CLOUDFLARE_ACCOUNT_ID` — ID konta Cloudflare
   - (już są: `DEPLOY_SSH_KEY`, `ANSIBLE_VAULT_PASS`)
5. **GitHub branch protection**:
   - `main`: require CI green + 1 review + linear history
   - `dev`: require CI green
6. **Worker secrets + KV** (po merge PR #7 — gdy pojawi się `[env.staging]`/`[env.production]`):
   ```bash
   cd workers/ai-agent
   wrangler kv:namespace create RATE_LIMIT --env production
   wrangler kv:namespace create RATE_LIMIT --env staging
   wrangler secret put NP_AI_BOT_TOKEN --env production
   wrangler secret put NP_AI_BOT_TOKEN --env staging
   ```
   ID-y KV z outputu wkleić do `wrangler.toml`. Token taki sam jak `vault_np_ai_bot_token`
   w Trellis vault — WP musi go znać żeby autoryzować się do Workera.
7. **Tymczasowo (do merge PR #7)**: `dev-deploy.yml` deployuje Worker z override
   `--name niepodzielni-ai-agent-staging`. Po merge #7 zmienić na `--env staging`.

## Per-PR workflow (Claude Code w VS Code)

```bash
# 1. Checkout PR-a
gh pr checkout <NR>

# 2. Rebase na aktualny dev (jeśli inne PR-y już zmergowane)
git fetch origin
git rebase origin/dev

# 3. Lokalne testy w Dockerze
docker compose up -d --build
docker compose exec php composer lint
docker compose exec php composer exec phpstan analyse
docker compose exec php composer test
(cd workers/ai-agent && npm ci --ignore-scripts && npx tsc --noEmit)
(cd web/app/themes/niepodzielni-theme && npm ci && npm test && npm run build)

# 4. Smoke golden path z opisu PR-a (curl, wp cli, browser)

# 5. Push (jeśli rebase) i poczekaj na zielone CI
git push --force-with-lease

# 6. Re-target na dev jeśli PR jeszcze celuje w main
gh pr edit <NR> --base dev

# 7. Squash merge do dev (po review)
gh pr merge <NR> --squash --delete-branch

# 8. dev-deploy.yml automatycznie deployuje na dev.niepodzielni.com
#    + niepodzielni-ai-agent-staging.workers.dev

# 9. Smoke test publiczny na https://dev.niepodzielni.com
```

## Kolejność mergowania 4 otwartych PR-ów

| # | PR | Powód kolejności |
|---|----|-------------------|
| 1 | #7 security hardening | Breaking changes (auth Workera, KV rate-limit, CSP) → fundamenty muszą być pierwsze |
| 2 | #8 refactor helpery | Mały rebase na #7 (~19 markerów); single source of truth dla pozostałych |
| 3 | #5 SEO + WCAG | Najgorszy rebase (~94 markery); CSP × inline JSON-LD wymaga `'unsafe-inline'` lub hash |
| 4 | #6 Crisis Hub + Calendar | Największy zakres; nowe deps (Stripe, dompdf) — `composer install` na deployu |

## Release na produkcję (dev → main)

Po akceptacji wszystkich 4 PR-ów na `https://dev.niepodzielni.com`:

```bash
# 1. PR dev → main (jako draft)
gh pr create --base main --head dev --draft \
   --title "release vX.Y.Z" \
   --body "Zawiera PR #7, #8, #5, #6 — przetestowane na dev.niepodzielni.com"

# 2. Review + ready-for-review + merge commit (NIE squash — zachowaj historię 4 PR-ów)
gh pr merge <release-PR> --merge

# 3. deploy.yml automatycznie:
#    - rsync theme build → /srv/www/new.niepodzielni.com/shared/theme-build/
#    - ansible-playbook trellis/deploy.yml -e env=production -e site=new.niepodzielni.com
#    - worker-deploy: wrangler deploy (niepodzielni-ai-agent)

# 4. Post-deploy weryfikacja
curl -I https://niepodzielni.pl/ | grep -E 'Strict-Transport|Content-Security-Policy|Referrer-Policy'
curl https://niepodzielni-ai-agent.workers.dev/debug-llm                 # 404
ssh root@185.201.113.217 'wp --path=/srv/www/new.niepodzielni.com/current/web/wp cron event list'
```

WP Admin: Tools → Audit Log non-empty + `/wydarzenia/?view=calendar` renderuje grid.

## Rollback

```bash
# WP prod
ssh root@185.201.113.217
cd /srv/www/new.niepodzielni.com
ls -lt releases/ | head -5
ln -sfn releases/<previous_timestamp> current
sudo service php8.4-fpm reload

# albo z lokalnego komputera (Trellis)
cd trellis
ansible-playbook rollback.yml -e env=production -e site=new.niepodzielni.com

# Worker
cd workers/ai-agent
npx wrangler rollback                       # produkcja (niepodzielni-ai-agent)
npx wrangler rollback --name niepodzielni-ai-agent-staging  # staging
```

## Lokalne środowisko (Docker)

```bash
cp .env.example .env                                  # uzupełnić salts: https://roots.io/salts.html
docker compose up -d --build                          # http://localhost:8000
docker compose exec mysql mysqladmin ping -uroot -p$MYSQL_ROOT_PASSWORD
docker compose exec redis redis-cli PING              # PONG
docker compose exec php wp --allow-root core is-installed
```

`cloudflared` w `docker-compose.yml` jest opcjonalny — od czasu uruchomienia
`dev.niepodzielni.com` na VPS-ie nie jest podstawowym URL-em dev.
