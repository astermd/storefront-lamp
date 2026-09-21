# Changelog

All notable changes to this theme are recorded here. The version is carried in
`composer.json` and `package.json`, which must always agree, and the release tag
must say the same thing. `bin/console --version` reads `composer.json`, so it is
not a fourth copy to keep in step. An update is applied by copying files, so every entry
names exactly which ones changed.

## [Unreleased — 0.0.3]

Adds authorize-and-capture, so a deployment can hold a buyer's funds instead of
taking them and settle later, and a second payment provider behind the same
boundary. Stops `theme:sync` carrying one channel's payment credentials into
another channel's config file. Renders the three authoring hooks the form builder
writes and the storefront read none of — a field's own class and id, and the
form's own stylesheet — and stops `cache:clear` claiming to have cleared a cache
it could not touch, which is what made the first of those look unfixed after it
was fixed.

**One new configuration key and one new migration**, both inert until a
deployment opts in: `payment.settlement` defaults to `capture`, which is exactly
what every deployment did before, and `0006_settlement.php` adds one column with
that same default. `composer.json` and `package.json` still say 0.0.2 — bump them
together with the tag when this is released.

### A second payment provider: CheckoutChamp

`core/Payment/CheckoutChamp/` is the second implementation of `[14.1]`'s boundary,
which until now had one. Adding it was an adapter plus a registry entry, which is
what `[14.2]` says it should be — nothing above `PaymentAdapter` changed to
accommodate it.

It is deliberately not shaped like the first, and that was the point. Placement
is **two calls**: `POST /leads/import/` creates the customer and answers a
`sessionId`, `POST /order/import/` bills it, and calling the second alone answers
"Customer not found". Lines are **numbered parameters** (`product1_id`,
`product2_id`, …) rather than an array, so an unmappable line is skipped without
leaving a gap — the provider stops reading at the first missing index, so a hole
would silently drop every line after it. There is no promotion endpoint, and the
reusable credential is a single customer id rather than a pair.

Two things it forced, both general rather than provider-specific:

- **Each provider's client declares its own `HttpClientInterface`**, so the
  test-environment fence had to be per adapter. `tests/Payment/RefusingTransportTest.php`
  now asserts that *every registered category* is fenced, so a third provider
  added without one fails there rather than by charging a card.
- **`AdapterCapabilities::$requiredDeploymentKeys`.** Each adapter now declares
  which `payment.*` keys it needs and `config:validate` checks the declaration.
  The hardcoded `shipping_profile_id` check it replaces demanded a key that a
  provider without one has never heard of. This adapter declares none, and the
  mechanism still earns its place through the other one.

**Pre-auth works on this provider too, and it has _two_ mechanisms for it**,
chosen by `payment.checkout_champ.authorize_mode`
(`PAYMENT_CC_AUTHORIZE_MODE`). They reserve different amounts of money, which is
the whole reason both exist:

- **`qa`** — the default, and what the provider recommends. `/order/import/`
  with `forceQA: 1` puts the order in `PENDING` review with the **full order
  amount held** on the card; `/order/qa/` with `action: "APPROVE"` releases it.
  Because the money is genuinely reserved, a later capture is far less likely to
  decline.
- **`preauth`** — the older mechanism. `/order/preauth/` validates the card by
  charging a nominal amount and refunding it, and **reserves nothing**; the
  settling call is `/order/import/` with the lines and no card. By the time you
  capture, the funds may be gone.

Deployment-wide, never per product, and CheckoutChamp's alone — unlike
`payment.settlement`, which a single product can escalate. It is a property of
how the deployment is set up with its provider, and a cart cannot be half one and
half the other. A settled order tells you which ran: the QA mechanism leaves
`reviewStatus: "APPROVED"`, the older one leaves it null.

Two traps recorded while wiring it. The settle parameter is **`action`, not
`qaStatus`** — the client package's README documents the latter and the API
answers it `"action is a required value"`. And the verbs are **`APPROVE` /
`DECLINE`**, not `APPROVED`. `DECLINE` is deliberately not wired: it voids the
hold, and a call that throws a buyer's reserved funds away needs a caller that
has decided to, not a flag on a capture.

That forced the one interface change. `PaymentAdapter::capture()` now takes a
`CaptureRequest` rather than a bare reference, because **a pre-authorized order
at this provider carries no line items until the settling call supplies them**,
and re-reading the order returns the same empty list. `order_lines` is the only
place they still exist. Settling without them is refused with "No products exist
in the order" and leaves the order partial *with the funds still held* — an error
that leaves money reserved. The other adapter ignores the lines.

It also reversed a rule in `bin/console payment:capture`: an unreadable local row
used to be waved through, on the reasoning that a hold must not lapse because a
SELECT failed. That assumed every provider can settle from the reference alone.
For the one that cannot, going on without the lines reaches the same outcome more
slowly and reports it as a cart problem, so it is now a stated failure.

**Two capabilities are still declared false**, each for a stated reason rather
than as a stub: `supportsPromotions`, because the client exposes no
discount-quote endpoint and `[14.4]` prefers hiding the control to offering a box
that can only reject; and `supportsOrderSearch`, because `orderQuery` works but
the fields `[21.9a]`'s sweep would have to map have not been established across a
real window, and that sweep turns its findings into alerts about money.

**This provider takes every parameter in the query string**, including the card
number, the security code and the account password. `CheckoutChampWireLog`
therefore holds credentials as well as cardholder data while it is on, and the
declared PCI posture says so — so `config:validate` puts it in front of an
operator before they go live rather than after.

**Recorded against the live sandbox, end to end, through the shipped adapter:** a
charge, a decline, and an authorization settled by a later capture under *both*
mechanisms. The
fixtures under `tests/fixtures/checkoutchamp-*.json` are those responses.

Three of the recordings corrected an assumption this adapter was first written
on:

- **`leads/import` is not only a lead.** It creates a PARTIAL order and answers
  the `orderId` every later call is keyed on — so the reference exists before a
  card is presented, which is what lets a refused placement still carry one
  (`[13.26]`) even though the refusal envelope has no order id in it.
- **`result` is the only discriminator.** All four combinations of
  `result` and `message`-type occur, including `SUCCESS` with a plain string
  (`"Card is preauthorized"`) and `ERROR` with a field map. The first draft read
  the type as the verdict and would have declined every successful
  pre-authorization.
- **A PARTIAL order is reused by the next attempt**, so a decline and the retry
  that succeeds share one `orderId`. `orders.provider_reference` is therefore not
  unique, and `OrderRepository::findByReference()` now orders by `id DESC`:
  without that, SQLite answers the lowest rowid — the declined attempt — and
  `payment:capture` would read a row saying the order was already captured and
  refuse to settle an authorization that is really outstanding.

`docs/INTEGRATION-NOTES.md` items 19–26 carry the full list.

New configuration: `payment.checkout_champ.authorize_mode` above, and nothing
else. In particular the campaign this provider needs on every order is a
variant's `provider.offer_id` in the catalog, and its `provider.product_id` is
the campaign-scoped product id — which is how the EMR already maps them, so a
second provider needed no new catalog field at all. The campaign therefore
arrives with the order rather than from configuration, which moves where a fault
shows up: a cart whose lines carry two different campaigns has no correct single
answer and is refused before the wire, rather than being placed under one that
does not offer half of it.

`salesUrl` is sent on the lead call — the storefront's own checkout URL, which
the provider shows against the order in its dashboard so an operator reconciling
one by hand sees where it came from rather than only a campaign number.

- `core/Payment/CheckoutChamp/` (new, eight classes),
  `core/Payment/CaptureRequest.php` (new), `composer.json`
  (`astermd/checkoutchamp-client`)
- `core/Payment/PaymentAdapter.php`, `NullPaymentAdapter.php`,
  `core/Payment/Vrio/VrioAdapter.php`,
  `core/Observability/InstrumentedPaymentAdapter.php`,
  `core/Console/CaptureOrderCommand.php`, `core/Repository/OrderRepository.php`
- `core/Payment/AdapterCapabilities.php`, `core/Payment/Vrio/VrioAdapter.php`,
  `core/Console/ValidateCommand.php`, `core/Bootstrap/AppFactory.php`,
  `bin/console`

### `theme:sync` no longer carries one channel's credentials into another's file

`config/channel.generated.php` was written with merge-keep-extra semantics so a
hand-added operational key survived a re-sync. The merge cannot see *who wrote*
an existing key, so it treated the previous provider's own credentials exactly
like an operator's hand-addition and kept them.

Point a deployment at a channel on a different payment processor and re-sync,
and the result was a `payment_processor.config` holding the new provider's
username and password **beside the previous provider's live API key**, plus its
campaign and connection ids. Two providers' secrets in one file — in the block
`AdapterRegistry`'s chosen adapter reads its credentials from — and a set of
routing hints meaning nothing to the provider now configured.

Two rules now, both about not letting one channel's data outlive it:

- **A different `channel.id` replaces the file wholesale.** It is describing a
  different deployment target and nothing in the old file describes it.
- **`payment_processor` is replaced on every sync.** It is generated data end to
  end: every key in it is one provider's own vocabulary, written by the EMR, and
  nothing in this codebase reads a hand-added key from it —
  `payment.shipping_profile_id` comes from `config/payment.php`, the wire-log
  switch from `app.debug.wire_log`. Merging it could only ever preserve a key the
  configured provider has no use for. This also **heals a file that was already
  polluted**: a plain re-sync now cleans it.

Hand-added keys elsewhere in the tree still survive a re-sync of the same
channel, which is what the merge was for.

- `core/Console/SyncCommand.php`, `config/channel.generated.example.php`

### Two tests stopped asserting whatever the last sync contained

Both resolved the payment adapter from the shipped configuration, so both
silently depended on the synced channel being one with an adapter. Pointing a
deployment at a channel on another processor turned each into a
`ReflectionException` about `NullPaymentAdapter::$inner` — which reads as a
broken test rather than as a test whose premise had moved.

`tests/Support/WireLogTest.php` asserts that no provider transcript is written
unless the switch is on; `tests/Payment/RefusingTransportTest.php` asserts the
suite cannot reach a live provider. Neither claim is about which provider a
deployment synced, so both now supply the channel they need through
`tests/Support/ConfigVariant.php`. `RefusingTransportTest` also dropped a skip
that let a fresh clone pass it by reaching nothing — the vacuous pass the fence
exists to prevent.

Fixing them exposed a real half-seam: `AppFactory`'s `AdapterRegistry` binding
read a closed-over `$config` while the category came from the container, so a
`Config::class` override moved the provider and left its credentials behind. The
registry then built the named adapter from another provider's config block, the
credential mapper threw, and the caught failure surfaced as "no provider
configured". Both now read from the container.

- `core/Bootstrap/AppFactory.php`, `tests/Support/WireLogTest.php`,
  `tests/Payment/RefusingTransportTest.php`, `tests/Console/SyncCommandTest.php`

### Settlement: hold the funds, or take them

A storefront could only ever charge in full at checkout. It can now authorize
instead — reserve the money on the card and leave it there — with
`bin/console payment:capture <reference>` taking it once whatever the deployment
is waiting on has happened.

**What decides *when* an authorization settles is deliberately outside this
storefront.** A prescriber approving a treatment is not a checkout concern, and
a storefront that scheduled its own captures would be guessing at a decision it
cannot see. So there is no scheduler and no sweep here; the command is the seam
the system that owns that event calls.

**Two configuration layers, because they answer different questions.**
`payment.settlement` in `config/payment.php` (or `PAYMENT_SETTLEMENT`) is the
deployment default — a commercial arrangement with one provider. A per-product
`'settlement' => 'authorize'` in `config/products.overrides.php` is a clinical
or fulfilment fact about one product and varies inside one deployment. Like
`geo_blocks`, the per-product key is override-layer only: the EMR channel
payload has no concept of settlement, so `theme:sync` neither writes it nor can
overwrite it, and a re-sync cannot silently start charging a product marked to
hold.

**A cart is one order (`[13.19]`), so a cart resolves to one action — and
authorize wins.** Adding a hold-until-event product changes how the *other*
products in that cart settle. That is intended: capturing a product a deployment
marked hold-until-event is a charge nobody asked for, while authorizing one
marked charge-now defers a charge by a step that has to happen anyway. Only the
first needs a refund to undo. A product marked `capture` therefore cannot pull
an authorize deployment back to charging; it only declines to ask for a hold.

**An authorized order is *placed*, not a fourth state.** The order exists at the
provider, the funnel advances, the EMR is told, the buyer gets a receipt — only
the debit is outstanding. A fourth `PlacementOutcome` state would have been read
as "not placed" by every existing `match` and would have stranded buyers whose
cards were validly reserved, so settlement hangs off a placed outcome beside the
charge discrepancy. `orders.settlement` is a column for the same reason: a
fourth `status` value would have made every query reading `status = 'placed'`
stop counting authorized orders, including both reconciliation sweeps.

**A provider that cannot authorize is never asked to charge instead.**
`AdapterCapabilities::$supportsAuthorizeCapture` is the one capability whose
absence stops a checkout rather than adapting a page — everything else here
degrades, and this has no degraded form. An order resolving to authorize against
an adapter that declares false is refused before the wire, the idempotency claim
goes back, and `config:validate` reports the same disagreement as an error so it
is found before a buyer finds it.

**The receipt says which one happened.** The thank-you page carried a flat "Your
card has been charged" — true then, and a lie on an authorize deployment, where
a buyer would go looking for a debit that is not on their statement and may never
be. The wording is resolved from the placed order rows, so a journey that
captured a checkout and authorized an upsell takes the cautious sentence.

Established on the wire: `action: "authorize"` is accepted on the same
required fields as `action: "process"`, and `POST /orders/{id}/capture` answers a
typed `order_unauthorized` refusal on an order that never authorized. What could
**not** be recorded is the `status_type_id` an *approved* authorize returns —
the sandbox merchant's acquiring gateway was rejecting every charge, `process`
included — and `docs/INTEGRATION-NOTES.md` says so rather than implying the
mapping is verified. `date_auto_capture` is deliberately not sent.

- `core/Payment/SettlementMode.php` and `core/Payment/CaptureOutcome.php` (new),
  `core/Checkout/SettlementPolicy.php` (new),
  `core/Console/CaptureOrderCommand.php` (new),
  `database/migrations/0006_settlement.php` (new)
- `core/Payment/PaymentAdapter.php`, `AdapterCapabilities.php`,
  `OrderEnvelope.php`, `PlacementOutcome.php`, `NullPaymentAdapter.php`,
  `core/Payment/Vrio/VrioPayload.php`, `VrioOutcome.php`, `VrioAdapter.php`
- `core/Checkout/CheckoutService.php`, `DatabaseOrderRecorder.php`,
  `core/Upsell/UpsellService.php`, `core/Completion/Receipt.php`,
  `ReceiptViewModel.php`, `core/Repository/OrderRepository.php`
- `core/Observability/Boundary.php`, `InstrumentedPaymentAdapter.php`,
  `core/Console/ValidateCommand.php`, `core/Bootstrap/AppFactory.php`,
  `bin/console`
- `theme/templates/pages/thank-you.twig`, `config/payment.php`,
  `config/products.overrides.php`, `.env.example`

### A field's authored class and id are rendered

`properties.runtimeClassName` and `properties.runtimeId` were read by nothing,
so a class set in the builder reached the page as neither markup nor an error.

**`runtimeId`** goes on the field wrapper, away from wiring that cannot move:
`intake-<name>` is what the label points at, what an error is described by, and
what a choice group names itself from, so an authored id sits beside that and
never displaces it. Reach the control with `#my-id input`.

**`runtimeClassName`** goes on the wrapper *and* on the element the field
actually draws — the `<h2>`, the `<input>`, the alert, the choice group. The
wrapper alone was not enough, and the failure looked exactly like the class
never being applied: the theme puts its own utility classes on that inner
element, so `.head-cls{color:red}` painted the wrapper and left the heading its
own `text-heading` colour. Keeping it on the wrapper too is what makes
`.hide-this{display:none}` still take a field's label with it.

One consequence worth knowing: a box rule applies twice. `.mine{margin:20px}`
margins the wrapper and the element inside it. Use a rule that does not
compound, or target one of them — `.mine input{}` or `div.mine{}`.

The authored class is appended to the layout class rather than replacing it, so
naming a class does not cost a field its grid cell. A blank value counts as
absent: the builder writes an empty string for a value typed and then cleared,
and `id=""` matches no selector ever written.

- `core/Forms/FieldViewModel.php`, `theme/templates/partials/intake/field.twig`
  and every `field-*.twig` that draws an element

### The form's own stylesheet is rendered

`settings.injectCss` was dropped, which is why the hooks above had nothing to
name. It is rendered into a `<style>` block on the intake page, last, so an
author's rule wins over the theme's without needing `!important`. Both
renderers get it, so mode A and mode C do not diverge.

It is emitted raw, because escaping would break it — `>` is the child
combinator and a quote appears in every attribute selector. What makes that
safe is narrow: inside `<style>` the HTML parser interprets no tags, and the
only thing that ends the element is a closing tag, so that one sequence is
removed and nothing else is. The guard tolerates the whitespace the parser
tolerates, since `</ style>` closes the element just as well.

Removal repeats until the text stops changing. Doing it once is not enough:
`</sty</stylele>` holds exactly one closing tag, in the middle, and deleting it
splices the surviving halves into a working one.

- `core/Forms/InjectedCss.php` (new), `core/Http/Controller/IntakeController.php`,
  `theme/templates/pages/intake/form.twig`,
  `theme/templates/pages/intake/form-js-engine.twig`

### `cache:clear` reports what it actually removed

It reported a success it did not have. The count rose once per entry *walked*
while both removals were `@`-suppressed, so a cache directory owned by the
web-server user and cleared by a shell user printed
`Cache cleared (12 entries removed).` and removed nothing — after which the
operator had the one piece of evidence they most needed to be true, and ruled
the cache out.

That is how a stale questionnaire definition went on being served through a
clear that reported success: `storage/cache/teleforms` still held the old copy,
so the storefront skipped the `teleforms/view-url` fetch entirely and the form
rendered from a definition nobody could see.

The answer from `rmdir`/`unlink` is now read. The count is of entries removed,
surviving files are listed with their owner — deleting a file needs write
permission on the directory holding it, not on the file, so ownership is almost
always the cause — and the command exits **1**.

```
Cache cleared (1 entry removed).

ERROR: 2 cache entries could not be removed:
  storage/cache/teleforms/abc123.json (owned by www-data)
  ...

The cache was NOT fully cleared. Whatever it was holding is still being served.
```

- `core/Console/CacheClearCommand.php`

### Tests

`vendor/bin/phpunit` is green at **2215 tests / 7524 assertions**, up from
2044 / 7102, and at 2215 / 7465 on a clone with no synced catalog. Every field type that draws an element is covered by name, so a
partial added later without the hook fails rather than silently ignoring it.
The two `cache:clear` permission cases skip as root, where the refusal they
arrange cannot happen.

Two SEO cases were re-argued rather than repaired. Both asserted that every
product in the shipped catalog carries an empty description — true of the
channel they were written against, and one of them said so as a premise to be
revisited if a sync ever brought real copy. A sync did. The claim about what a
catalog contains was never the rule: the rule is that a product's own copy
wins and an absent one falls back, so each branch is now a stated case against
a catalog the test supplies. What still sweeps the real catalog asserts only
what survives a re-sync — that nothing resolves to an empty description. The
branch where a product *has* copy had no end-to-end coverage at all until now,
because no synced product had ever exercised it.

- `tests/Seo/MetaResolverTest.php`, `tests/Seo/HeadMetadataTest.php`

The EMR's hosted engine reads none of these three properties, so there was no
reference renderer to match — the storefront defines the behaviour, and mode A
is handed the same stylesheet so the two cannot drift.

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
