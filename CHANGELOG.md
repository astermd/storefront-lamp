# Changelog

All notable changes to this theme are recorded here. The version is carried in
`composer.json` and `package.json`, which must always agree, and the release tag
must say the same thing. `bin/console --version` reads `composer.json`, so it is
not a fourth copy to keep in step. An update is applied by copying files, so every entry
names exactly which ones changed.

## [Unreleased]

### Stripe

A channel whose payment processor is Stripe now takes payments. The card is
entered in Stripe's own fields on the checkout page and never reaches the
server; the storefront creates and confirms a PaymentIntent with the token
Stripe mints, reusing the buyer's Stripe customer by email, and the EMR builds
the treatment from that PaymentIntent when the order is synced. A line whose
variant the EMR sells on a subscription plan is sold on that plan, at the
plan's first payment, and makes the order renew. Discount codes are the EMR's
coupons, priced again just before paying: a code that ran out in between is
refused before Stripe is called and dropped from the order. A held payment (`settlement: authorize`) is captured by the EMR when
the doctor completes the case, never by `payment:capture`. When the buyer's
bank asks them to verify a payment, the checkout runs the verification and
finishes the order on `/checkout/confirm/` — for any provider that declares it
can; Vrio and CheckoutChamp still stop the checkout as before.

Run `composer install` for `stripe/stripe-php`, then
`bin/console theme:sync --apply`. Plans and discount codes appear once the
installed `astermd/sdk` offers `plans()` and `coupons()`; until then every line
is sold one-off and the promo box is hidden on Stripe channels. Then
`npm run build`.

- `core/Payment/Stripe/` (new), `core/Emr/CouponGateway.php`,
  `core/Emr/EmrCouponGateway.php`, `core/Emr/NullCouponGateway.php` (new),
  `theme/js/payment-stripe.js` (new)
- `core/Payment/PaymentAdapter.php`, `core/Payment/AdapterCapabilities.php`,
  `core/Payment/PlacementOutcome.php`, `core/Payment/PromotionQuote.php`,
  `core/Payment/OrderLine.php`, `core/Payment/OrderEnvelope.php`,
  `core/Payment/CardDescriptor.php`, `core/Payment/NullPaymentAdapter.php`,
  `core/Payment/Vrio/VrioAdapter.php`,
  `core/Payment/CheckoutChamp/CheckoutChampAdapter.php`,
  `core/Observability/Boundary.php`,
  `core/Observability/InstrumentedPaymentAdapter.php`,
  `core/Checkout/CheckoutService.php`, `core/Checkout/CheckoutResult.php`,
  `core/Checkout/CheckoutViewModel.php`, `core/Checkout/ConsentRecord.php`,
  `core/Checkout/Promotion.php`, `core/Journey/JourneyState.php`,
  `core/Repository/CheckoutAttemptRepository.php`,
  `core/Http/Controller/CheckoutController.php`, `core/Upsell/Upsell.php`,
  `core/Upsell/Upsells.php`, `core/Upsell/UpsellService.php`,
  `core/Catalog/CatalogBuilder.php`, `core/Console/SyncCommand.php`,
  `core/Bootstrap/AppFactory.php`, `bin/console`, `config/routes.php`,
  `theme/templates/pages/checkout.twig`, `theme/js/checkout.js`,
  `public/assets/build/`,
  `composer.json`, `composer.lock`, `docs/ARCHITECTURE.md`,
  `docs/INTEGRATION-NOTES.md`

### Runs in Docker

A `Dockerfile` builds one image with nginx in front of php-fpm on PHP 8.5, and
`compose.yaml` runs it for local development with the checkout bind-mounted.
The container clears the cache and applies migrations on start, runs entirely
as `www-data`, and keeps the application read-only to that user with
`storage/` as the only writable path. `.env`, the two synced
`config/*.generated.php` files and `public/assets/media/` are kept out of the
image and mounted at run time. "Docker" in `deploy/README.md` covers both uses.
Nothing outside Docker changes.

- `Dockerfile`, `.dockerignore`, `compose.yaml`, `deploy/docker/nginx.conf`,
  `deploy/docker/php.ini`, `deploy/docker/php-fpm.conf`,
  `deploy/docker/entrypoint.sh`, `deploy/README.md`, `README.md`

### Priced add-ons are charged

An add-on the channel gives a price is now a `priced-addon`, not a `free-addon`.
It still comes into the cart with its parent, but at its own configured price:
checkout lists it as its own line, the subtotal includes it, and the provider
order carries it at that price. Before, every add-on was forced to `0.00`, and a
provider offer with a minimum line price refused the order ("0.00 is less than
the minimum amount allowed on the offer"). An add-on with no price stays free.

Run `bin/console theme:sync --apply` to pick the new kind up. A cart opened
before the sync keeps an add-on at its old price until the product is removed
and added again.

- `core/Catalog/CatalogBuilder.php`, `core/Catalog/CatalogValidator.php`,
  `core/Console/ValidateCommand.php`, `core/Domain/CartRules.php`

## [0.0.6]

Sends a channel-mapped add-on to the payment provider as its own line item at
`0.00`, where it used to be left off the order. The intake form collects
file-upload and signature answers, checkout suggests US addresses from Google
or the EMR, and the discount box is offered on CheckoutChamp. The treatments
listing's filter, "Get Started" and the product page's plan price are fixed.

Run `bin/console theme:sync --apply` after updating, so the catalog picks up
add-on mappings. Five intake upload routes and two checkout address routes are
added in `config/routes.php`, and two rate-limit buckets, `intake.upload` and
`checkout.address`, in `config/payment.php`; a deployment that has edited
either file merges them in by hand. `EMR_VERIFICATION` is split into
`EMR_VERIFY_EMAIL` and `EMR_VERIFY_ADDRESS`, and an existing `.env` behaves as
before. The shipped server configs and the new `public/.user.ini` raise the
request limit to 12 MB for file uploads; a lower limit still works, at the cost
`deploy/README.md` describes. `astermd/checkoutchamp-client` is raised to
`^0.0.2`. No migration is added.

### Add-ons reach the provider order

An add-on in the channel now carries its own `product_mappings`, and
`theme:sync` reads them: each add-on variant gets the offer and product ids its
own mapping names, never its parent's. Before, every add-on and lab was built
with no provider identity at all, so `theme:sync` warned that it needed one in
the override layer and checkout left it off the provider order, logged as
`payment.line_not_sent`.

A mapped add-on is now a line item on the one charge (`[13.19]`), on Vrio and
CheckoutChamp alike, sent at `0.00` as `[7.14a]` prices it -- the price is
always sent, so the provider never charges its own catalog figure for
something the buyer was shown as free. On CheckoutChamp an add-on's `offer_id`
is its campaign, and a cart whose lines name two campaigns is refused, so map
an add-on under the same campaign as the products it attaches to. A lab, or an
add-on the channel leaves unmapped, behaves as before.

Run `bin/console theme:sync --apply` to pick the mappings up.

- `core/Catalog/CatalogBuilder.php`, `core/Payment/OrderLine.php`

### File-upload and signature questions on the intake form

The server-rendered intake form now collects the builder's `file-upload` and
`signature` question types, which it used to refuse as unsupported (`[10.13]`).

A file goes to the EMR as soon as it is picked, relayed through five new POST
routes because the EMR call needs this deployment's credential:
`/intake/upload/` takes a whole document or image, and
`/intake/upload/start/`, `/part/`, `/finish/` and `/abort/` take a video, or
anything over one request, in parts of up to 10 MB, three at a time. The
EMR's answer, `{path, name, mime_type}`, is what the form posts and what the
intake submission carries, one entry per file, under the type `file`.
`docs/INTEGRATION-NOTES.md` item 30 records what the endpoints really accept:
the single-request one refuses all video, the multipart one also takes
documents and images, and neither takes HEIC yet.

Each question is held to its own `maxFiles`, `allowMultipleFiles`,
`maxFileSizeMb` and `fileTypes`, within the EMR's limits (its kinds, 200 MB a
file), in the browser before a byte is sent and again on the server. The page
shows each file's progress, a retry for a failed one and a remove button, and
will not leave the page while a file is still going up or has failed. A
reference the page posts is stored only when the EMR filed it under the
visitor's own session, and an upload id is honoured only from the PHP session
that started it, so no visitor can attach or write into another's file.

A signature is drawn on a pad and kept as a PNG data URL in the answer itself,
also sent under the type `file`. Every other field keeps the type it was
authored with.

Under `INTAKE_RENDERER=js-engine` both types stay unsupported: the hosted
engine keeps a picked file's name and never its bytes, and draws no signature
pad. That renderer's page now says so before the visitor starts, as the server
renderer's always has.

**Request size.** One upload request carries up to 10 MB, so the shipped
`deploy/nginx.conf` sets `client_max_body_size 12m`, `deploy/apache-vhost.conf`
sets `LimitRequestBody`, and a new `public/.user.ini` raises PHP's
`upload_max_filesize` and `post_max_size` for php-fpm. A server left lower
still works -- the page sends nothing larger than PHP will take -- but see
`deploy/README.md` for what a limit under 5 MB costs. Uploads use the new
`intake.upload` rate-limit bucket in `config/payment.php`, and follow
`app.session.analytics`: with it off, nothing would ever name a stored file, so
none is stored.

- `core/Forms/FileRules.php`, `FileReference.php`, `IntakeUploads.php`,
  `EmrIntakeUploads.php`, `NullIntakeUploads.php`, `UploadOutcome.php`,
  `core/Http/Controller/IntakeUploadController.php` (all new)
- `core/Forms/FieldTypes.php`, `Field.php`, `Definition.php`, `AnswerSet.php`,
  `AnswerValidator.php`, `AnswerEncoder.php`, `FieldViewModel.php`,
  `IntakeSession.php`, `core/Http/Controller/IntakeController.php`,
  `core/Bootstrap/AppFactory.php`
- `config/routes.php`, `config/payment.php`
- `theme/templates/partials/intake/field-file-upload.twig`,
  `field-signature.twig` (new), `theme/templates/pages/intake/form.twig`,
  `form-js-engine.twig`, `theme/js/intake-files.js` (new), `theme/js/intake.js`,
  `public/assets/build/`
- `public/.user.ini` (new), `deploy/nginx.conf`, `deploy/apache-vhost.conf`,
  `deploy/README.md`
- `docs/INTEGRATION-NOTES.md`, `docs/ARCHITECTURE.md`

### Address suggestions at checkout

Typing in checkout's Address field now offers up to five US addresses, which
narrow as the buyer types, and choosing one fills the address line, city,
state and ZIP. It is the same for every payment provider, since the address
form is shared.

The source follows `GOOGLE_MAPS_API_KEY`, with no switch of its own. With a key,
the browser asks Google Places (`AutocompleteSuggestion`, US only). Without
one, it asks the storefront's new `GET /checkout/address/suggest/` and
`/checkout/address/resolve/`, which ask the EMR through the new
`Emr\AddressAutofill` port. The EMR's suggestions carry no ZIP, so resolving
one goes through its address verifier, whose formatted address does
(`docs/INTEGRATION-NOTES.md` item 29). A key Google refuses for Places falls
back to the EMR without the buyer noticing. A Maps key restricted to the Embed
API alone, as the deploy notes used to advise, should now also allow the Maps
JavaScript API and the Places API (New).

The endpoints are rate-limited per client address under the new
`checkout.address` bucket in `config/payment.php`. With the script absent, the
field is the plain text input it was.

**`EMR_VERIFICATION` is split** into `EMR_VERIFY_EMAIL` and
`EMR_VERIFY_ADDRESS`, so the email and address checks can be switched
separately. `EMR_VERIFICATION` still sets both when neither is given, so an
existing `.env` behaves as before.

- `core/Emr/AddressAutofill.php`, `EmrAddressAutofill.php`,
  `NullAddressAutofill.php`, `core/Checkout/FormattedAddress.php`,
  `core/Http/Controller/AddressController.php` (all new)
- `core/Emr/VerificationGateway.php`, `EmrVerificationGateway.php`,
  `NullVerificationGateway.php`, `core/Bootstrap/AppFactory.php`,
  `core/Http/Middleware/TemplateGlobalsMiddleware.php`
- `config/app.php`, `config/payment.php`, `config/routes.php`, `.env.example`
- `theme/templates/pages/checkout.twig`, `theme/js/address-autocomplete.js` (new),
  `public/assets/build/`
- `README.md`, `deploy/README.md`, `docs/ARCHITECTURE.md`,
  `docs/INTEGRATION-NOTES.md`

### Treatment listings and the product page

- **The treatments filter works.** The sidebar's checkboxes were rendered with
  nothing behind them. Each card now carries its kind and categories, and
  `script.js` hides the cards that do not match: any ticked box within a group,
  every group that has one. A "No treatments match" note replaces an empty grid.
  Without the script every card stays shown.
- **"Get Started" starts buying.** It adds the product's first plan to the
  cart; the plan can be changed at checkout. A prescription then goes to the
  questionnaire, as the product page's "Start Assessment" does, and anything
  else straight to checkout. "Learn More" stays the way to the product page.
  "Get Started" used to open the product page on the listing and the listing
  on the home page. The button is the new `partials/get-started.twig`, shared
  by both.
- **Cards line up.** Price and actions sit at the foot of each listing card,
  and descriptions on both grids are clamped to three lines.
- **The home page's Our Treatments section ends with "View All Treatments"**,
  a link to the full, filterable listing the header's "Treatments" also opens.
- **An empty discount box is nothing to apply.** Pressing Apply with it empty,
  or Enter in any checkout field (which lands on the promo button, so that no
  keystroke can place an order), used to reload the page with "That discount
  code could not be applied." The server now answers an empty code with no
  notice and without spending the rate-limit bucket, and `checkout.js` keeps
  the page where it is and moves focus to the discount box.
- **A non-prescription product offers no "Start Assessment".** Checkout is its
  only action, and the note about steps after checkout is not shown for it.
- **Choosing a plan shows its price.** Each plan button carries its price and
  `cart.js` writes it into the displayed figure. The charge was already the
  chosen plan's; only the figure on the page stayed on the first plan.

- `theme/templates/pages/treatments.twig`, `theme/templates/pages/home.twig`,
  `theme/templates/pages/product.twig`, `theme/templates/partials/header.twig`,
  `theme/templates/partials/get-started.twig` (new)
- `theme/js/script.js`, `theme/js/cart.js`, `theme/js/checkout.js`,
  `core/Checkout/CheckoutService.php`, `public/assets/build/`

### Discount codes on CheckoutChamp

Checkout now offers the discount box when the channel's provider is
CheckoutChamp. A code is priced by `POST /order/coupon/` against the same
numbered lines, prices and quantities the billing call sends, with the buyer's
shipping country, so a country-scoped code prices correctly. The accepted code
then travels on `/order/import/` as `couponCode`, and on the pre-authorized
settle call as well, which re-prices the lines and would otherwise bill the
undiscounted figure.

The provider answers an unknown code exactly as it answers a real code worth
nothing on the cart — SUCCESS with a zero discount — so a zero is refused as an
invalid code rather than shown as applied. Only `priceDiscount` counts; a
`shipDiscount` is logged, since the storefront charges no shipping line.
`docs/INTEGRATION-NOTES.md` item 28 records the endpoint's answers.

A code the provider refuses with a reason of its own -- "This coupon has
already been redeemed. Please try again", "Coupon is expired" -- shows the
buyer that reason instead of "That discount code could not be applied", so they
know to stop trying rather than write to support. Only a refusal that names
the coupon is passed on; one about the request itself ("No products exist in
the order", a refused login) keeps the generic notice. `PromotionQuote` gains
an optional `$notice` for this, which an adapter may leave null.

Requires `astermd/checkoutchamp-client` 0.0.2, which adds `orderCoupon()`; the
constraint in `composer.json` is raised to `^0.0.2`. Against an older client the
quote fails closed: the code is rejected as `unavailable` and the order is
placed at full price.

- `core/Payment/CheckoutChamp/CheckoutChampAdapter.php`,
  `core/Payment/CheckoutChamp/CheckoutChampPayload.php`
- `core/Payment/CaptureRequest.php`, `core/Console/CaptureOrderCommand.php`
- `core/Payment/PromotionQuote.php`, `core/Checkout/CheckoutService.php`
- `composer.json`, `composer.lock`
- `docs/INTEGRATION-NOTES.md`, `docs/ARCHITECTURE.md`

## [0.0.5]

Lets one order carry several prescriptions. A second prescription added to the
cart used to replace the first; it now joins it, and a cart with two or more
collects no questionnaire on the storefront — every assessment for it is
completed from the patient portal after the order. A cart with one prescription
goes through the funnel exactly as before. Also fixes "Proceed to Checkout" on
the product page, which routed like "Start Assessment" whenever scripts ran.

No configuration key, migration or route is added. A client-customised
`checkout.twig` keeps working: the view model still carries the first
prescription's plan picker under `plans`, and the plan endpoint still reads the
`slug` + `variant_id` pair.

### Several prescriptions in one order

`[8.0f]` and `[8.0h]` are rewritten in `docs/SPEC-REFERENCE.md`, and `[8.0i]` is
new. `CartRules::add()` no longer removes the prescription already in the cart,
and `CartRules::RX_REPLACED` — the "was replaced with" notice — is gone.
`Cart::rxLine()` becomes `Cart::rxLines()`, a list in the order the lines were
added.

The questionnaire rule is one method, `FunnelRules::collectsOnStorefront()`,
true while the cart holds at most one prescription. When it is false the form
lists `FunnelRules` hands out are empty, and everything that reads them follows
without a check of its own: the routing decision answers `checkout`, the step
guard finds both form preconditions satisfied, `/intake/`, `/intake/medical/`
and `/intake/eligibility/` forward to checkout, and the cart drawer offers
checkout alone. A disqualification still shuts checkout — `not_disqualified`
reads the journey, not the forms — and Start Over on the not-eligible page now
removes only the prescription whose questionnaire produced the stop, rather than
whichever prescription came first.

On the page:

- The product page hides "Start Assessment" when adding that product would make
  two prescriptions, and "Proceed to Checkout" takes the primary style in its
  place.
- Checkout shows one plan picker per prescription. Each option posts
  `plan[<slug>]=<variant>`, so a picker names the line it belongs to.
- Product page, checkout and receipt show a short note — "You may have a few
  quick steps to complete after checkout to finalize your order." — whenever
  the cart or order holds two or more prescriptions. The wording lives in the
  new `theme/templates/partials/post-checkout-steps.twig`.

The upsell queue and the order's operator label anchor on the first
prescription added. `docs/ARCHITECTURE.md` describes the rule and where shared
intake forms — several products answering one questionnaire on the storefront —
would plug in.

- `core/Domain/CartRules.php`, `core/Domain/Cart.php`, `core/Domain/CartOutcome.php`,
  `core/Funnel/FunnelRules.php`
- `core/Checkout/CheckoutService.php`, `core/Checkout/CheckoutViewModel.php`,
  `core/Http/Controller/CheckoutController.php`
- `core/Http/Controller/ProductDetailController.php`,
  `core/Http/Controller/ReceiptController.php`,
  `core/Http/Controller/NotEligibleController.php`, `core/Bootstrap/AppFactory.php`
- `theme/templates/pages/product.twig`, `theme/templates/pages/checkout.twig`,
  `theme/templates/pages/thank-you.twig`,
  `theme/templates/partials/post-checkout-steps.twig` (new), `public/assets/build/`
- `config/funnel.php`, `config/cross-sells.php`, `config/upsells.php` (comments only)
- `docs/SPEC-REFERENCE.md`, `docs/ARCHITECTURE.md`

### "Proceed to Checkout" posted no intent when scripts ran

The product page's two buttons differ only by the `intent` they post.
`theme/js/cart.js` disabled every submit button inside the form's `submit`
handler, and a browser leaves a control that is disabled by then out of the
request body — the pressed button included. "Proceed to Checkout" therefore
reached `/cart/add/` with no `intent` and was routed to the questionnaire like
"Start Assessment". The disable is now deferred by one tick, as
`theme/js/checkout.js` already does; a double click still cannot post twice.
Without scripts the form was always correct.

- `theme/js/cart.js`, `public/assets/build/`

### Tests

`StepPreconditionsTest` built every cart line as a prescription whatever the
catalog said, so its "an OTC line beside a prescription" cases became
two-prescription carts and passed for a reason they were not about. They now
build each line with the kind the catalog gives it, and the router-and-guard
agreement check gains a two-prescription case.

- `tests/Domain/CartRulesTest.php`, `tests/Domain/FunnelRouterTest.php`,
  `tests/Funnel/FunnelRulesTest.php`, `tests/Funnel/StepPreconditionsTest.php`
- `tests/Http/CartControllerTest.php`, `tests/Http/CheckoutControllerTest.php`,
  `tests/Http/IntakeFormChoiceTest.php`, `tests/Http/MiniCartTest.php`,
  `tests/Http/NotEligibleTest.php`, `tests/Http/ProductPageTest.php`,
  `tests/Http/ReceiptPageTest.php`

## [0.0.4]

Stops the intake-submission draft silently dropping any field the form marks
`sendToProvider: false`. That flag governs whether `RecordMapper` writes a
field into the EMR's clinical record — it gates on `db_field` there instead —
and has no bearing on the visitor's own in-progress draft; `AnswerEncoder` was
excluding it from that draft anyway, citing `[10.12]`, which covers only
display-only elements and says nothing about this flag. Only a display-only
element and a genuinely unanswered field are excluded now.

Writes `clinical.medications` and `clinical.allergies` as a list rather than a
bare string, however the form collects them — the EMR refuses those two paths
written as a string. The whole free-text answer becomes a one-element list
rather than being split on commas, which would guess at a delimiter the
visitor never agreed to.

**Two new field properties**, both authored in the teleform builder.
`properties.hidden` renders a field as a native `<input type="hidden">`
carrying its value without ever drawing a control, for an answer that comes
from elsewhere and only needs to travel with the submission.
`properties.hasDefaultValue` and `properties.defaultValue` fill in an
otherwise-unanswered field with an authored default, including marking a
choice field's own option selected — a visitor's own answer, even an empty
one, is never overwritten by it.

## [0.0.3]

Adds authorize-and-capture, so a deployment can hold a buyer's funds instead of
taking them and settle later, and a second payment provider behind the same
boundary. Stops `theme:sync` carrying one channel's payment credentials into
another channel's config file. Renders the three authoring hooks the form builder
writes and the storefront read none of — a field's own class and id, and the
form's own stylesheet — and stops `cache:clear` claiming to have cleared a cache
it could not touch, which is what made the first of those look unfixed after it
was fixed.

Takes `astermd/sdk` 0.0.3, whose `treatments()->sync()` signature changed, and
fills the four payload extensions it documents.

**One new configuration key and two new migrations**, all inert until a
deployment opts in: `payment.settlement` defaults to `capture`, which is exactly
what every deployment did before, `0006_settlement.php` adds one column with
that same default, and `0007_user_agent.php` adds one nullable column. Tagged
`v0.0.3` without bumping `composer.json` and `package.json` to match; they go
straight from 0.0.2 to 0.0.4 with the next tag instead of a 0.0.3 that was
never really theirs.

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

`docs/INTEGRATION-NOTES.md` items 20–27 carry the full list.

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

### The SDK's 0.0.3 payload: how an order was paid for, and which variant was bought

`astermd/sdk` moves to `^0.0.3`. One breaking change and three documented
extensions.

**`treatments()->sync()` changed shape.** `$userAgent` moved to the third
position and became required and non-empty; `$payment` and `$verification` were
added. The old call passed `(session, orderIds, utmSource, userAgent)`, so on the
new signature `utm_source` would have gone out as the `User-Agent` header and the
real agent as the attribution — which is why the dependency bump and the rewrite
are one commit rather than two.

The header is now resolved in three steps, closest to the buyer first: the
ambient request, then the agent stored against the order, then the constant
`AsterMD-Storefront`. The middle step is what `0007_user_agent.php` exists for.
Two of the three callers have no request of their own — the receipt batch and
`bin/console reconcile:forward` — and until now had no way to name the device an
order was actually placed from. Never the empty string, because the SDK throws
on one and this class swallows every throwable into a warning line: the sync
would simply have stopped happening.

- `core/Checkout/EmrCheckoutEventReporter.php`, `database/migrations/0007_user_agent.php`,
  `core/Repository/OrderRepository.php`, `core/Checkout/DatabaseOrderRecorder.php`

**A `payment` block now rides the treatment sync and both order events.**
`type`, `pre_auth`, and — only when a reservation actually used the provider's QA
mechanism — `pre_auth_qa`, plus the held amount in dollars and a `card` object.
An absent optional is an absent key rather than a `false`: `pre_auth_qa: false`
asserts that the mechanism was available and not used, which is not what a Vrio
order or a plain capture means.

It sits *beside* the EMR's existing `payment_method` and does not replace it.
The two fields take different vocabularies — `card` against `credit_card` — and
collapsing them would silently change what the older one means to everything
already reading it.

A decline carries the block too, with the settlement it *attempted*:
`PlacementOutcome::declined()` carries no settlement and falls back to capture,
so reading it off the outcome would report every refused authorization as an
attempt to charge. It carries no held amount, because nothing was held.

- `core/Payment/PaymentDescriptor.php`, `core/Payment/CardDescriptor.php`,
  `core/Checkout/CheckoutService.php`, `core/Checkout/CheckoutEventReporter.php`
  and its four implementations

**The card's bin and expiry come off the form; only its brand may come from a
provider.** `CardBrand` is now the single BIN table and `Vrio\CardScheme` maps
onto it rather than keeping a second copy of the ranges — two tables drift, and
the one that drifts is the one nobody is looking at. Where a prefix names no
scheme, the payment provider's own reading answers instead: CheckoutChamp's
`message.cardType`, or Vrio's `card_type_id`. Where neither answers, `card`
ships without `type` and keeps its bin and expiry rather than discarding two
fields that are not in doubt.

Nothing else is read back from a provider. CheckoutChamp returns `cardBin` and
`cardExpiryDate` beside the brand and both are ignored: a CRM changes its
response shape and sometimes omits a field it documents, so a value the buyer
supplied directly is not re-sourced from one.

Vrio's `card_type_id` may be an echo of the one the order was sent with rather
than the provider's own reading — the storefront sends `2` for any prefix it
cannot place, so a `2` coming back proves nothing. `docs/INTEGRATION-NOTES.md`
item 19 says so and names the probe that would settle it.

- `core/Payment/CardBrand.php`, `core/Payment/Vrio/CardScheme.php`,
  `core/Payment/CheckoutChamp/CheckoutChampCardType.php`, `core/Payment/PlacementOutcome.php`

**Cart items carry `variant_id`.** One product with several strengths is one
`product_id` and several plans; without it the EMR could see what was bought and
not which of them. `CartLine` already held the id — the mirror simply dropped it.

- `core/Emr/CartMirror.php`

**`verification` is sent only when something was actually verified.** Today that
is one thing: an email the provider confirmed deliverable. `address` is `false`
because no address has ever been verified here, and a journey with nothing
verified sends no key at all rather than a row of falses describing checks that
did not run. `emailIsDeliverable()` answers null for "could not run" — on this
storefront's own credential the whole verification resource is refused — so only
a literal true counts. The gateway now memoises per address, because the verdict
is read twice in one request and the buyer should not pay for two round trips to
learn the same thing.

`verification.id` is deliberately not sent: the API requires the verified value —
the SSN or date of birth actually checked — and that would mean keeping PHI in
session storage. Session-level `verification` is likewise not wired; at session
creation nothing has been verified yet.

- `core/Emr/EmrVerificationGateway.php`, `core/Checkout/EmrCheckoutEventReporter.php`

### A picked date is sent as `YYYY-MM-DD`

A `picker-date` field collects `MM / DD / YYYY`, because that is what the control
writes and what has to go back into the box when the form re-renders. The EMR
stores ISO. Both outbound boundaries convert — the intake submission and the
opportunity record — and the stored answer is left exactly as the visitor typed
it, the same way money is integer cents everywhere and converts only at a
protocol boundary.

Month is read first, matching the field's placeholder and `theme/js/datepicker.js`,
which both writes and parses that order. A date that does not exist converts to
nothing rather than to the nearest one that does: a permissive parser rolls 31
February into March, which turns a typo into a plausible wrong date of birth on a
clinical record. An unreadable value passes through untouched — judging an answer
is the validator's job, and a boundary that blanked one would delete the
clinician's only copy of it.

- `core/Forms/DateAnswer.php`, `core/Forms/AnswerEncoder.php`,
  `core/Forms/RecordMapper.php`, `core/Forms/FieldTypes.php`

### The instrumentation decorator dropped an argument

`InstrumentedCheckoutEventReporter` accepted the new payment descriptor on all
three methods and forwarded none of them. It is what `AppFactory` wraps the EMR
reporter in, so in production the block was discarded before it reached the wire:
both `treatments/sync` and `checkout-events/update` went out without it while
every test of the inner reporter stayed green.

The gap that hid it is worth more than the fix. `tests/Http/CheckoutFunnelTest.php`
substituted the inner reporter directly and so took the decorator out of the
stack it is wrapped in — the one end-to-end test that should have caught this was
the one test guaranteed not to. It now wraps its substitute the way `AppFactory`
does, and asserts the payment block on both payloads.

- `core/Observability/InstrumentedCheckoutEventReporter.php`, `tests/Http/CheckoutFunnelTest.php`,
  `tests/Observability/RecordingCheckoutEventReporter.php`

### Tests

`vendor/bin/phpunit` is green at **2277 tests / 7671 assertions**, up from
2044 / 7102, and at 2277 / 7612 on a clone with no synced catalog. Every field type that draws an element is covered by name, so a
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
