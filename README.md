# AsterMD Storefront

A server-rendered storefront theme for telehealth. It carries a visitor from a product
page to a paid, clinically-reviewable order: catalog and cart, a definition-driven intake
questionnaire, an optional identity check, checkout against a payment aggregator,
post-purchase upsells, and a receipt.

PHP 8.4+ on Slim, Twig templates, Symfony Console for the CLI, and PDO against SQLite
(MySQL and PostgreSQL are also supported). No JavaScript framework, no build step at
runtime, no queue, no application server — Apache or nginx in front of PHP-FPM and a cron
table is the whole deployment.

It talks to two external systems:

- **the EMR**, through `astermd/sdk` — the product catalog, analytics sessions, teleform
  (questionnaire) definitions, intake submissions, and checkout-funnel events;
- **a payment aggregator**, through `astermd/vrio-client` — the charge itself, promotions,
  and the reference-order handle that later upsell charges reuse.

The two are kept apart on purpose. **Card data reaches the payment adapter and nothing
else.** No card number, expiry or security code is ever sent to the EMR, and none is ever
written to this application's own database — `core/Payment/PaymentCredential.php` makes
that structural rather than conventional, since the only credential shape that can be
persisted is one with no card in it.

---

## Requirements

| | |
|---|---|
| PHP | `>= 8.4` (`composer.json`; developed and run against 8.5) |
| PDO | plus the driver extension for your database — `pdo_sqlite`, `pdo_mysql` or `pdo_pgsql` |
| Composer | any current version |
| Node | build-time only, for the asset pipeline. No `engines` constraint is declared; the pipeline is developed against Node 24. |
| Web server | Apache with `mod_rewrite`/`mod_headers`, or nginx. Samples in `deploy/`. |

**Production servers do not need Node.** `public/assets/build/` — the compiled CSS, the
theme JS and the hashed `manifest.json` that Twig's `asset()` reads — is committed. `npm
run build` is something a developer or CI runs before deploying, never at runtime.

## Install

```bash
composer install                  # --no-dev on a server
cp .env.example .env              # then edit it, see Configuration below
bin/console db:migrate            # creates the SQLite file if it does not exist
npm install && npm run build      # only if you changed theme/css or theme/js
```

**On a server, delete the `APP_ENV` line from that copy.** Any value other than
`production` puts a sitewide `noindex` over the whole store; an absent `APP_ENV`
means `production`. See [`APP_ENV`](#app_env) below.

Point the web server's document root at `public/`. The web server user needs **write**
access to `storage/` and nothing else; `config/` must be **read-only** to it, because the
only thing that writes `config/` is `theme:sync` run from a shell as a deploy user.

To run it locally without a web server:

```bash
php -S localhost:8080 -t public
```

Check it came up:

```bash
curl -s localhost:8080/health/
```

`/health/` reports config, database, storage-writability and form-cache status, plus a
short `info` block (whether analytics sessions are on, whether the tracking keys are
present — presence only, never values).

---

## Configuration

Two layers. `.env` holds credentials and per-environment values. `config/*.php` holds
behaviour, and it is meant to be read: nearly every setting carries a docblock explaining
not just what it does but why it is set the way it is. Read the file before changing the
value.

Every file in `config/` becomes a top-level key. `config/app.php` is `app.*`,
`config/verification.php` is `verification.*`, and so on.

| File | What it decides |
|---|---|
| `app.php` | site name and URL, database, session and resume-parameter behaviour, attribution key rotation, SEO and structured data, feature switches, `WIRE_LOG` |
| `routes.php` | the route table — URL to controller |
| `funnel.php` | which URL is which funnel step, and what must be true before a visitor may enter it |
| `intake.php` | which renderer draws the questionnaire, definition caching, fallback disqualification rules |
| `verification.php` | identity verification: whether it runs, where it sits, whether it blocks, which checks in what order |
| `payment.php` | shipping profile id, currency, rate limits, stale-attempt window |
| `consent.php` | the consent controls shown at checkout, their wording, and which of them block an order |
| `cross-sells.php` | checkout order bumps — accepted *before* submission, ride along on the main order |
| `upsells.php` | post-purchase upsells — charged *separately* after the first order settles |
| `abandonment.php` | when a stopped journey counts as abandoned, and how far back a sweep looks |
| `retention.php` | how long each category of data is kept, and what a deletion request can actually reach |
| `products.generated.php` | **machine-written, gitignored.** The catalog, as synced from the EMR channel — one deployment's products, prices and provider identifiers. `products.generated.example.php` is the committed shape reference; a clone renders that placeholder catalog until you sync. |
| `products.overrides.php` | **yours.** Merged over the generated catalog at runtime. |
| `channel.generated.php` | **machine-written, gitignored.** Payment-processor config including live provider credentials. `channel.generated.example.php` is the committed shape reference. |

### Generated vs. override

This is the part worth understanding before you edit anything in `config/`.

`bin/console theme:sync --apply` **regenerates `products.generated.php` wholesale** from
the EMR channel response. It is a full replace, not a patch. Anything you hand-edit in
that file is gone at the next sync.

`products.overrides.php` is never touched by sync. It is merged *over* the generated
catalog at runtime by `CatalogProvider`, so every customisation survives every re-sync.
Pricing tweaks, provider identifiers for variants the EMR does not carry them for, copy
fixes, territory blocks, pre-qualification flags — all of it belongs there.

Two rules that are easy to get wrong, and both are documented at length at the top of
`products.overrides.php`:

- **Key overrides by `emr_product_id`, never by slug or name.** Slugs and names can change
  on a re-sync; the EMR's id does not.
- **Any field you set replaces the generated field wholesale** — overriding `categories`
  replaces the whole list rather than adding to it. `variants` is the one exception: key
  it by variant id and only the fields you name are merged into that one variant.

Both generated files are gitignored, for the same reason in two strengths: each is one
channel's data rather than the theme's, and `channel.generated.php` additionally holds live
payment credentials, so it must never be committed. Each has a committed
`*.generated.example.php` beside it that documents the shape. `products.overrides.php` **is**
committed — it ships as a documented template, and `theme:sync` never writes it, so your
edits to it are yours.

### Environment

`.env.example` is the starting point and covers the required set: app URL and environment,
database, EMR credentials and channel id, and the `_amd` affiliate-tracking key (with its
rotation slot, `AMD_TRACKING_KEY_PREVIOUS` — both keys are tried on every payload, so
already-sent links keep working across a rotation).

#### `APP_ENV`

The one value in `.env` that decides whether the store can be indexed at all. There is no
enumeration of it in code: `production` and `test` mean something specific, and every other
value behaves as a development environment.

| `APP_ENV` | Indexability | Twig cache | Error details in the response | EMR analytics sessions |
|---|---|---|---|---|
| absent, or `production` | per-route rules only: `robots.txt` disallows the funnel prefixes, `sitemap.xml` lists the marketing paths, no page-level `noindex` | compiled to `storage/cache/twig`, `auto_reload` off | hidden | on |
| any other value (`dev`, `staging`, …) | **sitewide `noindex`**: `robots.txt` is a blanket `Disallow: /`, `sitemap.xml` is an empty `<urlset>`, every page emits `noindex, nofollow` | off, recompiled every request | shown | on |
| `test` | as above | off | shown | **off** — nothing minted, no cookie, no resume |

Two things follow that are easy to get backwards:

- **An absent `APP_ENV` is a production deployment.** `config/app.php` reads
  `$env['APP_ENV'] ?? 'production'`, which inverts the usual instinct: deleting the setting
  is safer than carrying a copied one forward.
- **The indexing switch overrides everything.** It is a master switch, not one input among
  several: while it is on, no per-route rule and no sitemap entry can make anything
  indexable. That is what stops a staging site leaking into an index through one mis-set
  page — and it is equally what stops a production site with a stray `APP_ENV=staging`
  getting into one, however correct the rest of its SEO configuration is. `curl -s
  https://your-store.example/robots.txt` is the check; a bare `Disallow: /` is the symptom.

#### Values that cannot be generated locally

Four values in `.env` are issued to a deployment rather than chosen by it. Three of them
fail loudly when they are wrong. The fourth does not, which is why it is worth reading:

| Variable | Origin | Shape |
|---|---|---|
| `ASTERMD_CLIENT_ID` | issued with the API credential pair | an opaque identifier, `@`, and the API host it was issued for |
| `ASTERMD_CLIENT_SECRET` | issued alongside it | opaque; a blank pair throws at client construction |
| `ASTERMD_CHANNEL_ID` | the EMR's record for the sales channel this storefront sells | 24 hexadecimal characters |
| `AMD_TRACKING_KEY` | **issued** — the key the affiliate-link service encrypts with | exactly 32 hexadecimal characters (a 16-byte AES-128 key) |

`AMD_TRACKING_KEY` is the one worth spelling out. A self-generated 32 hex characters is a
valid key that is simply not *the* key: every `_amd` payload then fails to decrypt, the page
falls back silently to plain query parameters and renders normally, and the only trace is an
`amd_decrypt_failed` line in `storage/logs/app.log`. Affiliate traffic is attributed to
nothing and nothing announces it. Leaving the value **empty** is a legitimate configuration
— encrypted payloads are never read, plain parameters are — and is right for a deployment
with no affiliate links yet. `deploy/README.md` covers rotation once you have a real one.

#### `GOOGLE_MAPS_API_KEY`

Optional, blank by default, and blank is handled — the receipt renders no map. Filling it in
buys a delivery map on `/thank-you/` at two costs worth deciding on rather than discovering:
the embed's query parameter **is the buyer's shipping address**, so every receipt causes a
third-party request carrying where that person lives; and the key is a template global, so it
is delivered as plain text in page source and needs an HTTP-referrer restriction to your own
domain plus an API restriction to the Maps Embed API before it goes anywhere real.

#### Switches with no line in `.env.example`

Several settings read from the environment without appearing in the example file. The ones
worth knowing:

| Variable | Effect | Default |
|---|---|---|
| `WIRE_LOG` | writes every EMR and provider call to `storage/logs/` verbatim. **See Security.** | off |
| `IDENTITY_VERIFICATION` | master switch for the identity-check step | off |
| `EMR_VERIFICATION` | email-deliverability and address checks at checkout | off |
| `INTAKE_RENDERER` | `server` or `js-engine` | `server` |
| `PAYMENT_SHIPPING_PROFILE_ID` | the provider refuses any payment order without one | `1` |
| `ABANDONMENT_IDLE_SECONDS` / `_LOOKBACK_SECONDS` / `_LIMIT` | abandonment sweep tuning | 3600 / 2592000 / 500 |

---

## Running it

```bash
bin/console list          # the full command set
bin/console help <cmd>    # options for one command
```

The commands that write or spend money are dry-run by default and need `--apply` (or, for
`media:prune`, `--force`) to act. The exception is `forms:cache-purge`, which deletes
immediately — harmless, since the cache refills from the EMR on the next request.

**Setup and catalog**

```bash
bin/console db:migrate            # create the database and apply pending migrations
bin/console emr:ping              # connectivity: channel name, product count, provider
bin/console provider:ping         # resolve the payment adapter and check it answers
bin/console theme:sync            # dry run — prints the diff, writes nothing
bin/console theme:sync --apply    # writes products.generated.php and channel.generated.php
bin/console config:validate       # structural validation of the merged catalog
bin/console config:validate --live   # also resolves every rx/otc product against the EMR
bin/console cache:clear           # wipe storage/cache (do this after deploying template changes)
bin/console media:prune           # orphaned files under public/assets/media (--force to delete)
```

**Questionnaire definitions**

```bash
bin/console forms:cache-purge     # drop every cached teleform definition
bin/console forms:record --teleform=<id>   # capture a definition and its metadata to disk
```

The definition cache is keyed on the teleform's `form_json_identifier`, which carries the
form's version and publish time — so republishing a form in the EMR is already a cache
miss and a purge is rarely needed.

`theme:sync`'s dry-run diff includes `channel.generated.php`, which carries the live
provider credential. Do not pipe it into CI console output or a shared log.

**Scheduled jobs**

```bash
bin/console emr:reconcile         # orders the EMR was never told about
bin/console provider:reconcile    # charges the provider took that are not recorded locally
bin/console abandonment:signals   # abandoned-journey signals as JSON  ← see Security
bin/console db:prune              # operational data past its retention period
bin/console ops:status            # the monitored counts that represent money or clinical risk
```

Schedules, thresholds, alerting and what each report means when it is non-empty are in
**[`docs/OPERATIONS.md`](docs/OPERATIONS.md)**. Do not run these from cron off this README
alone.

### `config:validate` exits `2`, not `1`

`config:validate` distinguishes errors from warnings and notes, and reports **exit code 2**
on error. Errors are things that will break or endanger a deployment (`WIRE_LOG` on, a
consent linking to a route this application does not serve, a missing shipping profile);
warnings are catalog facts an operator should read; notes are always-printed context such
as the PCI posture of the resolved adapter.

There is one way this reliably gets missed:

```bash
bin/console config:validate | tail -5    # exit status 0 — tail's, not the command's
```

A shell pipeline reports the **last** command's status. If you filter, paginate or `tee`
the output in a deploy script, capture the status explicitly (`set -o pipefail`, or
`${PIPESTATUS[0]}`) or you will ship a configuration the validator rejected.

Note also that `theme:sync --apply` writes both generated files *before* the validator
runs. An exit of `2` from a sync means the new catalog is already on disk and was not
rolled back — fix the override layer and re-run, rather than assuming nothing changed.

---

## Security

This section is not boilerplate. Five of these are deliberate positions with real costs,
and the costs are stated because a reader who does not know them will make the wrong call.

### The resume link is permanent, on purpose

The single canonical resume parameter is:

```
https://your-store.example/?amd_session=<session uuid>
```

It is the raw analytics session identifier. **It carries no expiry, and completing a
journey does not revoke it.** It is accepted on any path, and when present it overrides
whatever session cookie the browser holds.

That was chosen deliberately over a short-lived signed token, and it is not a gap waiting
to be closed — nothing here should be "fixed" into revoking it without the decision being
re-made first.

What follows from the choice, stated plainly: **a resume link restores a person's intake
answers, which are health information, and the exposure is permanent.** Anyone who ever
holds that URL — a forwarded email, a browser history on a shared machine, a referrer
header, a support ticket attachment — holds indefinite access to that person's answers.
There is no revocation path short of deleting the session row.

The compensating control is the audit trail: every use of a resume link, and every sweep
that serialises a journey into a signal, is recorded as an access to health information.
With no expiry, that log is the only record that the link was used.

### `abandonment:signals` output is health information

The command emits JSON, one object per abandoned journey, and **every object carries a
`resume_url`**. By the paragraph above, that makes the command's entire output PHI.

Treat it as a credential store, not as a report:

- it belongs in the marketing automation platform that consumes it, **and nowhere else**;
- not a CI artefact, not a build log, not `storage/logs/`, not a Slack paste;
- not your shell history — do not run it interactively and scroll back through it;
- pipe it directly to the consumer, and give whatever runs it a locked-down cron
  environment.

The audit trail records that a signal carrying a resume link was emitted for a journey. It
deliberately does not record the link itself, because that would put the credential in a
second place.

### `storage/logs/` carries the same sensitivity as that feed

The operator log stamps the analytics session identifier on every line written inside a
journey. That is deliberate and it is `[20.13]`: one correlation identifier is what joins a
storefront log line to the EMR record and to the provider order, and without it a
"this exists at the provider but nowhere in our own records" investigation has nothing to
join on.

The same identifier is the resume link's only secret. `?amd_session=<uuid>` is the bare
identifier, and by the two sections above it never expires and is never revoked. A log line
naming a session is therefore a resume link that can be reassembled by hand — for every
journey the file mentions. **Read access to `storage/logs/` is close to resume access to the
intake answers of every journey in it.**

Note where the exposure is not. Nothing health-, identity- or credential-shaped reaches these
files; the redaction pass sees to that, and it is value-based rather than key-based so it
catches card data under a key that means nothing. The exposure is the identifier that makes a
line correlatable, not the line's contents. So treat log files, log shipping, and any
aggregator they reach exactly as you treat `abandonment:signals` output: not a ticket
attachment, not a CI artefact, not a shared dashboard. They live under `storage/`, which is
gitignored, and they expire on `retention.operational.log_days` — 30 days by default, and
enforced by `bin/console db:prune` rather than by intent.

### Never enable `WIRE_LOG` anywhere real

`WIRE_LOG=true` writes every EMR and payment-provider call — request and response — to
`storage/logs/`, **verbatim and unredacted**. That is the entire point of it: a redacted
transcript cannot be replayed by hand, and replaying the exact bytes is how a disagreement
with a provider gets settled.

While it is on, those files hold, in clear text:

- primary account numbers, security codes and expiry dates;
- live bearer tokens and the OAuth client secret;
- the buyer's name, address and telephone number;
- **and, if identity verification is on, Social Security Numbers and dates of birth.**

The last one is worth spelling out. The SDK does flag the identity-verify path as PHI and
can drop its body — but only through a redactor this application deliberately does not
install, because redaction defeats the switch's only purpose. The flag is all-or-nothing;
there is no setting that keeps verbatim provider bytes and still suppresses the identity
path.

So: this is a local debugging switch, against sandbox test cards, on a machine with no
real data. It is **never** something a deployment taking real payments or running real
identity checks may enable.

`bin/console config:validate` reports it as an **error**, not a warning, so a deployment
cannot ship with it on by accident — provided you read the exit code (see above). The
files land under `storage/`, which is gitignored, so a transcript cannot be committed by
accident either.

### Identity verification ships disabled — and that is a measurement

`config/verification.php` has both `enabled` and `blocking` set to `false`, and the file
opens with several hundred words explaining why. The short version, honestly stated:

**The provider integration cannot currently tell anybody apart.** Measured against the
live provider on 2026-08-25: twenty calls across nine identities, all three checks, and
not one returned `valid: true`. The `crosscheck` check scored exactly **0** against its
configured threshold of 0.75 — for a real, deliverable identity with a valid phone and a
real originating address, and for a fabricated one, identically.

It is not a threshold that could be lowered. `crosscheck` returns `address_invalid` and
`phone_invalid` even when called with name only, so it penalises fields that were never
sent; supplying real ones leaves the score at 0. `dob_verify` and `ssn_verify` return a
bare mismatch with no score at all. The shape of a passing response is therefore
unrecorded and cannot be recorded against this provider's sandbox, which is why the pass
branch is exercised against a fake gateway rather than a live call.

Enabled and blocking, this step refuses every real buyer. **That is a measurement, not
caution.** Turning either switch on needs a fresh measurement first: call the provider with
an identity known to be good and confirm it comes back `valid: true` before trusting any
verdict this integration produces.

The code is complete and tested either way — placement (`intake`, `post_checkout`,
`receipt`) and blocking are separate settings and must not be conflated, and the verdict
is recorded on the order whatever the placement, because it is a compliance artefact
rather than a funnel convenience. The fourth placement, `async`, is declared for
completeness and is **not implemented**: it needs durable authenticated inbound handling
this deployment has no receiver for, so selecting it is a configuration error rather than
a silent downgrade.

### Also worth knowing

- **Forwarding headers are stripped from client requests** by both sample web-server
  configs. The application trusts `X-Forwarded-Proto` (it decides whether the session
  cookie gets `Secure`) and the real-client-IP headers (they are what the EMR is told).
  Left un-stripped, a visitor can hand you either one. If you front the app with something
  that legitimately sets them, un-strip only that header — `deploy/README.md` has the two
  conditions that must hold first.
- **The `amd_session` cookie is `HttpOnly`**, so there is no client-side fallback: the
  resume parameter is the only way to restore a session whose cookie is gone. That is
  precisely why the resume parameter is as powerful as it is.
- **Nothing health-, identity- or credential-shaped is written to the operator log or the
  local event trail.** Redaction there is value-based, so it finds card data inside a
  string whose key means nothing.
- **The shipped legal copy is placeholder and says so.** `/terms/`, `/privacy/` and
  `/telehealth-consent/` are routed by this application rather than left to the
  deployment, because a consent control whose document 404s is not consent —
  `config:validate` refuses to pass when a configured consent links somewhere unserved.
  The wording is still yours to replace.

---

## What ships deliberately switched off

A short list, so none of these reads as an oversight. Each is explained in the config file
that holds it.

- **Identity verification** — see above.
- **`app.features.emr_verification`** — the shipped credential is refused for that whole
  EMR resource, and a check that always fails is worse than none.
- **`config/cross-sells.php` is empty** — every product in the current channel is a
  prescription, and an order carries at most one prescription, so nothing in the catalog is
  a legal order bump.
- **`seo.structured_data.rx` is `false`** — a prescription product carries claims and
  availability constraints that vary by jurisdiction; a deployment enables it once it has
  decided its own territory allows it.
- **`seo.search_url_template` is `null`** — there is no search endpoint here, and
  publishing a `SearchAction` would tell a search engine that a query returns matching
  results when what comes back is the unfiltered listing.
- **`seo.discourage_indexing` defaults on outside production** — a staging deployment
  cannot leak into an index through one misconfigured page.

---

## Testing

```bash
vendor/bin/phpunit
```

**1995 tests, 7090 assertions.** A full run takes about ten seconds.

A clone that has not synced a catalog is green too, at 7027 assertions — the cases that
assert something about *the shipped catalog* fall back to
`config/products.generated.example.php` through `tests/Support/ShippedCatalog.php`. The
assertion count is lower only because the example catalog is smaller than a real channel.

**No test makes an outbound network call.** That is enforced rather than hoped for: the
suite runs with `APP_ENV=test` (set in `phpunit.xml`), which turns EMR analytics sessions
off at the config level, and every gateway — EMR sessions, cart, teleforms, leads, identity
checks, the payment transport — has a fake that tests inject. Provider behaviour is pinned
against recorded response fixtures rather than re-fetched. `forms:record`, which always
reaches the real EMR when an operator runs it, is exercised in tests through its gateway
seam instead.

The suite also checks its own documentation: `tests/Docs/SpecReferenceTest.php` scans the
source for `[n.n]` citations and fails if any of them resolves to nothing in
`docs/SPEC-REFERENCE.md`.

---

## Where everything else is documented

| | |
|---|---|
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | how the pieces fit — the funnel, the journey store, the catalog merge, the payment and EMR seams |
| [`docs/OPERATIONS.md`](docs/OPERATIONS.md) | the operator runbook: schedules for the four jobs, what `ops:status` counts mean, what to do when one is non-zero |
| [`docs/INTEGRATION-NOTES.md`](docs/INTEGRATION-NOTES.md) | what the EMR and the payment provider really do, as measured — including where each disagrees with its own documentation |
| [`docs/SPEC-REFERENCE.md`](docs/SPEC-REFERENCE.md) | what the `[n.n]` citations mean |
| [`deploy/README.md`](deploy/README.md) | web-server configs, permissions, catalog-sync procedure, session and attribution behaviour, key rotation |

### About the `[24.4]`-style citations

You will meet these throughout the source — in docblocks, in configuration comments, in
template annotations, in test names:

```php
 * `[22.16]` is the rule most easily got wrong: placement and blocking are two
 * separate settings and must not be conflated.
```

They all mean the same thing: **the code they sit next to exists in order to satisfy that
rule.** The storefront was built against a written behaviour contract — a numbered set of
statements about what the funnel must do — and much of that behaviour is not self-evident
from the code. A cart line that refuses to be removed, an intake step that will not
advance, a verification call whose result is recorded and then ignored: each looks like a
bug until you know it was asked for. The citation is what separates the two, and its
absence is equally informative, because unclaimed behaviour is nobody's decision.

`docs/SPEC-REFERENCE.md` resolves every id the code actually cites. Look there before
assuming something is a mistake.

---

## Layout

```
bin/console              CLI entry point (Symfony Console)
config/                  behaviour configuration — read the docblocks
core/                    application code, PSR-4 AsterMD\Storefront\
database/migrations/     numbered, applied by bin/console db:migrate
deploy/                  nginx.conf, apache-vhost.conf, deployment guide
docs/                    architecture, operations, integration notes, spec reference
public/                  document root — index.php and built assets
storage/                 gitignored; cache, logs, sessions, SQLite file
theme/                   Twig templates, CSS and JS sources
tests/                   PHPUnit suite
```

## Version and license

The version lives in `composer.json` and `package.json`, which must always agree with each
other and with the release tag. This is **0.0.1**; `CHANGELOG.md` names the files each
release changed, because an update is applied by copying files rather than by a package
manager.

MIT licensed — see `LICENSE`.

---

`core/` is the application; `theme/` is what you restyle. Core files carry a header saying
local edits are lost when core is updated — customisation belongs in `theme/`,
`config/*.overrides.php`, and the non-generated config files.
