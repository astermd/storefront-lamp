# Changelog

All notable changes to this theme are recorded here. The version is carried in
`composer.json` and `package.json`, which must always agree, and the release tag
must say the same thing. `bin/console --version` reads `composer.json`, so it is
not a fourth copy to keep in step. An update is applied by copying files, so every entry
names exactly which ones changed.

## [0.0.2]

Lets a buyer pay with the questionnaire still outstanding — this deployment
collects it from the patient portal after the order — and fixes four rendering
faults in the intake form that relaxation made visible.

**Upgrading.** Set `PATIENT_PORTAL_URL` in `.env`, or the header's Sign In and
the receipt's portal button do not render at all. If your deployment relies on
checkout being gated by the questionnaire, add `prequalification_satisfied` and
`intake_satisfied` back to the `checkout` step in `config/funnel.php` — but keep
`not_disqualified` either way, or a journey the server stopped can pay.

### Checkout no longer waits on the questionnaire

`config/funnel.php` drops `prequalification_satisfied` and `intake_satisfied`
from the `checkout` step. The product page's "Proceed to Checkout" already
redirected there; the step's own preconditions bounced it back.

`StepPreconditions::intakeSatisfied()` was answering two questions at once, and
the second one had to survive: a journey the server hard-stopped was refused
checkout only because a disqualification also fails the completion gate. It is
published separately as `not_disqualified`, which fails closed for a cart that
owes a questionnaire when the journey cannot be loaded, and open for a cart that
owes none — a cart with nothing to answer cannot have been stopped by an answer.

`verification_satisfied` is deliberately kept. It is off by configuration, and
the line is what makes `[22.16]`'s blocking placement mean anything where a
deployment turns it on.

- `config/funnel.php`, `core/Funnel/StepPreconditions.php`

### Single-select questions render as radios

The EMR authors every choice question as type `choice-multi` and puts the real
distinction in `properties`: `multiSelect` says whether more than one answer is
allowed and `choiceInputType` says which control was drawn. The renderer read
only the type, so 14 of the 15 choice questions on a shipped weight-loss intake
rendered as checkbox groups that accepted both answers — and stored what came
back as a list. `sex_at_birth` maps to `opportunity.gender`, which was being
sent a list where the EMR expects a value.

`Field::isMultiValue()` now reads those properties, which fixes the control and
the answer shape together: every consumer already asked the field rather than
the type. A field declaring neither property stays multi.

- `core/Forms/Field.php`, `core/Forms/FieldViewModel.php`

### The questionnaire lays out as a form rather than as a column of headlines

Two faults with one visible result. The column span was interpolated as
`sm:col-span-{{ field.cols }}`; Tailwind scans source text rather than rendered
output, so no such class was ever compiled — the 0.0.1 stylesheet contains no
`col-span` rule at all — and the semantics were inverted besides (`cols: 2` is
the half-width treatment that puts two fields on a row). The field partials were
also transcribed from a mockup that gave each question a screen of its own, so
rendered two-up in a grid every 28px centered label competed with every other.

Labels are now left-aligned and sized to name their box; choice options stack
with the control on the left; a composite lays its parts out against its own
grid. The page title is no longer printed above the fields — it names the step,
and the stepper already shows it.

- `theme/templates/pages/intake/form.twig`, `theme/templates/partials/intake/*.twig`
  (`field`, `field-label`, `field-question`, `field-text`, `field-number`,
  `field-textarea`, `field-date`, `field-dropdown`, `field-boolean`,
  `field-choice-single`, `field-choice-multi`, `field-heading`, `field-bmi`),
  `core/Forms/FieldViewModel.php`

### The stepper describes the form it sits above

It was four constants — Eligibility, Contact, Medical, Verify & Review — above a
questionnaire with any number of pages, so on a five-page intake three of the
four could never move. `ProgressSteps` derives one step per page, named by the
page title unless that is the builder's `Page N` default, then by the page's own
heading; an authored `form-progress` field's `progressStepItems` win where a form
carries one. `theme/js/intake.js` re-points the stepper as pages change, and
`stepper.twig` draws a step's number when it names no icon.

- `core/Forms/ProgressSteps.php` (new), `core/Http/Controller/IntakeController.php`,
  `theme/templates/partials/stepper.twig`, `theme/templates/pages/intake/form.twig`,
  `theme/templates/pages/intake/form-js-engine.twig`, `theme/js/intake.js`

### A card number of no issued length is refused before the provider is called

The card box accepted an unbounded string, so a buyer who pasted a statement
line paid a round trip to be shown a decline written for a developer. 13 to 19
digits, which is ISO/IEC 7812 as actually issued.

**No Luhn check, deliberately.** Payment providers issue sandbox numbers that
fail it on purpose, so checking it locally would make a provider's own test
cards unusable while telling whoever typed one that their card was wrong.
`[13.28]` still leaves the verdict on a card entirely to the provider; a length
is not a verdict. The checkout template had no error slot for the card at all,
so the message existed and no buyer could have read it.

- `core/Checkout/CardNumber.php` (new), `core/Checkout/CheckoutService.php`,
  `core/Http/Controller/CheckoutController.php`,
  `theme/templates/pages/checkout.twig`, `theme/js/checkout.js`

### The cart drawer offers both ways forward

Assessment and checkout, in the drawer footer. The assessment appears only while
a questionnaire is outstanding, which is an Rx-only condition without being
written as one: the routing decision names an intake step only for a line that
declares a questionnaire. A stopped journey is offered its explanation and no
pay button. The actions move off the line cards, where one cart-level answer was
being drawn once per eligible line.

- `core/Http/Middleware/TemplateGlobalsMiddleware.php`,
  `theme/templates/partials/header.twig`

### The patient portal has an address

`PATIENT_PORTAL_URL` — this application is not the portal, and the questionnaire,
the identity check and an order's clinical status all live there. Three links
previously pointed at `#sign-in` or `#`. There is no default, because a guessed
portal address sends a buyer somewhere real and wrong; absent means render no
link, and `config:validate` warns rather than errors so a fresh clone still boots.

- `config/app.php`, `.env.example`, `core/Console/ValidateCommand.php`,
  `core/Http/Middleware/TemplateGlobalsMiddleware.php`,
  `theme/templates/partials/header.twig`, `theme/templates/pages/thank-you.twig`

### Receipt lines show their product photo

Read live from the catalog by the slug the order stored, rather than kept in the
snapshot. What was charged — name, quantity, price — stays frozen, because a
later catalog edit must never rewrite a receipt a buyer keeps; a photo is
illustration, so there is nothing to protect by storing a copy. A slug the
catalog has lost, or a product with no photo, keeps the placeholder tile.

- `core/Http/Controller/ReceiptController.php`, `core/Bootstrap/AppFactory.php`,
  `theme/templates/pages/thank-you.twig`

### `npm run dev` for theme work

The watcher re-runs the same build on every save under `theme/css`, `theme/js`
and `theme/templates`, and the page reloads itself. Templates are watched
because Tailwind finds classes by scanning them, which is the same mechanism
behind the span fault above. `layouts/base.twig` loads the reload script only
outside production: shipped to a visitor it would be a request a second per open
tab. It is a full page reload, not hot module replacement — preserving page state
would mean a bundler owning the pipeline, and built output is committed here
precisely so servers never run Node.

- `build/watch.mjs` (new), `theme/js/livereload.js` (new), `package.json`,
  `theme/templates/layouts/base.twig`,
  `core/Http/Middleware/TemplateGlobalsMiddleware.php`, `README.md`, `CLAUDE.md`

### Tests

`vendor/bin/phpunit` is green at **2044 tests / 7165 assertions**, and at
**2044 / 7102** on a clone with no synced catalog — up from 1995 / 7090 and
1995 / 7027. Cases that used to witness an outstanding questionnaire on
`/checkout/` now witness it on `/verify/`, which still waits for one; asserting
it on a step that no longer waits would have left them passing either way.

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
