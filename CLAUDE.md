# CLAUDE.md

AsterMD telehealth storefront — PHP 8.4+ / Slim 4 / Twig / SQLite.

This file is **how to work here**. What the system *is* lives in `docs/ARCHITECTURE.md`.

---

## Toolchain

| Purpose  | Command              | What it has to be                                     |
| -------- | -------------------- | ----------------------------------------------------- |
| PHP      | `php`                | `>= 8.4` — the constraint in `composer.json`           |
| Composer | `composer`           | any current 2.x                                        |
| Tests    | `vendor/bin/phpunit` | PHPUnit 13, from `require-dev`                          |
| Assets   | `npm run build`      | build-time only; the pipeline is developed against Node 24 |

- **Establish which `php` and which `composer` you are actually getting, once, before you trust anything they tell you.** `php -v` and `composer --version` at the start of a session. A `php` older than 8.4 fails inside `core/` as a *parse error*, which reads as a syntax bug in the file rather than as the wrong interpreter, and a `composer` that is a stale shell alias or a wrapper around an absent `composer.phar` fails as `Could not open input file`, which reads as a broken Composer install. Neither failure names its real cause.
- **If either one is not what you want, pin that invocation to an absolute path for the rest of the session** — `/path/to/php vendor/bin/phpunit`, `/path/to/composer install` — rather than fixing your shell and hoping it took.
- `bin/console` is `#!/usr/bin/env php`, so it inherits whatever `php` is first on `PATH`. Run it as `<the php you chose> bin/console …` whenever that is uncertain.
- The absolute paths differ per machine and are nobody's rule but that machine's. On the laptop this was developed on, for instance, PHP 8.5.9 and Composer 2.10.2 sit under `/opt/homebrew/bin/` and bare `composer` is an alias onto a `composer.phar` that is not installed — which is exactly the second failure above, and exactly the kind of thing that is true of one shell and no others.
- Baseline: `vendor/bin/phpunit` is green at **1995 tests / 7090 assertions** — and at **1995 / 7027** on a clone that has not synced a catalog, where the suite falls back to `config/products.generated.example.php`. Both cases must stay green; the assertion counts differ because the example catalog is smaller than a real channel. Anything red is yours — establish the baseline before you change anything, so a pre-existing failure is never mistaken for one you caused.

---

## Code standards

`core/**` is framework code the client never edits. `theme/` and `config/` are the client's.

- **Every file under `core/` opens with `declare(strict_types=1);` and then the core header:**
  ```php
  /* Core file — part of the AsterMD storefront core. Local edits are lost when core is updated. */
  ```
  Copy it verbatim into any new core file. It is the only signal telling a client-side editor that their edits here will be discarded on the next update.
- **Never put that header on a `theme/` or `config/` file.** Those are client-owned and survive an update; the header would be a lie that talks someone out of their own work.
- **Everything in `core/` is `final`** — all 213 classes, no exceptions. Compose and inject instead of subclassing, so behaviour stays a wiring decision in `core/Bootstrap/AppFactory.php` rather than an inheritance graph nobody can see the shape of.
- **PHPDoc explains *why*, not *what*.** Document the decision, the failure it prevents, the thing that looks like a bug and is not. Skip narration of code that already reads itself — comment noise is what makes the load-bearing comments invisible.
- **No internal-process vocabulary in shipped files.** This repository is public. Notes about how the work was scheduled or organised mean nothing to a reader and date on contact.
- **`[24.4]`-style rule citations are wanted and are kept.** They mark behaviour that was *asked for* rather than invented, and their absence is equally informative. `docs/SPEC-REFERENCE.md` resolves every id the code cites — point readers there. `tests/Docs/SpecReferenceTest.php` fails on a citation the reference does not resolve, so cite only ids already in it: a pointer that resolves to nothing is worse than no pointer.
- **Money is integer cents everywhere.** Convert only at a rendering or protocol boundary, and only through the named exits: `Checkout\Totals::dollars()`, `Totals::decimalString()`, and the Twig `money` filter. The EMR accepts a cents integer where it documents a float dollar amount *without complaining*, so a skipped conversion charges 100× and is invisible everywhere except in a test.

---

## Testing

- **Test methods are camelCase** — `testTheThingItAsserts()`. 1919 of them, zero snake_case. There are no `#[Test]` attributes; the `test` prefix is what PHPUnit discovers, so a renamed method silently stops running.
- **No test may make an outbound network call.** `phpunit.xml` sets `APP_ENV=test`; `config/app.php` turns that into `app.session.analytics = false`; `AppFactory` then binds `NullSessionGateway`, `NullCartGateway`, `NullTeleformGateway`, `NullIntakeGateway` and `NullLeadGateway`. Two more ports are Null-bound under the suite by their own switches rather than by `APP_ENV` — `IdentityGateway` follows `verification.enabled` and `VerificationGateway` follows `app.features.emr_verification`, both of which ship off — and they are deliberately not tied to the environment, so do not assume `APP_ENV=test` is what fences them. **`PaymentAdapter` is not environment-switched at all**: it resolves from synced channel data, so the suite gets a real adapter over a transport that refuses to send ({@see core/Payment/RefusingTransport.php}). Never restore a real gateway to make a test "more realistic" — a suite that can reach the dev EMR *writes to it*.
- **The substitution seam is `AppFactory::create($rootDir, $containerOverrides)`.** Override through the second argument. Never add a test-only branch to production wiring; the branch then ships and the thing under test is not the thing that runs.

### Anything that renders a questionnaire needs three overrides and a cookie

Without them the test silently exercises a different page than you think:

```php
AppFactory::create(dirname(__DIR__, 2), [
    \PDO::class            => $this->tempPdo(),
    SessionGateway::class  => new FakeSessionGateway(mintUuid: self::SESSION),
    TeleformGateway::class => /* a gateway returning the definition under test */,
    DefinitionCache::class => new DefinitionCache($cacheDir, 0),   // a fresh temp dir
]);
```

and every request must carry the session cookie:

```php
(new ServerRequestFactory())->createServerRequest('GET', $path)
    ->withCookieParams(['amd_session' => self::SESSION]);
```

The reasons, in order: without a minted session there is no journey for answers to live on; without a *fresh temp* `DefinitionCache` directory one test is served the definition another left behind; without the cookie every request is a brand-new visitor and the journey is empty. POSTs additionally need the `_csrf` token, read back from the rendered page or from `$_SESSION['_csrf']`.

### `APP_ENV=test` makes every page `noindex`

`config/app.php` sets `app.seo.discourage_indexing` for any non-production environment, so under the suite every page emits `<meta name="robots" content="noindex, nofollow">` and the sitemap is empty.

Consequence: **an assertion that some particular page refuses indexing proves nothing under the default suite config** — it would hold on the marketing home page just as well. To reach the indexable path, vary the configuration through `tests/Support/ConfigVariant.php`:

```php
$app = require dirname(__DIR__, 2) . '/config/app.php';
$app['seo'] = ['discourage_indexing' => false] + $app['seo'];

AppFactory::create($root, [Config::class => $this->configWith(['app' => $app])]);
```

`configWith()` symlinks every shipped config file into a temp directory and rewrites only the named ones, then returns a `Config` to hand back as a container override. Vary one file and inherit the rest — the variant then stays in step with the real configuration as it changes, and the test states exactly the one thing it varies.

### A test that asserts nothing is worse than no test

It also reports that the thing is covered. Two here were caught by re-deriving what they proved, not by failing:

- **`tests/Http/HomePageTest.php`** pinned that `Starting from $46.00/mo` never appears on the product grid — semaglutide's product-level price, which must never outrank the $50.00 first-variant price a buyer is actually charged. A later `bin/console theme:sync --apply` replaced `config/products.generated.php` with live channel data and semaglutide went with it, after which the pin passed against *any* template. It now injects the catalog it needs and **asserts up front that the two prices differ**, so a levelled catalog reports `the catalog under test cannot express the divergence` instead of going quiet.
- **`tests/Http/FunnelPagesTest.php`** asserted that seven funnel pages refuse indexing — satisfied entirely by the sitewide switch above, so all seven would have held on a marketing page. They now run against `indexableConfig()` with that switch off, leaving only the funnel rule to refuse them.

Before you write an assertion, ask what would have to break for it to fail. If the answer is "nothing in the code under test", assert the precondition as well.

---

## Operational traps

- **Reachability is `bin/console emr:ping`, never a curl at the token endpoint.** A credential-less `POST /v1/auth/api-credentials/token` returns **HTTP 500 by design** — it says nothing about whether the API is up, and has been read as an outage more than once. `emr:ping` fetches the configured channel and prints its name, product count and payment provider.
- **After any EMR permission change, run `bin/console cache:clear` before you test it.** The EMR bakes a permission snapshot into the bearer token, and the token is cached in `storage/cache/emr-token.json` until its `access_token_expiry` — roughly 24 hours. Until it is discarded, a permission granted server-side keeps answering 403, which reads as the grant having had no effect.
- **`config:validate` exits `2` on error, not `1`** — and a pipeline reports the last command's status, so `bin/console config:validate | tail` says `0` however many errors printed. Run it bare and check `$?`.
- **`bin/console theme:sync` is a dry run by default; `--apply` writes.** It overwrites `config/products.generated.php` and `config/channel.generated.php` with live channel data. **Both are gitignored**, because both are one channel's data and `channel.generated.php` additionally holds live provider credentials; the committed `*.generated.example.php` files next to them document the shape. Anything that has to read *the shipped catalog* therefore goes through `tests/Support/ShippedCatalog.php`, which resolves to the synced file when there is one and the example when there is not — a bare `require` of the generated path is red on a fresh clone. `config/products.overrides.php` is never written, which is why deployment-specific edits belong there. A test that needs a *stated* catalog — a product with a particular price shape — must inject one (`tests/Support/SampleCatalog.php`) rather than read the generated file, which is never the same catalog twice.
- **`npm run build` runs last, after the final template edit.** Tailwind scans `theme/templates` and `theme/js` (declared with `@source` in `theme/css/app.css`) and `build/build-assets.mjs` content-hashes the result into `public/assets/build/` with a `manifest.json`. Build before your last edit and you ship a hash for CSS that never saw the new markup. The script clears its own output directory first, so a deleted source file needs no `git rm`. Built assets are committed — servers never run Node.
- **Tailwind v4's preflight sets interactive controls to `cursor: default`.** `theme/css/app.css` restores `cursor: pointer` once, in its base layer. Do not "fix" this again per-control with `cursor-pointer`; the base-layer rule is the fix and duplicating it hides that.

---

## Rules that protect correctness

- **Commit with explicit paths on both halves — `git add -- <paths> && git commit -m "…" -- <the same paths>` — never a bare `git commit`.** More than one person or process may share this worktree, and a bare commit has swallowed another's staged work.
- **Grep before building on a summary.** A summary of a rule is not the rule, and the gap between them is exactly where the bug lives. Open the file. This has been wrong here more than once.
- **Record what a system actually returns before designing against it.** The EMR and the payment provider both disagree with their own documentation, and both do it silently. `docs/INTEGRATION-NOTES.md` exists for that reason: design from the recording, and add to it when you find the next divergence.
- **Ask who else touches what you just changed.** Fixes here have repeatedly needed a sibling fix in the other place that reaches the same column, the same config key or the same rule. Grep the identifier before calling it done.

---

## Where things live

`core/` is the application (`AsterMD\Storefront\`, PSR-4, wired in `core/Bootstrap/AppFactory.php`); `theme/` holds Twig templates, CSS and JS; `config/` holds per-deployment settings; `tests/` mirrors `core/`. Entry points are `public/index.php` and `bin/console`, with schema in `database/migrations/` and server configs in `deploy/`.

Read `docs/ARCHITECTURE.md` before changing anything structural — it is the map, and this file does not repeat it. `docs/OPERATIONS.md` covers running the thing, `docs/INTEGRATION-NOTES.md` what the external systems really do, and `docs/SPEC-REFERENCE.md` resolves the rule citations.
