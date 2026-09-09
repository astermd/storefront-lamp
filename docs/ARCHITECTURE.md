# Architecture

How the AsterMD storefront is put together, for a developer about to change it.

This is a PHP 8.5 application on Slim 4, Twig and PDO, served by a single front controller.
It sits between two systems it does not own — the AsterMD EMR and a payment provider — and
sells prescription and over-the-counter treatments through a guarded funnel: browse, cart,
questionnaire, identity check, checkout, post-purchase upsells, receipt.

Two boundaries run through the whole codebase and explain most of its shape:

- **`core/` is update-safe, `theme/` and `config/` are client-owned.** Every file under
  `core/` carries a header saying local edits are lost when core is updated. Customisation
  happens in templates, in CSS, and in the override layer of the configuration files.
- **Every external system sits behind an interface with a Null implementation.** Nothing
  above a port knows the name of the system behind it, and the container decides which
  implementation is bound. This is [the single most important idea in the codebase](#ports-null-implementations-and-the-container-seam).

Source files cite behaviour rules by id — `[24.2]`, `[20.1]`, `[13.32]`. Those ids resolve
in `docs/SPEC-REFERENCE.md`, which is the only thing in the repository that resolves them.
A few appear below where a decision is otherwise hard to place; the code itself is where
they are dense.

---

## Repository layout

```
public/            document root — nothing else is web-reachable
  index.php        front controller (requires the autoloader, builds the app, runs it)
  assets/build/    content-hashed CSS/JS, committed, plus manifest.json
  assets/media/    product images localized by theme:sync

core/              namespace AsterMD\Storefront — update-safe, ~23 namespaces
theme/             client-owned: templates/ (Twig), css/ (Tailwind v4 source), js/
config/            site config, the funnel table, the route table, catalog layers
database/migrations/   versioned, ordered, recorded
bin/console        Symfony Console entry point (migrations, sync, sweeps, probes)
build/             the asset build script (Node, dev-only; its output is committed)
storage/           logs/, cache/ (twig, teleforms, emr-token, health), sessions/, database/
deploy/            nginx and Apache samples
tests/             PHPUnit, mirroring the core/ namespace tree
```

`storage/` and `config/` live outside the document root and are additionally denied at the
web-server level by the shipped `deploy/` configs.

---

## Request lifecycle

```
  nginx / Apache
      │  everything that is not a real file → public/index.php
      ▼
  public/index.php
      │  require vendor/autoload.php
      ▼
  AppFactory::create($rootDir)                     core/Bootstrap/AppFactory.php
      │  1. Dotenv (immutable, safeLoad) → $_ENV
      │  2. Config::load(config/, $_ENV)
      │  3. build the PHP-DI container: every binding a closure
      │  4. apply $containerOverrides   ← the test seam
      │  5. hand the container to Slim, register middleware, load config/routes.php
      ▼
  $app->run()
      │
      ▼
  ┌─ error boundary ────────────────────────────────────────────────┐
  │ ┌─ TwigMiddleware ────────────────────────────────────────────┐ │
  │ │ ┌─ the pinned pipeline (9 slots, see below) ──────────────┐ │ │
  │ │ │ ┌─ Slim routing ──────────────────────────────────────┐ │ │ │
  │ │ │ │  controller  →  Twig template  →  ResponseInterface │ │ │ │
  │ │ │ └─────────────────────────────────────────────────────┘ │ │ │
  │ │ └─────────────────────────────────────────────────────────┘ │ │
  │ └─────────────────────────────────────────────────────────────┘ │
  └─────────────────────────────────────────────────────────────────┘
```

`public/index.php` holds no logic at all — it is one `require` and one call. Everything the
application knows about how its pieces fit together lives in `AppFactory::create()`, which
is the one place all of them are allowed to know about each other. Everything else receives
its collaborators through the container.

Controllers are thin. They read the request, ask a service, and render a template; no
controller makes an EMR or provider call directly.

### Lazy by construction

Every container binding is a closure, and each middleware slot is registered wrapped in
`LazyMiddleware` rather than as a resolved instance. The pipeline's *order* is fixed at
bootstrap; its *construction* is not. Resolving middleware eagerly would pull the whole
dependency graph — including the database connection — into bootstrap, which runs *before*
the error middleware exists. An unreachable database at that moment is an unhandled fatal:
no themed error page, no request id, and `/health/` unable to answer with `database: false`
at exactly the moment an operator is asking why the site is down.

`SessionRepository` and the other repositories take their connection as a closure and open
it on the first query rather than in the constructor, so *building* a repository costs
nothing. That is what lets a visitor add to their cart — which lives in the PHP session —
with the database flat on its back.

### Error routing

Registered outside the pinned pipeline, because it has to wrap the pipeline to catch what
the pipeline throws. Three tiers, checked in order:

| Condition | Response |
| --- | --- |
| `HttpNotFoundException` | themed `pages/error-404.twig`, registered explicitly because Slim's routing middleware throws before any handler runs |
| any other `HttpSpecializedException` (405, 400, 403 …) | themed `pages/error-http.twig`, deliberately **not** logged — malformed or disallowed requests are not application faults |
| anything else | logged to `storage/logs/app.log` with a generated request id, and the same id shown on `pages/error-500.twig` so a bug report can be matched to the log line |

Every error response is created with `X-Robots-Tag: noindex, nofollow` already on it. The
boundary sits outside `SeoMiddleware`, so a response it renders never passes back out
through the middleware that would otherwise add the header; the value comes from
`RobotsPolicy::REFUSED`, the same constant the error templates render their meta tag from,
so the two halves cannot disagree.

The unhandled-exception log line records the request **path only, never the full URI** —
query strings carry tracking payloads and resume identifiers, and key-based redaction
cannot see inside a URI string.

---

## The middleware pipeline

`AppFactory::MIDDLEWARE_ORDER` is the contract: a list, **outermost first**. Slim wraps
LIFO, so registration iterates the list in reverse. `tests/Bootstrap/MiddlewareOrderTest.php`
enforces it in both directions — the declared list against an assertion, and the declared
list against the `X-MW-Trace` headers an actual request accumulates on the way out (which,
being appended innermost-first, must reverse to exactly the declared order). A silent
reordering would change session, CSRF and indexing semantics without any single
middleware's own tests noticing.

```
outermost
    │
 1  CanonicalUrlMiddleware      one-hop 301 to the canonical URL form
 2  SeoMiddleware               publishes page metadata; writes X-Robots-Tag
 3  SessionMiddleware           starts the native PHP session
 4  RetiredSessionMiddleware    clears the analytics cookie of a finished journey
 5  AttributionMiddleware       resolves the analytics session; first-touch capture
 6  CsrfMiddleware              mints the token; rejects unauthenticated mutations
 7  JourneyStateMiddleware      writes durable journey state back, once
 8  StepGuardMiddleware         enforces funnel step preconditions
 9  TemplateGlobalsMiddleware   publishes Twig globals before the handler runs
    │
    ├── Slim routing middleware  (added first, therefore innermost)
    ▼
 route handler
```

`TwigMiddleware` and the error boundary are registered *after* the loop and therefore sit
outside slot 1. Slim's routing middleware is registered *before* it and sits inside slot 9 —
which is why the step guard matches steps by path rather than by route argument: there is
no matched route to read yet.

### Why the positions are what they are

**1 → 2, canonicalisation before SEO.** The canonical URL `SeoMiddleware` publishes has to
be built from the canonicalised path, so canonicalisation must already have run.

**2 outside everything else.** `[24.2]` requires the indexing directive to be enforced
twice — as a response header *and* as a meta tag — from one value. This middleware is the
header half and the source of the value the meta half renders. It has to reach every
response the application produces, including the ones the session, journey and step-guard
slots redirect away before a controller sees them.

**3 outside 6.** CSRF needs somewhere to keep its token, and that is the native PHP session.

**4 outside 5, and this is load-bearing twice over.** The analytics cookie is issued on the
way *out* by `AttributionMiddleware`, and a browser applies `Set-Cookie` headers in the
order it receives them — a clear written inside attribution would be overwritten by the very
re-issue it is cancelling. And reading the retirement flag *after* attribution has run means
a request that re-minted its session reads the fresh journey, which carries no flag, so a
re-mint is left alone instead of being cleared into a mint loop.

**5 outside 6 and 7.** Attribution is captured before anything reads it, and the state it
mutates is still persisted on the way out.

**6 outside 7.** A request rejected for a bad CSRF token never writes journey state —
correct, because such a request mutated nothing.

**7 outside 8 and 9.** The save-back happens only after the handler is done with the state
it was given. Its flush sits in a `finally`, so a crashing handler still leaves the
visitor's progress recorded: the error page is a better outcome than the error page *plus*
a lost step.

**9 innermost, and it runs before the handler.** The globals published on a mutating request
describe the cart as it looked on the way *in*. That is safe only because `CartController`
answers every mutation with a 303 redirect and renders nothing itself — the response that
would show a stale drawer is never produced, and the GET that follows rebuilds the globals
from the cart the handler actually saved. Every mutation response being a 303, with no
exception, is the invariant that keeps this true, and it has its own test.

### Degradation

Slots 5, 7, 8 and 9 are error boundaries. Analytics session creation is a
must-degrade-silently dependency: a visitor must get their page even from a storefront whose
database is unreachable or unmigrated. Each of those four receives its collaborators as
closures rather than instances, so that *building* them happens inside the same boundary
that catches them throwing — a slot that cannot be constructed has to degrade exactly like
one that throws mid-resolution.

`RetiredSessionMiddleware` fails open in every direction: an unreachable journey store, an
unresolvable path, a flow with no receipt step each leave the cookie alone. The harm in that
direction is a stale analytics session; the harm in the other is a buyer losing their
receipt one request after paying for it.

---

## The namespace map

Twenty-three namespaces under `core/`, grouped by what they are for rather than
alphabetically.

### Wiring and delivery

| Namespace | Owns |
| --- | --- |
| `Bootstrap` | `AppFactory` — the container, the pinned pipeline, error routing, route loading. One class. |
| `Http\Controller` | Thin HTTP adapters. `PageController` renders a template named by a route argument, so a static page needs no class of its own. |
| `Http\Middleware` | The nine pinned slots plus `LazyMiddleware`. |
| `Support` | Cross-cutting utilities with no home elsewhere: `Config`, `Url` (path canonicalisation), `AssetManifest`, `OperatorLog`, `CardScrubber`, `RequestContext`, `RateLimiter` / `DatabaseRateLimiter`, `PhpArrayExporter`, `FailureDigest`. |
| `Console` | Symfony Console commands — thin wrappers over the services below. |

### The domain

| Namespace | Owns |
| --- | --- |
| `Domain` | Pure rules over a cart and a catalog: `Cart`, `CartLine`, `CartRules`, `FunnelRouter`, and the `ProductCatalog` / `StepRouter` read ports. No I/O, no framework types. |
| `Funnel` | The funnel as data: `FlowDefinition`, `StepPreconditions`, `FunnelRules`, `FurthestStep`. |
| `Journey` | Durable server-side journey state: `JourneyState`, `JourneyStore`, `CartStore`, `SessionResolver`, `SessionOptions`. |
| `Catalog` | The catalog pipeline: `CatalogBuilder` (from an EMR payload), `CatalogMerger` (overrides over generated), `CatalogProvider` (the runtime entry point), `CatalogValidator`, `MediaLocalizer`. |
| `Attribution` | `_amd` payload decryption, alias normalisation, first-touch capture, derived source/device fields. |

### The funnel's stages

| Namespace | Owns |
| --- | --- |
| `Forms` | The intake questionnaire engine: teleform metadata and definition fetch, caching, field rendering models, rule evaluation, answer validation, disqualification, lead writing. |
| `Verification` | The identity-check step: `VerificationStep`, `IdentityVerificationSequence`, `VerificationPlacement`, `IdentityVerdict`. |
| `Checkout` | The checkout decision surface: `CheckoutService`, buyer validation, consents, order bumps, promotions, totals, idempotency, order recording, `PostChargeGuard`. |
| `Payment` | The provider boundary: the `PaymentAdapter` contract, `AdapterCapabilities`, `AdapterRegistry`, `PaymentCredential`, and the `Vrio/` adapter. |
| `Upsell` | Post-purchase offers: `Upsells` (configuration), `UpsellQueue` (where a journey has got to), `UpsellService` (the charge). |
| `Completion` | What reaching the receipt does: the completion actions, once, then teardown. |

### The outside world

| Namespace | Owns |
| --- | --- |
| `Emr` | Orchestration over the AsterMD SDK: `ClientFactory` (credentials from `$_ENV`, file-backed token cache), the session / cart / verification gateways, `CartMirror`. |
| `Repository` | The only code that touches the database: `SessionRepository`, `OrderRepository`, `EventRepository`, `CheckoutAttemptRepository`, `RateLimitRepository`. |
| `Database` | `ConnectionFactory` (the one place engines differ) and `Migrator`. |

### Presentation

| Namespace | Owns |
| --- | --- |
| `Seo` | `RobotsPolicy`, `MetaResolver`, `PageMeta`, and the `StructuredData/` emitters. |

### Operating the thing

| Namespace | Owns |
| --- | --- |
| `Observability` | The `Boundary` enum, `BoundaryTimer`, `Instrumentation`, the seven `Instrumented*` decorators, `Measured`, `CorrelationContext`. |
| `Reconciliation` | The forward sweep (orders the EMR was never told about) and the reverse sweep (provider orders with no local row). |
| `Abandonment` | Which journeys have stopped moving, and what state each stopped in. |
| `Retention` | `RetentionPolicy` and the sweeps that expire aged rows and log files. |

---

## Ports, Null implementations, and the container seam

**This is the pattern the rest of the codebase is built on.** Every external system sits
behind an interface. Each interface has a real implementation that talks to the system and a
**Null implementation that talks to nothing**, and the container decides which one is bound.

There are thirteen interfaces under `core/`; these are the ones with a Null counterpart:

| Port | Real | Null |
| --- | --- | --- |
| `Emr\SessionGateway` | `EmrSessionGateway` | `NullSessionGateway` |
| `Emr\CartGateway` | `EmrCartGateway` | `NullCartGateway` |
| `Emr\VerificationGateway` | `EmrVerificationGateway` | `NullVerificationGateway` |
| `Forms\TeleformGateway` | `EmrTeleformGateway` | `NullTeleformGateway` |
| `Forms\IntakeGateway` | `EmrIntakeGateway` | `NullIntakeGateway` |
| `Forms\LeadGateway` | `EmrLeadGateway` | `NullLeadGateway` |
| `Verification\IdentityGateway` | `EmrIdentityGateway` | `NullIdentityGateway` |
| `Payment\PaymentAdapter` | `Vrio\VrioAdapter` | `NullPaymentAdapter` |
| `Checkout\OrderRecorder` | `DatabaseOrderRecorder` | `NullOrderRecorder` |
| `Checkout\CheckoutEventReporter` | `EmrCheckoutEventReporter` | `NullCheckoutEventReporter` |

The remaining three are read ports with no remote system behind them:
`Domain\ProductCatalog` (satisfied by `CatalogProvider`), `Domain\StepRouter` (satisfied by
`FunnelRouter`, declared as a port so the guard's branching can be tested against a router
that names any step), and `Seo\StructuredData\Emitter`.

### What binds the Null ones

Three configuration switches, all read inside `AppFactory::create()`:

- **`app.session.analytics`** governs the EMR gateway family — session, cart, teleform,
  intake, lead. `config/app.php` derives it as `APP_ENV !== 'test'`, so **under the test
  suite the whole EMR family is Null-bound and no test run can reach the network or write
  to the dev EMR.** Tests that exercise session or intake flows inject their own gateway
  through the container instead.
- **`verification.enabled`** governs `IdentityGateway`. Off as shipped, and therefore always
  off under tests. It deliberately does *not* follow `app.features.emr_verification`: the
  two govern different resources that happen to share an EMR endpoint family, and tying them
  together would mean an operator could not have the checkout address check without also
  running identity checks that, as recorded against the live integration, currently refuse
  everybody.
- **`app.features.emr_verification`** governs `VerificationGateway` (email deliverability
  and address checks at checkout). Off by default, because the credential this storefront
  ships with is refused for that whole resource — and a check that always fails is worse
  than none, since it would either block real buyers or teach an operator to ignore it.

`PaymentAdapter` is a fourth case and works differently: it is not switched by environment
but resolved from the synced provider category (see [Payment](#payment)), falling back to
`NullPaymentAdapter` when nothing is configured. Payment tests substitute a transport at the
adapter's own seam, or substitute the whole adapter through the container.

### Null is a behaviour, not a stub

A Null implementation returns the answer that means "this degraded exactly as the funnel
already expects", not a blank one — and the difference is a design decision each of them
states:

- `NullSessionGateway` reports "no session could be created" and "this session is not
  known", both states the funnel already has to handle, so switching analytics off degrades
  the storefront rather than changing its behaviour.
- `NullCartGateway` and `NullIntakeGateway` report **success** unconditionally. A deployment
  with analytics off must behave exactly like one whose mirror always succeeded; reporting
  `false` would be read as "the save failed" and could surface a retry or a warning to a
  visitor whose form is working exactly as configured.
- `NullIdentityGateway` answers **inconclusive** to everything, and never `passed`. A
  deployment may run the identity step blocking, and a disabled gateway reporting a pass
  would let anyone through the moment an operator switched identity checks off — turning "we
  are not checking" into "everyone checks out". Inconclusive is also the same answer the live
  gateway gives when the integration is inactive, so switching the feature off changes what
  the step *knows* and never what the funnel *does*.
- `NullPaymentAdapter` declares no promotion support (so the promo control is hidden) and
  turns every placement into a decline naming the configuration problem without exposing it
  to the buyer. An unconfigured storefront renders and browses normally and fails only at
  the point money would move, rather than 500ing on the checkout page.

### The container is the seam

```php
AppFactory::create(string $rootDir, array $containerOverrides = []): App
```

`$containerOverrides` is a map of container id to value, applied **after every default
definition and immediately before the container is handed to Slim**. It is the documented
test seam and the only one: a test substitutes a fake gateway, a fake adapter or an
already-migrated PDO without a second bootstrap path. Production callers pass nothing —
`public/index.php` calls `create($rootDir)` with one argument.

The seam only reaches bindings that resolve their configuration *from the container*. Most
of `create()` closes over the `$config` loaded at the top of the method, which is
deliberate — a partial override that silently changed a database path or an environment name
would be a worse seam than none.

**Twenty-five bindings resolve `Config::class` from the container instead**, and that is the
seam's real blast radius. Four of them are what let a test say anything about an enabled
identity step — `FlowDefinition`, `IdentityGateway`, `StepPreconditions`, `FunnelRouter` — but
the set also includes `PaymentAdapter`, `CheckoutService`, `UpsellService`, `Completion`,
`CheckoutEventReporter`, `Consents`, `OrderBumps`, `Upsells`, `DatabaseRateLimiter`,
`RetentionPolicy`, `RobotsPolicy`, `MetaResolver`, `RobotsController`, `HealthController`,
`IntakeController`, `DefinitionCache`, `Disqualification`, `VerificationStep`,
`CatalogProvider`, `EmrClientFactory` and `TemplateGlobalsMiddleware`.

So a test that overrides `Config::class` to vary one flag is re-resolving payment, checkout and
retention from the varied configuration too. That is usually harmless and occasionally the
point, but it is worth knowing before reading a surprising result: the override is broad, not
surgical.

A closure definition resolves to one instance per container, which is PHP-DI's default and
what makes a binding request-scoped. Two rely on that explicitly: `JourneyStore`, because the
resolver and the save-back middleware must see the same instance or the save-back would flush
an empty state; and `CartStore`, because a controller and the templates rendering its result
must see the same cart or they will disagree about what is in it.

---

## Observability decorators

`core/Observability/` wraps the same ports to record outcome and latency. `Instrumentation`
is the one class that does the wrapping — seven methods, one per port — rather than ten `new`
expressions scattered through the container or ten edits at call sites.

```
  container  →  InstrumentedSessionGateway  →  EmrSessionGateway  →  the EMR
                └─ implements SessionGateway ─┘
```

A decorator sits **between the container and the port**. Every one implements the interface
it wraps and returns exactly what the port returned, so substituting one is invisible to
every caller — which is what makes it safe to leave switched on in production and what stops
the instrumentation from becoming the failure.

The reason the timing lives here rather than at call sites is that the storefront degrades
silently by design: a broken dependency does not surface as an error, it surfaces as a funnel
that quietly stops converting. An EMR that has stopped minting sessions looks identical to a
quiet afternoon unless the outcome is counted. `Boundary` is an enum naming every external
call the storefront makes, so the set is enumerable rather than discoverable by grepping for
a logging call, and one dependency cannot end up logged under two spellings.

**Anything reflecting into a port must expect to peel one.** `Instrumentation` is applied to
the session, cart, teleform, intake and lead gateways, to the payment adapter, and to the
checkout event reporter. A test asserting on what the innermost object holds walks the
`inner` property until it finds what it is looking for, rather than naming a decorator class
— which keeps the assertion honest through the next decorator somebody adds.

Not every decorator is observability. `RecordingIntakeGateway` wraps whichever
`IntakeGateway` the analytics flag produced and writes the local audit line either way,
because the EMR half is analytics and the storefront's own record is not.

`CorrelationContext` resolves the session identifier **per log line** rather than capturing
it, because the session is minted partway through a request and a value taken at any fixed
moment would be wrong on one side of it.

`Measured` carries the four answers a monitored figure can have — `measured`, `floor`,
`not_checked`, `unavailable` — because zero and "could not be counted" are different answers,
and a status surface that renders them the same reports an all-clear on exactly the runs that
could see nothing.

---

## Configuration

`core/Support/Config.php` loads every `config/*.php` file into a read-only bag keyed by
basename, and `get('app.seo.default_title')` dot-traverses it. Each file receives the `$env`
array (loaded from `.env` by Dotenv, immutably, so a value already in the environment wins)
and can read from it.

The top-level key is resolved **greedily** — longest dotted prefix first — because file
basenames themselves contain dots: `config/products.generated.php` is keyed
`products.generated`. One caution follows from that: a file named `<x>.<y>.php` shadows a
nested key `<y>` inside `x.php`.

| File | Owned by |
| --- | --- |
| `app.php` | client — site identity, database, EMR host, session, attribution keys, SEO, feature flags, debug |
| `funnel.php` | client — the step table: which URL is which named step, and what it requires |
| `routes.php` | client — the route table, returning a `function(App $app)` |
| `verification.php` | client — identity-check placement, blocking, and which checks run |
| `consent.php`, `cross-sells.php`, `upsells.php`, `payment.php`, `intake.php`, `retention.php`, `abandonment.php` | client |
| `products.generated.php` | **machine** — overwritten wholesale by `theme:sync`; gitignored, since it is one channel's catalog rather than the theme's. `products.generated.example.php` is the committed shape |
| `products.overrides.php` | **client** — never touched by a sync |
| `channel.generated.php` | **machine**, merge-not-replace; gitignored, since it carries live provider credentials |

### Generated versus override

`bin/console theme:sync` fetches the configured EMR channel, builds a catalog from it, and
rewrites `config/products.generated.php` in full. `config/products.overrides.php` is the
client's, and no sync writes to it. `CatalogProvider` layers the second over the first at
runtime through `CatalogMerger` and memoizes the result for the life of the instance, so a
container singleton merges once per request no matter how many controllers and templates ask
for products.

The merge rules, all in `CatalogMerger`:

- **Products are matched by `emr_product_id`, never by slug or name** (`[2.2]`). Slugs are
  allowed to change — an override may set one, which re-keys the product in the merged map
  *and* updates its `slug` field — so a slug cannot be the join key.
- **Every field other than `variants` replaces the generated field wholesale.** No deep
  merge: an overridden `categories` list replaces the whole list, which keeps the result
  predictable.
- **`variants` merge per variant id.** Fields land on the matching variant; variants not
  mentioned are untouched, and none are dropped or reordered.
- **An override id matching no generated product** is added as a net-new product if it is a
  full definition (at minimum `slug`, `name`, `kind`, `variants`), and silently skipped
  otherwise. Skipped ids are collected on the merger instance and readable via
  `lastIgnoredOverrideIds()` — a second return channel rather than a wrapper type, so the
  signature stays `merge(): array`.
- **Slug collisions are not silently swallowed.** A re-key or a net-new product landing on a
  slug another product occupies throws, naming the slug and both colliding EMR ids, because
  silently overwriting one product with another is catalog data loss.

The override layer is also where fields the EMR payload has no room for live — `geo_blocks`,
`requires_prequalification`, `prequalification_teleform_id` — and where the SEO description
override lives, which matters because every product this EMR syncs today carries an empty
description.

---

## Persistence

### Connection

`core/Database/ConnectionFactory.php` is the one place database engines differ; everything
above it speaks portable SQL through PDO. Supported drivers are **sqlite (default), mysql,
pgsql**; anything else is refused at construction. PDO is configured with exception error
mode, associative fetch, and emulated prepares off.

For SQLite, a *relative* configured path is rooted at the application directory, so a
deployment can say `storage/database/app.sqlite` without knowing where it is installed.
Three spellings survive untouched: an absolute path, `:memory:`, and a `file:` URI. That
exemption is load-bearing — rooting `:memory:` produces a real 4 KB file named `:memory:` in
the application root, so an operator reaching for SQLite's own name for an ephemeral database
would quietly accumulate a persistent one.

### Migrations

`core/Database/Migrator.php` applies pending files exactly once each, tracked in a
`migrations` table keyed by **filename** — not by contents or hash. A migration is a plain
PHP file returning `function(PDO $pdo, string $driver)`, and the driver is passed through so
a migration can special-case DDL differences itself. Files run in filename sort order and are
recorded immediately after they run, so a failure partway through a batch leaves everything
before it marked applied and everything from that point untouched; re-running resumes rather
than re-applies.

Two consequences worth knowing before you write one:

- **Every statement must be safe to re-run.** There is no portable
  `ADD COLUMN IF NOT EXISTS` across all three engines, and MySQL before 8.0.29 has no
  `CREATE INDEX IF NOT EXISTS` — so column additions and index creations are individually
  wrapped in `try`/`catch(\PDOException)`.
- **A correction to an already-applied migration is a statement nobody executes.** Because
  files are recorded by name, every environment that already ran a file has recorded it;
  editing it changes nothing anywhere. A data fix belongs in a new file. `0003` exists partly
  for exactly that reason.

MySQL/InnoDB also silently ignores an inline column-level `REFERENCES` clause — it parses but
never creates the constraint — so foreign keys are declared as table-level clauses.

### The schema

| Table | Holds |
| --- | --- |
| `sessions` | one row per analytics session: `session_uuid` (PK), `opportunity_id`, `journey_state` JSON, `attribution` JSON, `created_at`, `updated_at` |
| `orders` | `session_uuid` (nullable FK), `provider_reference`, `anchor_slug`, `amount_cents`, `discount_cents`, `currency`, `status`, `treatment_reference`, `verification_status` / `verification_at`, buyer name/email/territory, `payment_method`, `card_last_four`, `promotion_code`, `idempotency_key`, `provider_category`, `is_upsell`, `placed_at` |
| `order_lines` | `order_id` FK, `slug`, `name`, `kind`, `provider_offer`, `provider_item`, `unit_price_cents`, `quantity`, `sent_to_provider` |
| `order_consents` | `order_id` FK, `consent_key`, `granted`, `copy_version`, `copy_shown`, `recorded_at` — the copy as shown, not a reference to it |
| `events` | append-only local audit trail: `session_uuid`, `name`, `payload` JSON, `created_at` |
| `checkout_attempts` | `idempotency_key` (**UNIQUE**), `session_key`, `state`, `outcome`, `created_at`, `completed_at` |
| `rate_limits` | `bucket`, `identity`, `hits`, `window_start` (integer), unique on `(bucket, identity)` |

**Money is integer cents everywhere.** `amount_cents`, `discount_cents`,
`unit_price_cents`, every price in the catalog, every total in the domain. There is exactly
one conversion to dollars in the codebase, at the EMR checkout-event boundary, and it has
its own test pinning it there — the EMR documents floats and accepts an integer cents value
verbatim, so a skipped conversion silently multiplies every reported order by a hundred and
nothing downstream complains.

**`orders.session_uuid` is nullable**, and that is deliberate: an analytics-off deployment,
or one whose EMR session could not be minted, must still be able to take an order, and
inventing an identifier to satisfy a NOT NULL constraint is the synthetic identifier the
storefront is forbidden to produce. The same absence is recorded literally in the `events`
table rather than filled in.

**`checkout_attempts.idempotency_key` being UNIQUE is the entire mechanism** that stops a
double charge. The claim is a conditional insert, so two concurrent submits race on the index
and exactly one wins. The payment provider offers no idempotency of its own — an identical
payload posted twice creates two orders and charges both — so that index is what stands
between a double-click and a double charge. The state vocabulary has three values rather than
two because `claimed` may be taken over by a retry and `sent` may not, and a row that cannot
say which it is must be read as `sent`.

**Nothing stores a card.** `orders.card_last_four` is the only card-derived column in the
schema, and four digits are what a receipt and a support call need.

**Timestamp columns are `VARCHAR(32)` holding `gmdate('c')`** — fixed-width UTC. That is what
makes lexicographic order chronological order, and therefore what makes a plain string
comparison a valid range query for every retention, abandonment and reconciliation sweep. It
is only true because *every* writer uses that format; a writer that did not would silently
break the range scans rather than fail. Note the one column that is not a string:
`rate_limits.window_start` is an integer, because that table was written for a different job,
and the sweeps compare it differently on purpose.

Indexes exist where a scheduled sweep reads: `orders.treatment_reference` (the forward
reconciliation predicate), `orders.placed_at` and `orders.provider_reference` (the reverse
sweep), `sessions.updated_at` (abandonment and session expiry), `checkout_attempts.created_at`
and `rate_limits.window_start` (pruning), `events.created_at` (audit-trail expiry — the
largest table on a busy deployment by a wide margin). `sessions.created_at` deliberately gets
none: a journey's age for retention purposes is when it last moved, not when it began.

---

## Journey state

The **journey** is the durable, server-side record of one visitor's pass through the funnel,
keyed by the analytics session uuid and stored in `sessions.journey_state`. Browser storage
is a cache of that row and never the system of record, because cross-device resume and the
abandonment sweep both have to read it with no browser present.

```
  SessionResolver          decides which session this request belongs to
        │                  resume parameter  >  cookie  >  mint
        ▼
  JourneyStore             loads the row once, hands the same instance to everyone,
        │                  writes it back once (JourneyStateMiddleware)
        ▼
  JourneyState             a mutable bag: cart, form answers and statuses,
                           furthest step, buyer, consents, promotion, placed
                           orders, reusable payment handle, upsell queue /
                           cursor / outcomes, receipt snapshot, verification
                           verdict, completion timestamp, retirement flag
```

**Resolution order is the contract.** A resume parameter beats the cookie, because it comes
from a message the storefront itself sent and is authoritative over whatever the browser
currently holds. The cookie beats minting, because session creation is idempotent per
browser. Minting happens as soon as a visitor arrives rather than at the first cart action,
which is why the create payload carries the attribution captured moments earlier. A cookie
naming a session the EMR says it does not know is discarded and the journey transplanted onto
a freshly minted one — keeping it would leave every EMR call failing and swallowed for the
life of the cookie, which surfaces as nothing at all.

An unresolvable session resolves to **null** and the journey continues untracked. A malformed
resume value is ignored in silence rather than shown as an error; the visitor simply lands at
the funnel entry point.

### The fingerprint-gated save-back

`JourneyStore::flush()` compares a fingerprint taken at load time against the state as it
stands at the end of the request, and **writes nothing when they match**. The comparison
replaces a dirty flag deliberately: the failure mode of a missed dirty flag is silent data
loss on a step the visitor believes they completed.

That gate is what makes **`sessions.updated_at` a true "last moved" rather than "last seen"**.
A visitor who reloads the checkout page ten times does not reset their own abandonment clock;
one who adds a line to their cart does. The abandonment sweep and the retention sweep both
depend on that being true.

Handlers mutate the state object they were given and never touch a repository. That is what
keeps a handler from being clobbered by a save-back it did not know was coming, and it is why
`JourneyStateMiddleware` is the only place journey state is persisted. A failed write is
logged and swallowed — a lost analytics write must not become the visitor's problem — but a
write that matched *no row* is raised as `JourneyWriteFailed` rather than returned quietly,
so it leaves a line an operator can find.

### The cart lives in two places

`CartStore` keeps the working copy in the **native PHP session** and mirrors every save into
durable journey state. Two stores, because each covers the other's hole: durable state is
keyed by a session uuid, and there are ordinary deployments with no uuid at all — so a cart
kept only there could never be written, and a storefront that cannot take an order because
tracking is down is exactly what the degradation rule forbids. The PHP session, conversely,
is browser-scoped and cannot serve cross-device resume.

Precedence is decided by a session stamp stored beside the lines rather than by a flag
someone has to remember to set. When the stamp does not match the session this request
resolved to, the browser is carrying a cart from a different session — a resume link, or a
re-minted one — and the durable copy for the *current* session wins, unless there is nothing
there, in which case the anonymous cart is adopted into the new session rather than thrown
away.

### One note before you extend `JourneyState`

`formAnswers` holds clinical answers, which are PHI. They are there because a questionnaire
has to be resumable across devices and sessions, which browser-scoped storage cannot do — but
it means the `journey_state` JSON column stores health information alongside funnel
bookkeeping. An answer must never be logged, never appear in an exception message, and never
be echoed into an analytics payload.

---

## The funnel

The funnel is **data**, not annotations on routes. `config/funnel.php` names each step, its
URL, and what must already be true before a visitor may enter it:

```
home  →  prequalification  →  intake  →  intake.medical  →  verify  →  checkout  →  upsell  →  receipt
 /      /intake/eligibility/  /intake/  /intake/medical/   /verify/   /checkout/   /upsell/  /thank-you/

                              not_eligible  (/not-eligible/ — a terminal off-ramp, reachable from anywhere)
```

Four pieces implement it:

**`FlowDefinition`** reads that table once. Every declared path is canonicalised at
construction, and every path handed to `stepForPath()` goes through the same
`Url::canonicalizePath()` the redirect middleware uses — so a case-different or slash-less
spelling of a guarded URL cannot slip past the guard by comparing unequal to what was
declared. `pathFor()` throws on an unknown step name, because a redirect target that
silently becomes `''` is a redirect to nowhere.

**`FunnelRouter`** (implementing the `StepRouter` port) answers "where should this visitor be
right now". It is deliberately *not* a forward-only table: the guard asks it about a visitor
who has just failed a precondition, so an answer ignoring what they had already finished
would send someone who completed a questionnaire back to the start of it. Every branch reads
journey state as well as the cart.

**`StepGuardMiddleware`** enforces preconditions in one place, so no funnel template carries a
guard of its own and none of them can be inconsistent about it. Its redirect target is the
routing decision, not "the first step with unmet preconditions" — with an empty cart *every*
funnel step is unsatisfied, and walking the list would send the visitor to a step that would
bounce them again. When the routing decision names the step the visitor is already on, the
guard fails closed: it redirects to `FlowDefinition::ENTRY_STEP` and logs
`funnel.guard_self_redirect`, because serving the unguarded page is never the safe branch.

**`StepPreconditions`** implements the named predicates the config file refers to —
`cart_not_empty`, `prequalification_satisfied`, `intake_satisfied`, `verification_satisfied`,
`plan_chosen_for_every_rx_line`, `order_placed`. Named rather than inline closures so the flow
stays readable data and an unknown name fails loudly: a mistyped requirement that quietly
evaluated true would leave a step unguarded and look exactly like a step with no requirements.

The router and the guard share one implementation of the rules they both need —
`FunnelRules` — because the guard decides whether a visitor may stay and the router decides
where they go instead, and a funnel where those two disagree either strands the visitor or
leaves a step unguarded.

### Three subtleties worth carrying

**The form preconditions are "satisfied *or not required*", not "completed".** A cart with
nothing to ask goes straight to checkout, and a channel that folds its eligibility questions
into the intake form authors no separate pre-qualification form at all — so demanding
completion of a form that does not exist would make checkout permanently unreachable rather
than guarded.

**A journey that could not be loaded is a third answer, not a synonym for "not satisfied".**
When session resolution fails, `JourneyStore::state()` returns null and every durable fact is
*unknowable* rather than known-false. Each precondition picks its own direction of failure for
that case: the questionnaire gates stay **shut**, because an outage does not make an
uncollected medical form safe, and nothing irreversible has happened to the visitor they stop;
`order_placed` **opens**, because by the time the receipt or the upsell is asked for the money
is taken and locking a buyer out of their own receipt is the worse failure. Someone holding a
prescription therefore does not reach checkout during an outage; someone holding a receipt
does.

**Upsell and receipt cannot be guarded on the cart at all.** The cart is cleared the moment an
order is placed (`[13.32]`), so by the time a buyer reaches either page it is empty and
indistinguishable from a visitor who never had one. The placed order recorded on the journey
is the only fact left — durable, server-side, and unforgeable from the browser, which a "just
bought something" flag in the PHP session would not have been.

### The one monotonic advance rule

`core/Funnel/FurthestStep.php` is the only writer of `JourneyState::$furthestStep`, and every
writer calls `advance()` rather than assigning — so "only ever forwards" is a property of the
class instead of a habit four call sites have to keep. A visitor who reached checkout and went
back to edit their questionnaire has not un-reached checkout.

Its `ORDER` constant declares the progression **here rather than reading it from the flow**.
`config/funnel.php` is the step *vocabulary* — every name in `ORDER` must be one of its keys —
but its array order is not a progression, and nothing in `FlowDefinition` treats it as one:
`not_eligible` sits between `checkout` and `upsell` there while being a terminal off-ramp
reachable from anywhere. Ranking by array position would make a monotonicity rule out of an
incidental detail of a config file, and a later reordering — a refactor by every appearance —
would silently change which steps can overwrite which. `not_eligible` is absent from `ORDER`
for the same reason it cannot be ranked: it is not a step anybody completes.

Two edge answers are decided rather than obvious. An unrecognised *candidate* is refused
outright, leaving the last true value standing, because writing a name the funnel does not
declare would put a step into the abandonment signal that nothing can turn back into a URL.
An unrecognised *current* value is overwritten, because it can only be a spelling from an
earlier release or a hand-edited row, and blocking on it would freeze the field permanently.

Every method is pure array work on an in-memory object — it opens nothing, reports nothing
and cannot throw — which is what lets a charge path call it without a guard around it.

---

## Payment

`core/Payment/PaymentAdapter.php` is the whole provider boundary. Every provider-specific
fact lives behind it: payload field names, status vocabularies, attribution slot maps,
credential handling. **Nothing above this interface may name a provider**, and adding one
must be an adapter plus a registry entry and nothing else.

### The contract declares capabilities

`capabilities(): AdapterCapabilities` is what the storefront asks before it renders anything,
so the UI adapts rather than discovering a missing capability at runtime. The declaration
carries `providerCategory`, `supportsPromotions` (false hides the promo control entirely),
`discountScope`, `credentialStrategy`, `collectionSurface`, `requiredConfigKeys` (what
`config:validate` checks before a deployment goes live), `routingHintKeys`, `pciPosture`
(what it reports to an operator), and `supportsRefund` / `supportsRecurring` /
`supportsOrderSearch`.

Two of those pairings are decisions rather than fields. `collectionSurface` travels with
`credentialStrategy` because they are one decision: tokenization only reduces PCI scope when
the card is collected in a surface the storefront does not control, so an adapter declaring
tokenization must also declare where its fields live. And `supportsOrderSearch` is declared
*and* gets a method, unlike refund and recurring which are declared with no method at all — a
declared slot with no caller is honest, a method nobody implements is not, and order search
has a caller in the reverse reconciliation sweep.

`place()` and `searchOrders()` **must never throw for a provider-side outcome.** A refused
card, a missing reference, a malformed response and a network failure are all a
`PlacementOutcome::declined()` with a buyer-safe reason — the visitor has to be able to see a
reason and retry, not hit an error page. For search, a failure is explicitly *not* an empty
result, because a sweep that read an outage as "no orders were lost" is the silent failure
reconciliation exists to end.

### The registry

`AdapterRegistry` maps provider category to adapter, and adapters are registered as
**factories rather than instances**, so building one — which reads credentials and constructs
an HTTP client — happens only for the category actually configured, and only when something
asks. A page that never reaches checkout must not pay for a provider client, and a
misconfigured provider must not break the home page.

The category is chosen from **synced channel data**, never named by a code path:

```php
$config->get('payment.adapter')
    ?? $config->get('channel.generated.payment_processor.provider_category', '')
```

`channel.generated.php` is written by `theme:sync`; the `payment.adapter` key is an operator
override for a deployment that has to pin one. An unconfigured or unknown category resolves to
`NullPaymentAdapter` and is logged once rather than throwing, and so does an adapter whose
factory fails to build — a channel whose payment processor has not been synced yet should
still serve its catalog.

### The Vrio adapter

`core/Payment/Vrio/` is the one shipped implementation. Nothing above it knows the provider's
name, field names, status vocabulary or its twenty tracking slots.

Its credential strategy is **reference-order**: an order is charged against a
`customer_id` + `customer_card_id` pair returned by an earlier placement, with no card number,
security code or expiry in the request. The handle is a *pair* and the provider enforces the
pairing itself — a card id whose customer does not own it is refused — which matters because
card ids are sequential, so that enforcement is the only thing between a guessed id and a
charge. The pair is therefore minted from the provider's own response and never accepted from
a request.

`VrioWireLog` decorates the provider's own default transport rather than replacing it, so
behaviour is identical whether the debug switch is on or off; a switch that changed what the
provider was sent would be worse than no switch.

### Where the card goes, and where it does not

**Card data never reaches the EMR.** The storefront charges at the payment aggregator and
*then* tells the EMR that an order exists. The EMR is told about treatments and funnel events;
the provider is told about money.

The card also never reaches storage. It arrives as a `PaymentCredential` and is handed to the
adapter, and that is the end of it. `JourneyState::$paymentCredential` holds it for the
duration of **one request only**: it is the single field deliberately excluded from both
`toArray()` and `fingerprint()`, so it can never be written into the `sessions` row. What
survives to pay for a later charge on the same journey is `$paymentHandle` — the opaque
reference the provider issued, which is not a card and is therefore allowed to be durable.
Assign it through `storeReusableCredential()`, which is where the card refusal lives;
`toArray()` repeats the refusal, only because a public property can be written past the
setter. A deployment whose adapter declares raw carry-forward instead has nothing to charge
an upsell against, and that is the correct trade — holding a card across requests to sell an
add-on would put the whole deployment in full PCI scope.

`PaymentCredential` intercepts four of PHP's five ways of turning an object into text —
`__debugInfo()`, `jsonSerialize()`, `__serialize()` — and the fifth, `var_export`, has no hook
and would emit every property. Nothing calls it on a credential, and nothing should.
`toStorable()` makes the rule structural rather than conventional: the only credentials that
can be written durably are the ones with no card in them.

Two layered defences protect the logs. `OperatorLog::redact()` is key-based and blanks values
under names the codebase's own structures use. `CardScrubber` is value-based and looks
*inside* strings — for the security code and expiry by the key they sit next to
(`cvv=737`, `ccexp=1230`), and for any 13–19 digit run that passes a Luhn check. The second
exists because a provider echoing `gateway_request_text` returns one opaque string with a PAN
somewhere in the middle of it, under a key no redaction list would think to name. Both sinks
that outlive the request — the operator log and the `events` table — apply the scrubber
themselves, so containment does not depend on a caller remembering to ask.

### The order of operations in checkout is the specification's

`CheckoutService::submit()` runs, in order: refuse a flood; re-price a stored discount code
against the cart actually in hand; validate the buyer and record consents; re-run the geo gate
against the freshly submitted territory; store the buyer *before* the provider call so a
decline or a crash still re-fills the form; claim the idempotency key; assemble one envelope
for the whole cart; record that the provider is about to be contacted; place it; and only then
capture the running totals *before* clearing the cart.

Four deliberate exceptions to degrade-silently live there — a blocking consent not granted, a
geo block, a checkout attempt that could not be recorded before the charge, and a discount
whose figure moved when re-priced. Each stops the order, because a payment nobody can
reconcile to a local record, or a total the buyer never saw, is worse for the buyer than being
asked to try again.

**Past the charge, nothing may reverse.** Once `place()` has returned, the money may have
moved, and from that point no failure of ours may reach the buyer as a failure — an error page
in front of someone whose card was just debited is the one outcome that leaves nobody with a
record of the order. Every write after the call runs inside `PostChargeGuard::run()`, which
logs what could not be written as a reconciliation item and lets the order stand. The same
guard covers the upsell charge and the completion actions, because two copies of that rule
would drift.

---

## The theme

`theme/` is client-owned and is never overwritten by a core update.

```
theme/templates/layouts/    base.twig, marketing.twig, funnel.twig, checkout.twig
theme/templates/pages/      home, treatments, product, intake/*, verify, checkout,
                            upsell, thank-you, not-eligible, legal/*, error-404/500/http
theme/templates/partials/   header/footer variants, cart drawer pieces, intake fields,
                            structured-data.twig
theme/css/app.css           Tailwind v4 source; design tokens at the top
theme/js/                   cart.js, checkout.js, intake.js, intake-engine.js, verify.js,
                            upsell.js, datepicker.js, script.js, vendor/
```

### Layouts

`base.twig` renders `<head>` and `<body>`, exposes a `page` block and a `content` block, and
defaults `page` to rendering `content` directly so a template extending it without a
marketing/funnel/checkout layout — the error pages — keeps working. The other three override
`page` wholesale with their own header, content and footer, and pull in the JS bundles that
surface needs.

`theme/css/app.css` opens with a design-token block that is the single reskinning point:
every colour, radius, shadow, spacing alias, font stack and transition used across the
storefront resolves back to a variable there. Reskinning means editing hex values, not
touching template markup.

### The asset build

`npm run build` runs `build/build-assets.mjs`, which compiles `theme/css/app.css` through the
Tailwind v4 CLI, copies each file under `theme/js/` (including `vendor/`), gives every output
an 8-character content hash, and writes `public/assets/build/manifest.json` mapping logical
name to hashed name:

```json
{ "app.css": "app.c579848a.css", "script.js": "script.27ba6a44.js", … }
```

The build is dev-only and **its output is committed**, so a server never needs Node.

`core/Support/AssetManifest.php` reads that manifest once per request and resolves a logical
name to `/assets/build/<hashed>`, which is what the `asset()` Twig function calls. A missing
or unreadable manifest, or a name with no entry, falls back to the logical name rather than
throwing — templates keep rendering with an unhashed URL instead of a build hiccup taking the
whole page down.

Alongside `asset()`, `TwigExtensions` provides `url(path, query)` (canonicalised, query-aware)
and the `money` filter, which takes **integer cents** and formats a display price.

### `base.twig` is the single writer of `<head>` metadata

A page contributes what it knows about itself by setting `page_title`, `page_description` and
`page_type` before the layout renders; everything it leaves unset falls through to the
configured default. Pages state plain text — escaping happens once, on the way out, in the
layout. Every `<title>`, description, canonical link, robots tag, Open Graph tag and Twitter
card is written there and nowhere else.

Single-writer matters most for the indexing directive. The value comes from the robots policy,
which is also what the response header and the sitemap derive from, so the three cannot
disagree. `noindex` remains available as a **page-level escalation** for a page that knows it
must not be indexed for a reason its path cannot express — the error pages are exactly that —
but it can only *refuse* indexing, never grant it.

Two conditional details in the layout are decisions rather than tidiness. A deployment with no
social image renders no image tag at all, because a card pointing at an image that does not
exist is what a crawler caches. And the structured-data partial is suppressed on a page that
escalated to `noindex` itself — an error page is served at any URL at all, and handing it the
site-level Organization/WebSite graph is noise a crawler may act on. It is gated on the
template's own escalation and *not* on the resolved directive, because with the master switch
on every page carries `noindex` and gating on that would strip structured data from the whole
site.

---

## SEO

`core/Seo/` has one governing idea: **each decision has exactly one writer, and everything
else derives from it.**

### `RobotsPolicy` — the single indexing decision

Built from `app.seo.robots_rules` (canonical path, optionally ending `*`, to directive) and
`app.seo.discourage_indexing`.

- `directiveFor($path)` returns the directive, or **null** when the path may be indexed
  freely. Null rather than an `index, follow` string, because the absence of a directive is
  what "indexable" means to a crawler; emitting the permissive form says nothing a crawler did
  not already assume while adding a tag that has to stay correct forever.
- `refusesIndexing($path)` asks whether the directive keeps the path out of an index
  altogether. **Having a directive is not the same as being refused** — `noarchive` asks a
  crawler to index and not cache; `nosnippet` asks it to index and quote nothing. Reading
  either as a refusal is not a harmless over-reach: `robots.txt` would translate it into a
  `Disallow`, which blocks the fetch, and a page that is never fetched is never indexed.
- `isIndexable($path)` — what the sitemap filters on — is the **negation of
  `refusesIndexing()`**, not of `directiveFor() === null`, for exactly that reason.
- `indexingDiscouraged()` answers about the deployment rather than any single path, because
  the master switch turns `robots.txt` into a blanket disallow rather than a list of
  exceptions.
- `discourage_indexing` is that master switch, above all of it: when on, every path is refused
  regardless of any per-route rule, so a staging deployment cannot leak into an index through
  one mis-set page. It defaults on outside production.

Rule matching is by specificity, not by config order: an exact path beats a prefix, and a
longer prefix beats a shorter one, so `/checkout/promo/` can be governed separately from
`/checkout/*` without the array order deciding the outcome.

`REFUSED` is a public constant (`noindex, nofollow`) — `nofollow` too, because a noindex
page's links are not endorsements — and it is what the error boundary stamps on error
responses.

### `MetaResolver` and `PageMeta`

`MetaResolver` builds every page's metadata from configuration and catalog data, so a client
gets correct titles, descriptions, canonical links and social cards without writing code. The
resolution order is the same for every field: **a per-page override, then the page's own data,
then the configured default.** The override layer comes from `config/products.overrides.php`,
which is what makes it survive a re-sync.

One consequence is load-bearing rather than theoretical: **every product this EMR syncs
carries an empty description**, so a chain that stopped at the product's own value would
render `<meta name="description" content="">` on every product page. An empty string therefore
falls *through* to the next source rather than satisfying the chain — absent and blank mean
the same thing to a crawler.

Titles have a matching rule: a page supplying nothing gets `default_title` **verbatim, without
the template**, so the homepage does not read "AsterMD — AsterMD".

`PageMeta` is one page's resolved metadata, computed once on construction so the `<title>`,
the description, the canonical link and the social card cannot disagree — the card shares the
same two strings the page renders, and a card describing a different page from the one it is
attached to is worse than none. `SeoMiddleware` publishes it as the `seo` global; `with()`
folds in whatever the page set.

### Structured data

`core/Seo/StructuredData/` has an emitter per type rather than one class switching on a type
string, because each answers a different rule: a `Product`'s offer has to reflect real state,
a prescription product has to be able to publish **nothing at all**, and the site-level nodes
(`Organisation`, `WebSite`, `BreadcrumbList`) have neither constraint. `Emitter::emit()`
returns `null` rather than an empty array, because "nothing to say" and "an empty object" are
different claims to a crawler.

`StructuredData` assembles the graph **from the path** — the same input `RobotsPolicy` decides
indexing from, so the two answers derive from one fact rather than two hand-kept lists. Each
emitter is independently switchable in `app.seo.structured_data`, and each toggle defaults to
**off** when the key is absent, so a deployment that has not configured structured data
publishes none rather than whatever the catalog happens to contain. The `rx` toggle ships off:
a prescription product carries claims and availability constraints that vary by jurisdiction.

`search_url_template` ships **null**, and that is a decision rather than an omission. The
`SearchAction` emitter is built and tested, but this storefront has no search endpoint — the
treatments listing filters in the browser without reading a query parameter. Publishing the
action would tell a search engine that a query submitted here returns matching results, when
what comes back is the unfiltered listing. A deployment that builds real search sets its own
template and the action appears with no further change.

### The sitemap derives its exclusions from the robots policy

`SitemapController` carries **no exclusion list of its own.** It asks `RobotsPolicy` — the
same object that decides whether a page emits `noindex`. Two lists would drift, and the
failure mode is silent and bad: a checkout page advertised in a sitemap while claiming to be
unindexable. Adding a funnel step later means adding one robots rule, not two entries in two
files.

Its `STATIC_PATHS` constant lists only the marketing surface, so funnel steps are absent by
construction as well as by policy — a new funnel route cannot be added to the sitemap by
forgetting a rule.

Under `discourage_indexing` the document is still served but lists nothing. A 404 behind a
live `Sitemap:` line in `robots.txt` is a contradiction a crawler will report; an empty
`<urlset>` is the weaker of two imperfect answers, and the two documents have to agree.

`RobotsController` generates `robots.txt` from the same policy plus the `ai_crawlers`
configuration — whether a client wants their catalog ingested by answer engines is a
commercial decision, not a technical default. Both documents are routed and generated rather
than served as static files, so a re-sync cannot leave them stale; both are file-like paths,
which `CanonicalUrlMiddleware` exempts from the trailing-slash rule.

---

## Testing

`tests/` mirrors the `core/` namespace tree and runs under `APP_ENV=test`, set in
`phpunit.xml`. That one variable is what switches the EMR gateway family to its Null
implementations, so a test run cannot reach the EMR by accident. Tests that need a real
interaction build the app through `AppFactory::create($rootDir, $overrides)` and substitute a
fake at the container.

Three tests are worth knowing about before changing the shape of anything:

- `tests/Bootstrap/MiddlewareOrderTest.php` pins the pipeline in both directions.
- `tests/Bootstrap/ConfigSeamTest.php` proves the override seam works, through the three
  readers of `config/verification.php` that decide whether the identity step exists and
  whether it blocks. It does **not** enumerate the twenty-five bindings that resolve
  configuration from the container, so it will not notice that set growing — count it from
  `AppFactory` rather than from here if the number matters to you.
- `tests/Docs/SpecReferenceTest.php` scans `core/`, `tests/`, `theme/`, `config/`, `bin/`,
  `database/`, `deploy/` and `public/` for `[n.n]` citations and fails if any of them resolves
  to nothing in `docs/SPEC-REFERENCE.md` — a citation nothing resolves reads as a pointer to a
  decision and delivers nothing.
