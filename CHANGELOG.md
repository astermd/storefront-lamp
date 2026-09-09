# Changelog

All notable changes to this theme are recorded here. The version is carried in
`composer.json` and `package.json`, which must always agree, and the release tag
must say the same thing. `bin/console --version` reads `composer.json`, so it is
not a fourth copy to keep in step. An update is applied by copying files, so every entry
names exactly which ones changed.

## [0.0.1]

Initial release.

A server-rendered telehealth storefront theme: catalog and product pages, a
mini-cart drawer, a definition-driven intake questionnaire in two renderer
modes, an optional identity-verification step, checkout against a payment
aggregator, post-purchase upsells, and a receipt.

- **Catalog** synced from the EMR channel by `bin/console theme:sync`, with a
  client-owned override layer that survives a re-sync.
- **Sessions and attribution** — analytics sessions minted on landing,
  first-touch attribution from plain query parameters or the encrypted payload,
  and durable server-side journey state keyed by the analytics session.
- **Cart** as pure domain rules mirrored to the EMR, with no cart page.
- **Intake** — a form engine reading the EMR's own definitions, evaluating
  disqualification server-side in both renderer modes.
- **Checkout** — prefill from intake answers, plan switching, order bumps,
  versioned consents, an idempotency guard, and a capability-declaring payment
  adapter contract. Card data never reaches the EMR.
- **Post-purchase** — an upsell queue charging the instrument the provider
  already holds, and a receipt that fires completion exactly once.
- **Reconciliation** in both directions, abandonment signals for an external
  marketing platform, and a retention job.
- **SEO** — generated titles, descriptions, canonical links, social cards,
  JSON-LD, `sitemap.xml` and `robots.txt`, with the whole funnel excluded from
  indexing and from the sitemap.
- **Observability** — outcome and latency at every external boundary, a local
  audit trail covering access to health information, a health endpoint, and
  `bin/console ops:status` for the counts that represent money or clinical risk.

Two things ship deliberately switched off, each for a recorded reason rather
than out of caution. `config/verification.php` disables identity verification
because the provider integration returns a failing verdict for every identity,
including real ones — enabled and blocking it would refuse every buyer.
`app.debug.wire_log` writes unredacted provider and EMR traffic and
`bin/console config:validate` refuses a deployment with it on.
