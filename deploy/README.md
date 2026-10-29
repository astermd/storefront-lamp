# AsterMD Storefront Deployment Guide

This directory contains sample configurations for deploying the AsterMD storefront on nginx or Apache. Both configurations follow the same security model: docroot is `public/`, with automatic rewriting to `index.php`, deny rules for internal paths, and immutable caching for hashed build assets.

## Installation Steps

1. **Unpack the application** into your desired location (e.g., `/var/www/storefront/`).

2. **Install PHP dependencies**:
   ```bash
   composer install --no-dev
   ```

3. **Set up the environment**:
   ```bash
   cp .env.example .env
   ```
   Then **delete the `APP_ENV` line from the copy** — or change it to `APP_ENV=production` — before you configure anything else, and fill in the database and EMR values described under [The environment file](#the-environment-file) below.

   Deleting that line is not housekeeping. **Any `APP_ENV` value other than `production` puts a sitewide `noindex` over the whole storefront**, and it overrides every per-route rule (`[24.7]`). Left wrong on a live store, that store is invisible to every search engine and nothing on the page says so. An absent `APP_ENV` defaults to `production`, so removing the line is a safer edit than trusting whatever value the copy came with.

4. **Run database migrations**:
   ```bash
   bin/console db:migrate
   ```

5. **Configure your web server** to point the virtual host's document root at the `public/` directory (see nginx or Apache examples below).

6. **Ensure proper permissions**: the web server user needs **write** access only on `storage/`. `config/` must be **read-only** to the web server user — it's only ever written by `theme:sync`, which runs via the CLI as a trusted operator/deploy user, never by a web request.

## The environment file

`.env` holds the credentials and the per-deployment values. `config/*.php` holds behaviour and is meant to be read rather than copied. `bin/console config:validate` checks what it can of both; the things below are what it cannot tell you.

### `APP_ENV` decides whether the store can be indexed

There is no enumeration of this value in code: `production` and `test` each mean something specific, and any other value behaves as a development environment. What it changes:

| `APP_ENV` | `/robots.txt` | `/sitemap.xml` | every page's meta robots | Twig cache | error details in the response |
|---|---|---|---|---|---|
| absent, or `production` | the funnel prefixes only | the marketing paths | absent | compiled to `storage/cache/twig`, `auto_reload` off | hidden |
| any other value (`dev`, `staging`, …) | blanket `Disallow: /` | empty `<urlset>` | `noindex, nofollow` | off, recompiled every request | shown |
| `test` | as above | as above | as above | off | shown |

`test` additionally turns EMR analytics sessions off (`app.session.analytics`), so nothing is minted, no visitor gets an `amd_session` cookie, and a resume link has nothing to resume. It is the value the automated suite runs under, and it is never a value to deploy.

Two consequences worth stating outright:

- **The absent value is the safe one.** `config/app.php` reads `$env['APP_ENV'] ?? 'production'`, so an `.env` with no `APP_ENV` line at all is a production deployment. That inverts the usual instinct: deleting the setting is safer than carrying a copied one forward.
- **The indexing switch is a master switch** (`[24.7]`). It is not one input among several: while it is on, no per-route rule, sitemap entry or page-level setting can make anything indexable. A staging site therefore cannot leak into an index through one mis-set page — and a production site with a stray `APP_ENV=staging` cannot get *into* one however correct the rest of its SEO configuration is.

The right way to check a deployment rather than trust it:

```bash
curl -s https://example.com/robots.txt      # a bare "Disallow: /" means APP_ENV is not production
curl -s https://example.com/sitemap.xml     # an empty <urlset> means the same
```

### The values you cannot invent

Four of the values in `.env` are issued to your deployment and cannot be generated locally. Three of them fail loudly when they are wrong — an authentication error, or a command that refuses to run. The fourth fails silently, and is the one to get right first time.

| Variable | Where it comes from | Shape |
|---|---|---|
| `ASTERMD_CLIENT_ID` | issued with the API credential pair, per organisation | an opaque identifier, an `@`, and the API host it was issued for — e.g. `<id>@health.api.example.net`, the host half being the same value as `ASTERMD_BASE_HOST` |
| `ASTERMD_CLIENT_SECRET` | issued alongside the client id | opaque |
| `ASTERMD_CHANNEL_ID` | the EMR's own record for the sales channel this storefront sells | a 24-character hexadecimal id |
| `AMD_TRACKING_KEY` | **issued** — it is the key the affiliate-link service encrypts with, not a key you choose | exactly 32 hexadecimal characters (a 16-byte AES-128 key) |

`AMD_TRACKING_KEY` is the one that punishes a guess. A locally generated 32 hex characters is a perfectly valid key that simply is not *the* key: every `_amd` payload fails to decrypt, the storefront falls back silently to whatever plain query parameters are present (`[5.3]`), the page renders normally, and the only trace is an `amd_decrypt_failed` line in `storage/logs/app.log`. Affiliate traffic is then attributed to nothing and nobody is told. The key must come from whoever issues the links; see [Rotating `AMD_TRACKING_KEY`](#sessions--attribution) below for changing it once you have it.

Leaving `AMD_TRACKING_KEY` empty is a legitimate configuration — encrypted payloads are then never read and plain parameters are used — and it is the right state for a deployment that has no affiliate links yet. `ASTERMD_CLIENT_ID` and `ASTERMD_CLIENT_SECRET` are not optional: a blank pair makes every EMR call throw at client construction. `ASTERMD_CHANNEL_ID` is what `theme:sync` and `emr:ping` read; without it they refuse to run rather than guess a channel.

### `GOOGLE_MAPS_API_KEY` costs something to fill in

Blank is the shipped default, blank is safe, and blank is handled: the receipt simply renders no map, and checkout's address suggestions come from the EMR through the storefront. Filling it in moves those suggestions to Google Places in the buyer's browser and adds a delivery map to the receipt. That has two consequences that are not obvious from the variable's name.

- **It sends the buyer's address to Google.** What the buyer types into checkout's address field goes to Google Places as they type, and the receipt page embeds a Google Maps iframe whose query parameter *is* the delivery address. That is a data-sharing decision, not a styling one, and it is one to make deliberately and to reflect in the privacy copy at `/privacy/`.
- **The key is rendered into page source.** It is a template global, so it is delivered as plain text in the HTML of every page that uses it and is readable by anyone who can view that page — there is no server-side proxying of the request. Restrict it before you use it, by HTTP referrer to your own domain and by API to the Maps JavaScript API, the Places API (New) and the Maps Embed API; an unrestricted key in page source is billable by anyone who reads it. A key restricted to the Embed API alone still works: the address field finds Places refused and falls back to the EMR's suggestions.

## Theme Assets

`public/assets/build/` (compiled CSS, JS, and the hashed `manifest.json` the Twig `asset()` function reads) is generated by:

```bash
npm install && npm run build
```

The generated output is **committed to the repository**, so **production servers never need Node.js installed** — `npm run build` is a build-time step run by a developer or CI before deploying, not a runtime dependency.

> **Caution — clear the Twig cache after deploying template changes.** In production (`app.env=production`), Twig compiles `theme/templates/*.twig` files to `storage/cache/twig` and disables `auto_reload`, so a stale compiled template will keep being served even after the source `.twig` file changes on disk. After deploying any change to `theme/templates/`, run:
>
> ```bash
> bin/console cache:clear
> ```

## Catalog Sync

The product catalog and payment-processor channel config are pulled from the configured EMR channel via three CLI commands, all run as the trusted operator/deploy user (never by a web request — see the permissions note above):

```bash
bin/console emr:ping             # connectivity check: channel name, product count, provider
bin/console theme:sync           # dry run (default) — prints the diff, writes nothing
bin/console theme:sync --apply   # writes config/products.generated.php and config/channel.generated.php
bin/console config:validate          # structural validation of the merged catalog
bin/console config:validate --live   # also resolves every rx/otc product against the live EMR
bin/console media:prune              # lists orphaned files under public/assets/media
bin/console media:prune --force      # deletes them
```

**Dry-run by default.** `theme:sync` only prints its diff unless you pass `--apply`. This is deliberate: it's a full-replace sync (the generated files are regenerated from the EMR response, not patched), so the diff is worth reading before committing to it — check the product/variant counts and the media and provider-identifier warnings at the end of the output before re-running with `--apply`.

**Caution — the printed diff includes the provider credential block.** `theme:sync`'s dry-run diff covers `config/channel.generated.php`, which carries the payment processor's live credentials (e.g. the Vrio API key), so don't pipe `theme:sync` output into shared logs or CI console output — treat it the same as you would a secret.

**`--apply` writes before validation runs.** Both generated files are written to disk first, then media is localized, then the structural validator runs last. An exit code of `2` means validation found problems in a catalog that was already written — the files on disk reflect the new sync, they were not rolled back; re-run `bin/console config:validate` (or fix the override layer) and re-sync rather than assuming a failed exit code means nothing changed.

**Both `config/*.generated.php` files are machine-written and gitignored**, and
`config/products.generated.php` — the synced catalog — is simply this deployment's own
data, so a fresh checkout carries only `config/products.generated.example.php` and needs a
sync before it has products to sell.

**`config/channel.generated.php` is the one that also holds credentials.** It holds the payment-processor config for the synced channel, including live provider credentials (e.g. the Vrio API key), so it must never be committed. `bin/console theme:sync --apply` is the only thing that writes it. `config/channel.generated.example.php` is the committed reference for its shape — copy its structure, never its placeholder values, when you need to reason about the file without a live sync.

**`config/products.overrides.php` survives every sync.** `theme:sync` regenerates `config/products.generated.php` wholesale, but the override layer is merged in afterward and is never touched by the sync itself. Any hand customisation — pricing tweaks, provider identifiers for variants the EMR doesn't carry them for, copy fixes — belongs in `products.overrides.php`, not in the generated file.

**`media:prune`** cleans up `public/assets/media` (gitignored, populated by `theme:sync --apply` localising product images) once products are removed or re-synced under new IDs. Run without `--force` first to see what would be deleted.

## Sessions & Attribution

Every storefront visit is tied to an analytics session minted with the EMR the moment a visitor lands. The session identifier is the correlation key across three systems for one visitor — the storefront, the EMR, and the payment provider — which is what lets an order, an intake answer and a lead all be traced back to the same journey later.

**The `amd_session` cookie.** Set on first landing, valid for 30 days, `HttpOnly` so client-side scripts cannot read or tamper with it, `SameSite=Lax`, and marked `Secure` automatically whenever the site is served over TLS. Because it is `HttpOnly`, the only way to restore a session the browser has lost the cookie for is the resume parameter below — there is no client-side storage fallback.

**Resuming a session.** The single canonical resume parameter is `?amd_session=<id>`, accepted on any path, not just the homepage. When present it is authoritative: it overrides whatever the current cookie holds. A value that is malformed (wrong shape, contains a path separator, etc.) or names a session the EMR has never heard of is ignored silently — the visitor simply lands at the funnel entry point with no error shown and a fresh session minted underneath them. A resume link restores intake answers along with journey and attribution state, so treat it as a bearer credential for health information: the parameter is the bare session identifier by deliberate choice, it carries no expiry, and completing a journey does not revoke it. The cost of that choice is that a resume link can sit in an inbox indefinitely and still work, so anyone holding the URL holds indefinite access to that person's intake answers. It is not a gap awaiting a signed-token fix — the compensating control is the audit trail, which records every use of a resume link and every sweep that emits one as an access to health information. What that asks of you as an operator is to keep resume links out of anywhere they would outlive their purpose (ticket threads, CI artefacts, shell history, shared inboxes) and to retain the event log long enough to answer "who resumed this journey, and when".

**Rotating `AMD_TRACKING_KEY`.** Affiliate and campaign links carry their tracking parameters encrypted in the `_amd` query parameter, and those links live on in already-sent emails and already-bought ads — they cannot be recalled or reissued on demand. To rotate the key without breaking them:

1. Move the current value of `AMD_TRACKING_KEY` into `AMD_TRACKING_KEY_PREVIOUS`.
2. Put the new key in `AMD_TRACKING_KEY`.
3. Deploy. Both keys are tried on every `_amd` payload, current key first, so links signed with either one keep decrypting during the overlap.
4. Once you're confident no live link can still carry the old key, clear `AMD_TRACKING_KEY_PREVIOUS`.

A payload that cannot be decrypted by either key never breaks the page — it falls back silently to plain query parameters, and the failure is recorded as `amd_decrypt_failed` in `storage/logs/app.log` for operators to notice a rotation that ran long or a malformed link.

**Turning sessions off.** Setting `app.session.analytics` to `false` (see `config/app.php`) stops the storefront talking to the EMR about sessions: nothing is minted, no new visitor gets a cookie, and the resume parameter has nothing to resume — a resume link is rejected because the EMR is never asked whether the session exists. What it does *not* do is disable the local session machinery for a browser that already holds an `amd_session` cookie from before the switch: that cookie is still honoured, so the row is still there and first-touch attribution is still recorded against it locally. Clear the cookie (or wait out its 30 days) if you need visits genuinely untracked. This is the right setting for an environment that has no EMR credentials configured yet, or for any environment where you deliberately don't want visits correlated back to the EMR. It is already off for the automated test suite so no test run reaches the network.

**Forwarding headers are stripped from client requests.** Both sample configs blank `X-Forwarded-Proto`, `X-Forwarded-For`, `CF-Connecting-IP` and `True-Client-IP` on the way in (nginx via `fastcgi_param HTTP_… ""`, Apache via `RequestHeader unset`). The app trusts those headers: `X-Forwarded-Proto` decides whether the session cookie is marked `Secure`, and the real-client IP headers are what tell the EMR where a visitor came from. Left un-stripped, a visitor could send `X-Forwarded-Proto: http` to a TLS site and get a cookie without `Secure`, or name any IP address they liked and have it stored as theirs.

If you front the app with something that legitimately sets these — Cloudflare sets `CF-Connecting-IP`, an AWS ALB or any nginx/HAProxy reverse proxy sets `X-Forwarded-Proto` and `X-Forwarded-For` — remove **only** the line for the header that hop sets, keeping the rest. Two things must be true before you do: the proxy has to overwrite the header rather than append to whatever the client sent, and nothing on the network may be able to reach the origin except through that proxy. If a request can arrive directly, relaxing the directive hands the spoof straight back.

**`sessions` and `events` tables.** `sessions` holds one row per analytics session: the EMR-assigned session id, the opportunity id once one exists, the durable journey state, and the captured attribution. `events` is the storefront's own append-only audit trail of what happened in a journey — session creation, resume, and later journey milestones — independent of what the EMR itself recorded, which is what makes a "this exists at the provider but nowhere in our own records" investigation possible. Event payloads are redacted the same way the operator log is: nothing health-, identity-, or credential-shaped is ever written to either. Both tables are operational data, not clinical records — treat them as subject to your deployment's own data-retention policy, and retain and purge them on that schedule rather than indefinitely.

The health endpoint (`/health/`) reports whether analytics sessions are enabled and whether `AMD_TRACKING_KEY` / `AMD_TRACKING_KEY_PREVIOUS` are configured, under its `info` block — presence only, never the key values themselves.

## Request size: intake file uploads

A file-upload question sends each file to the EMR as soon as it is picked, relayed through this server one request at a time: a whole document or image up to 10 MB, or a video or larger file in parts of up to 10 MB. Every layer between the browser and PHP has to let a request of that size through:

| Layer | Setting | Shipped value | Where |
|---|---|---|---|
| nginx | `client_max_body_size` | `12m` (default `1m`) | `deploy/nginx.conf` |
| Apache | `LimitRequestBody` | `12582912` | `deploy/apache-vhost.conf` |
| PHP | `upload_max_filesize`, `post_max_size` | `11M`, `12M` (defaults `2M`, `8M`) | `public/.user.ini` |

`public/.user.ini` is read by php-fpm and other CGI/FastCGI setups, and is not served (both samples deny dot-files). **mod_php ignores it**: under mod_php, set the two values with `php_value` in the vhost instead. A CDN or load balancer in front has a body limit of its own.

Lower PHP limits do not break the form. The page reads what PHP will accept and sends nothing larger, so a file between that and 10 MB goes up in parts instead of whole. Below 5 MB, though, a file larger than the limit cannot go up at all, because the EMR needs every part but the last to be at least 5 MB, and the page says so.

## Apache Deployment

### Required Modules

The Apache sample vhost requires the following modules to be enabled:

- **mod_rewrite** — for URL rewriting to the front controller
- **mod_proxy_fcgi** — for proxying PHP requests to PHP-FPM (alternative: mod_php)
- **mod_headers** — for setting cache headers on immutable assets

Enable them with:
```bash
a2enmod rewrite proxy_fcgi headers
```

Then reload Apache:
```bash
systemctl reload apache2
```

### Configuration

Copy `apache-vhost.conf` to `/etc/apache2/sites-available/storefront.conf`, update the `ServerName` and `DocumentRoot` paths, then enable it:

```bash
a2ensite storefront
systemctl reload apache2
```

### Apache Security Model

The Apache configuration implements the same security hardening as nginx:
- **Non-front-controller .php files are denied** — all `.php` files except `index.php` are blocked with `Require all denied`, mirroring nginx's `location ~ \.php$ { return 404; }`.
- **Dot-paths are denied anywhere in the URI** — paths containing a dot-prefixed segment (e.g., `/.git/config`, `/.env`) are blocked by a `LocationMatch "/\."` rule, preventing access to version control, configuration files, and other sensitive dot-directories.
- **Directory listing is disabled** with `Options -Indexes`.

## nginx Deployment

### Configuration

Copy `nginx.conf` to `/etc/nginx/sites-available/storefront.conf`, update the `server_name` and `root` paths, and configure the `fastcgi_pass` to match your PHP-FPM socket or TCP listener. Then enable it:

```bash
ln -s /etc/nginx/sites-available/storefront.conf /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

## Docker

The `Dockerfile` at the repository root builds one image that runs nginx in front of php-fpm, on PHP 8.5 (`--build-arg PHP_VERSION=8.4` for the floor). The server block in `deploy/docker/nginx.conf` follows `nginx.conf` above, with the same deny rules, forwarding-header stripping, body limit and asset caching. What changes is what a container needs: it listens on **8080**, and nginx, php-fpm and anything you `docker exec` all run as `www-data`. The application files are owned by root and read-only to that user; `storage/` is the only writable path, which is the permission model from step 6.

On every start the container runs `bin/console cache:clear` and then `bin/console db:migrate`. Clearing the cache matters because `storage/` is a volume that outlives the image: without it, a new image would keep serving the old image's compiled Twig templates. Set `MIGRATE_ON_START=0` if a release step runs migrations instead.

### Local development

```bash
cp .env.example .env          # set APP_ENV=dev for template work — see below
docker compose up
curl -s localhost:8080/health/
```

`compose.yaml` builds the `dev` target and bind-mounts the checkout, so code and template edits show up on the next request. On first start it runs `composer install` into `vendor/` if there is none. Run everything else in the same container:

```bash
docker compose exec app bin/console emr:ping
docker compose exec app bin/console theme:sync --apply   # writes config/*.generated.php into the checkout
docker compose exec app vendor/bin/phpunit
```

- **`.env.example` says `APP_ENV=production`, and in production Twig never notices a changed template.** For template work, set `APP_ENV=dev` in your `.env`.
- **`storage/` lives on the `app-storage` volume, not in the checkout.** On a Mac, a bind mount reports every file as owned by your host user, and PHP will not open a session file it does not own, so storage on the bind mount would break sessions. Read the log with `docker compose exec app tail -f storage/logs/app.log`.
- **On a Linux host, set `DOCKER_UID` and `DOCKER_GID`** (in `.env` or the shell) to your own `id -u` and `id -g`. Otherwise the container's uid cannot write `vendor/` or `config/` in your checkout. Docker Desktop and Rancher Desktop on a Mac need neither.
- **The `dev` target has Node 24 and npm**, so the asset pipeline runs in the container too: `docker compose exec app npm run build`, or `npm run dev` for the watcher. `node_modules/` lives on its own `app-node-modules` volume, because Tailwind's native binary is per platform and the one npm installs on a Mac cannot run in the Linux container. On first start the container runs `npm ci` into it. After `package-lock.json` changes, run `docker compose exec app npm ci`. Running npm on the host still works as before. The production image contains no Node, because built assets are committed.

### Production

```bash
docker build -t astermd-storefront .

docker run -d --name storefront -p 8080:8080 --restart unless-stopped \
  --env-file .env \
  -v storefront-storage:/var/www/html/storage \
  -v "$PWD/config/products.generated.php:/var/www/html/config/products.generated.php:ro" \
  -v "$PWD/config/channel.generated.php:/var/www/html/config/channel.generated.php:ro" \
  -v "$PWD/public/assets/media:/var/www/html/public/assets/media:ro" \
  astermd-storefront
```

- **The environment comes from the container.** `--env-file .env` or individual `-e` flags work. php-fpm passes the container environment through (`clear_env = no`), and a variable set on the container beats the same name in a mounted `.env`. Docker's `--env-file` does not strip quotes the way `.env` parsing does, so keep values unquoted, as `.env.example` does. Everything in [The environment file](#the-environment-file) applies unchanged, including deleting or fixing `APP_ENV`.
- **The synced catalog is mounted, never built in.** `.dockerignore` keeps `.env`, both `config/*.generated.php` files and `public/assets/media/` out of the image, because `channel.generated.php` carries live provider credentials and an image outlives the deployment it was built for. Produce them with `theme:sync --apply` from a checkout (the compose stack above will do), then mount them read-only as shown. **Each mounted file must exist before you start the container.** `docker run -v` creates a missing host path as a directory, and a directory named `channel.generated.php` stops the configuration from loading at all. Without the three mounts the container runs on the example catalog, the same as a fresh clone.
- **Health:** the image declares a `HEALTHCHECK` against `/health/`, so `docker ps` reports `healthy`/`unhealthy` from the same checks a load balancer would use.
- **TLS terminates in front of the container.** The forwarding headers are stripped here exactly as in `nginx.conf`, so behind a proxy that sets `X-Forwarded-Proto` you edit `deploy/docker/nginx.conf` as described under [Sessions & Attribution](#sessions--attribution) and rebuild. Make sure nothing can reach port 8080 except through that proxy.
- **The scheduled jobs still need a scheduler.** Nothing in the container runs them. Invoke them from the host's cron or your orchestrator, for example `docker exec storefront bin/console emr:reconcile --apply`. `docs/OPERATIONS.md` covers cadence, and the warning there about where `abandonment:signals` output may go applies to a container's captured stdout too.

## Verification

After deployment, verify the application is working correctly:

### Health Check Endpoint

```bash
curl -i http://example.com/health/
```

Expected response: **200 OK** with JSON payload.

### URL Rewriting

```bash
curl -i http://example.com/treatments
```

Expected response: **301 Moved Permanently** (redirect to `/treatments/`).

Both configurations implement:
- **Docroot** locked to `public/`
- **Deny rules** for `/config`, `/storage`, `/core`, `/vendor`, `/bin`, `/database`, and dotfiles (as defense-in-depth; these paths are outside the docroot)
- **Immutable asset caching** for `/assets/build/` with `max-age=31536000` (1 year)
- **Front controller routing** so only `index.php` executes PHP
