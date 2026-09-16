# Integration notes

This storefront talks to two external systems: an EMR, through the
`astermd/sdk` package, and a payment provider, through an adapter in
`core/Payment/`.

**Everything in this document is a place where those systems behave differently
from what their own documentation says.** Each was established by calling the
live system and reading what came back. Each is load-bearing: code in this
repository depends on it, and a developer who "corrects" the code back to the
documented shape will break something that currently works — usually silently,
because most of these failures produce a null rather than an error.

Where a claim here and a docblock in an SDK disagree, this document is the one
that was measured.

Rule ids like `[14.10]` refer to the behaviour contract resolved in
[`SPEC-REFERENCE.md`](SPEC-REFERENCE.md). Operational consequences —
schedules, exit codes, what to do at three in the morning — are in
[`OPERATIONS.md`](OPERATIONS.md).

---

## The EMR

### 1. `treatments()->sync()` returns a **list**, not an object

The sync response envelope's `data` is a **list of records**, holding one
element per treatment. The treatment's fields are at `data()[0]`, not at
`data()`.

```php
$response->data()[0]['_id']              // correct
$response->data()['_id']                 // null, on every order, forever
$response->data()['summary']['cycle_status']  // null, on every order, forever
```

**This is the most expensive mistake available in this integration**, because
it does not fail. Reading the response as an object returns `null` for every
field on every order. No exception, no warning, no log line. A receipt built
that way renders an empty clinical status permanently and looks like a display
bug for as long as anyone cares to look.

Written summaries of this API flatten the record's fields to top-level paths.
They are wrong. `core/Checkout/EmrCheckoutEventReporter.php` indexes the list,
and a test pins the flattened shape as one it must refuse — that test exists
specifically to stop someone "simplifying" the index away.

The EMR treatment id — the thing `[19.2]` and `[19.9]` call the treatment
reference — is at `data()[0]['_id']` and is free to read from a response the
storefront already receives.

**The value is not unique per order, and that is the EMR's model rather than a
collision.** One session's orders accumulate into a single treatment record,
with every provider reference listed under its `external_refs`. A checkout that
places two orders stamps the same treatment id on both local rows. Nothing
queries that column by value, and nothing should start.

Related: **`sync()` is deduplicated per session.** Calling it repeatedly for
the same session does not create a second clinical record. That is what makes
the reconciliation sweep safe to run on a schedule and safe to overlap with
itself.

### 2. `treatments()->list()` cannot be filtered at all

Every filter this endpoint accepts is **inert**. Verified against a channel
holding exactly one treatment:

| query | result |
|---|---|
| date range covering **1990** | the same single row, `total: 1` |
| a bogus `status` value | the same single row, `total: 1` |
| a nonexistent `patient_id` | the same single row, `total: 1` |

Only `page` and `limit` are honoured. The parameters are accepted, produce no
error, and change nothing about the result.

Two consequences, and the second is the one that shapes the architecture:

- **A job cannot ask the EMR for recent or unsynced treatments.** It can only
  page the entire channel and filter client-side.
- **Nothing in the `list()` projection can be matched to a local order.** The
  projection has **no external-references key at all** — not null, absent —
  and its `order_id` is a small per-channel integer (`1`, `2`, …), not a
  payment-provider reference. Matching requires `view()`, one call per
  treatment.

This is why reconciliation runs **forward** — from a local order to the EMR —
rather than pulling a list of treatments and diffing it. There is no diff to
take.

### 3. The same treatment has three incompatible read models

`sync()`, `list()` and `view()` return three genuinely different projections of
one record. Choosing the wrong one is silent, in the way §1 describes.

| | `sync()` | `list()` | `view()` |
|---|---|---|---|
| envelope | **list of one** | list, paginated | object |
| treatment `_id` | `data[0]._id` | present | **absent** |
| external order refs | `external_refs` | **key absent** | under `address_and_payment` |
| clinical status | `status`, `lifecycle`, `summary.*` | partial | different shape again |
| analytics session id | `attribution.session_id` | absent | absent |
| money | `12000` — integer cents | — | `"240.00"` — decimal **string** |

`patients()->view()` is a fourth projection and carries none of the treatment
fields at all.

Note the money row: the **same amount** is an integer in one projection and a
decimal string in another. Never move a numeric field between these projections
without converting it.

`view()` on an unknown id throws a distinct `NotFoundException` (HTTP 404),
which is useful for branching — it is a different class from the generic API
exception, not a status code to test.

### 4. The EMR stores integer cents verbatim where it documents a float dollar amount

A field documented as a dollar amount — `240.00` — accepts `24000` **without
complaining** and stores it exactly as sent. There is no validation, no
coercion and no error.

So a skipped conversion produces an order recorded at a hundred times its real
value, in the clinical system, with every call returning success. It is
invisible everywhere except in a test that asserts the stored figure.

This codebase holds money in integer cents throughout.
`core/Checkout/Totals.php` has the only two exits from that representation —
`dollars()` for the EMR and `decimalString()` for the payment provider. Both
are named, both are tested, and neither is inlined anywhere. **Do not inline
them.** A conversion written at a call site is a conversion nobody can grep for
when the figures come out wrong.

### 5. A missing required field is an exception, not a `valid: false` body

The identity check has **three response channels**, and two of them look alike
if you only read the outcome:

| channel | what it means | what it is |
|---|---|---|
| HTTP 200, body carries `valid` | the provider reached a verdict about this person | a statement about the buyer |
| **`ApiException`, HTTP 400** | **our request was malformed** | a bug in this code |
| anything else — 403, transport failure, outage | the check could not run | inconclusive |

**`valid: false` and HTTP 400 mean completely different things.** One is a
refused identity. The other is a request that was never evaluated because a
required field was missing — recorded example: the date-of-birth check called
without `phone` throws HTTP 400, `"Phone number is required."`

Collapsing them presents our own bug to a real buyer as "you failed identity
verification". So the 400 branch is logged at **warning** — nobody is coming to
fix a malformed request unless the log says so — while the inconclusive branch
is logged at info, and neither is ever shown to the buyer as a failure.

**Every verdict is HTTP 200.** For a plausible identity and for nonsense alike.
Any branch on the HTTP status to decide an *outcome* is wrong by construction;
the status only ever tells you whether the request was well formed.

`valid` is also nullable — the provider answering and deciding nothing is a
third state, distinct from `false`, and the code carries it.

Two further properties of this integration worth knowing before enabling it:

- **The identity submitted is retained by the provider.** The response echoes a
  `store: true` flag on its own request. That retention is outside this
  storefront's control and outside its retention policy. Nothing on this side
  can shorten it.
- **The shape of a passing check is not established against the sandbox this
  was measured on** — every identity submitted returned `valid: false`, real
  and fabricated alike, with the score check returning exactly `0.000` against
  a threshold of `0.75`. That is why the feature ships disabled and
  non-blocking: switched on as a hard gate in such an environment, **every**
  buyer fails. The passing branch is exercised against a stub, deliberately,
  because there is no live input that produces one.

### 6. The checkout event record is one row per session, updated in place

The EMR's checkout event is **not an append-only log.** It is a single record
per analytics session, created once and then overwritten as the journey moves.

Two things follow, and both have already caused bugs:

- **An update with no create before it is refused.** The funnel record has to
  be opened when the checkout page is *rendered*, not when it is submitted, or
  every subsequent order event is lost. That is why this storefront reports
  `checkout.visited` on render.
- **History does not survive.** A decline the buyer retried past is simply gone
  from the record — the successful attempt overwrote it. So the EMR cannot
  answer "how many declines happened", and neither can the local `orders`
  table, since no order row exists for a charge that never landed.

This is why the decline rate and the "journeys reaching payment without a
session" count are computed from the **local** `events` table, which *is*
append-only. It is the only place either question can still be answered.

The event enum also has no order-bump case; a bump is recorded locally and
folded into the order total the EMR sees.

### 7. `opportunity.gender` as a one-element list is refused — and takes the whole update with it

```
gender: ["female"]   → HTTP 400, and the entire opportunity update fails
gender: "female"     → accepted
```

The record's fields are scalars, and a single-element list is not a scalar it
will coerce. The rejection is not confined to the offending field: the whole
update is refused.

This matters more than it sounds, because **form authors routinely build a
one-answer question out of a multi-select control.** The intake form's answer
for such a question arrives as a list of one, and passing it through
unflattened means every opportunity update fails, for every buyer, permanently.
This was the difference between edits flowing back to the EMR and nothing
flowing back at all.

`core/Forms/RecordMapper.php` therefore writes a one-element list as the value
it holds, logging `intake.multi_value_flattened` so a flatten that is ever
wrong stays traceable rather than invisible. **A genuine multi-selection — two
or more values — stays a list.**

### 8. `phone` is accepted as a plain string; the documented structured shape returns 400

The SDK documents phone as `array{code, number}`. **That shape is what fails.**
A plain string is accepted.

Do not "fix" the mapper to match its docblock. This is the one in the set most
likely to be undone by a well-meaning developer reading the type annotation and
concluding the code is wrong.

The same lesson applies to the intake form's field types: the authored type
vocabulary is accepted verbatim on both create and update, and read back with
those exact types stored. There is no translation table and none should be
added.

### 9. Polling is the only mechanism; there is one delete route

**There is no webhook or notification resource anywhere in the SDK.** The
`Resource/` directory is Carts, Categories, Channels, CheckoutEvents,
DoctorsNetworks, Geo, IntakeSubmissions, LabTests, Medications, Opportunities,
Patients, Products, Sessions, Shippings, Teleforms, Treatments, Verification —
and nothing else. Nothing in the contract names an inbound transport either.

Polling `treatments()->sync` or `treatments()->view` is the only mechanism
demonstrated on the wire. An inbound authenticated receiver is a legitimate
thing to build, but it is an invention: nothing shows the EMR would ever call
it.

**No SDK route advances a treatment.** `Treatments` exposes `create`, `view`,
`list` and `sync`. There is no way to stage an approved or rejected clinical
outcome from this side. The record does move on its own — a treatment's cycle
count advances between reads with no call from here — which makes the EMR the
owner and this storefront a mirror.

**Exactly one deletion route exists across the whole `Resource/` directory:**

```
$ grep -rn "function delete" vendor/astermd/sdk/src/Resource/
vendor/astermd/sdk/src/Resource/Sessions.php:156:    public function delete(string $session): Response
```

It removes an analytics session, not a clinical record. See
[`OPERATIONS.md`](OPERATIONS.md) §5 for what that means for a deletion request.

### 10. The bearer token carries a permission snapshot, cached for a day

The EMR bakes a permission snapshot into the bearer token — its JWT carries a
`user_info.permission_key` claim — and the token is cached until its stated
expiry, measured at exactly **24 hours** from issue.

A permission granted or revoked server-side therefore has no effect here until
the token is re-issued. Run `bin/console cache:clear` before concluding
anything about a 403. Full procedure in [`OPERATIONS.md`](OPERATIONS.md) §4.2.

---

## The payment provider

### 11. A null `status_type_id` means the card was **never charged**

The behaviour contract says the opposite — `[14.10]` says that no status counts
as an order placed. **The wire disagrees, and the wire wins.**

An order with a null `status_type_id` is an order *container*: `date_ordered`,
`date_authorized` and `date_capture` are all null too. It was created and never
charged. In one recorded window it was **55 of 143 orders**; in a larger sample,
521 of 1,400. This is a common state, not an edge case.

**An uncharged order is not a lost charge.** The reverse reconciliation sweep
counts these apart from its headline figure for exactly that reason — folding
them in would report hundreds of phantom lost charges, and an operator who
learns to ignore the number stops reading it at all.

Both flags in that projection fail **toward reporting**, not toward silence: a
missing or unrecognised test flag is read as a live order, and a missing
`status_type_id` **key** (as opposed to a null value) is read as charged.
The asymmetry is deliberate — over-reporting costs an operator one lookup in
the provider's dashboard; under-reporting loses a charge nobody ever hears
about again.

The status is not classified further at this point. An unmatched order the
storefront has no record of cannot be shown from these fields to have been
voided or refunded afterwards, so a charged order is reported whatever its
status and the raw value travels along for a person to read.

### 12. The provider has no idempotency

**An identical payload posted twice creates two orders and charges both.**
There is no idempotency key, no request-hash deduplication, and no header that
provides one. This was established by doing it.

Everything about the checkout path's serialisation follows from this fact. A
`checkout_attempts` row is claimed *before* the provider is called, and the
claim is what stops one submission becoming two charges. That table's rules
exist to protect this one property:

- **A `claimed` row may be taken over** by a later request, however old it is —
  that request never reached the provider.
- **A `sent` row is never taken over and never deleted, at any age.** That
  request *did* reach the provider, and the provider never answered.

  **Age proves a request is not coming back. It proves nothing whatever about
  whether it charged the card.** Reconstructing such a row and resubmitting
  past a timeout is precisely what produces a double charge.
- **The takeover is a single conditional update**, never a delete followed by a
  fresh claim. Deleting the row destroys the very thing two racing requests
  were serialising against — both re-insert, both charge — and the delete could
  land on a row that had meanwhile become `sent`.

`db:prune` inherits all of this: it never expires a `sent` attempt, and it
reports them for a human instead. A sweep that took the wrong row here would
not merely lose data — it would re-arm the double charge.

### 13. `session_id` is write-only, and `connection_order_id` comes back overwritten

This storefront sends `session_id` — the analytics session uuid — on every
order. **It is returned by neither order search nor order fetch.** The key is
absent from both projections, on every order.

`connection_order_id` is worse than ignored: it comes back **overwritten with
the provider's own order id**, on 143 of 143 orders in the recorded window
(`"34788"` on order 34788). It cannot carry anything.

What does and does not round-trip:

| field | round-trips? |
|---|---|
| `session_id` | **no** — absent from every read projection |
| `connection_order_id` | **no** — overwritten with the provider's order id |
| `cart_token` | not set by order creation (the field works; this path does not populate it) |
| `tracking1`–`tracking20` | **yes** — all twenty, verbatim |
| `ip_address` | yes, verbatim |
| `user_agent` | **no** — parsed and rewritten as `"Other\|Desktop\|0\|0\|0\|0"`, useless for matching |

**Only the twenty tracking slots survive the round trip**, and all twenty are
already spent on attribution: one affiliate id, thirteen sub-identifiers, five
UTM parameters, one more sub-identifier.

**This is why the reverse reconciliation sweep counts rather than attributes.**
An order the provider took that has no local row cannot be traced back to the
journey that placed it, because the identifier this storefront submitted does
not survive. The sweep can say *that* a charge is unrecorded and print the
provider's reference; it cannot say *whose* it was. Recovery is a person
reading those references in the provider's dashboard.

Dedicating a tracking slot to a recovery key would work, and would cost a
sub-identifier. That trade was considered and declined: detect and count, do
not attribute. Do not spend a slot without re-taking that decision.

### 14. A decline still creates an order, and still returns a reference

A declined card does not mean no order exists at the provider. The failing
response carries an order reference nested at `data.error.transaction.order_id`
— a different path from the success response's, which is why both are read.

Record it. It is the only handle anyone has on a decline when a buyer calls to
ask why their card was charged (it was not) or why they got an email (they
should not have).

### 15. Two parameter names that fail silently

Both of these are accepted, produce no error, and quietly do the wrong thing.

- **The discount parameter is `discount_code`, not `code`.** Sending `code`
  returns a successful response with no discount applied.
- **The quote endpoint reads the quantity from `offer_quantity`; the order
  endpoint reads it from `order_offer_quantity`.** They are different keys for
  the same concept on two endpoints of the same API. Sending the wrong one is
  not an error — the call succeeds, and the quantity silently defaults.

The consequence of the second is that a quote and the charge it produced can
disagree about how many of something the buyer bought, with both calls
reporting success. The two key names are named constants in
`core/Payment/Vrio/VrioOffers.php` for that reason.

### 16. Money encodings differ by direction

The provider's order-search projection returns its only money field — the order
discount — as a **decimal string**. The payload sent to it uses a fixed
two-place decimal string as well. This codebase's internal representation is
integer cents.

Conversion happens once, at the adapter boundary, so nothing above it ever sees
provider money encoding. Keep it there.

### 17. `action: "authorize"` is accepted; the approved `status_type_id` is the one thing unrecorded

**What the wire does.** `POST /orders` accepts
`action: "authorize"` on exactly the same required fields as
`action: "process"` — no extra parameter, no different endpoint, no second
call. The authorize request built order 36727, reached the acquiring gateway
and priced the transaction at `120.00`. Nothing else in the payload changed;
`tests/Payment/Vrio/VrioPayloadTest.php` asserts that as a whole-payload diff so
a future change that quietly varies a second field has to be stated.

`POST /orders/{id}/capture` takes an **empty body** and captures the most recent
successful authorization. Capturing an order that never authorized answers a
typed refusal — `success: false`, `data.error.code` and `validation_code` both
`order_unauthorized`, message "Order has not been authorized.", and **no
transaction node**. That is a stated refusal rather than a transport error or a
silent no-op, which is what lets a failed capture tell an operator which kind of
failure they have.

The order node carries `date_auto_capture`, and the provider will also settle on
a campaign-level trigger. **Neither is used.** Both would put the decision of
when money moves inside a payload written at checkout, before the event that
decides it has happened.

**What could not be recorded, and why it matters.** The `status_type_id` an
*approved* authorize returns on this account is **unverified**. The sandbox
merchant's acquiring gateway was answering `Error 0:Invalid API Key provided`
with `gateway_response_code: "500"` for every charge at the time — a control run
with `action: "process"`, the path production uses today, failed identically —
so an approved authorize could not be taken. A *declined* one was, and it is
shape-identical to a declined capture: reference at
`data.error.transaction.order_id`, `success: false`, `response_code: 200`,
`status_type_id` null.

That gap is why `core/Payment/Vrio/VrioOutcome.php`'s authorize branch is
the **minimum** change from the capture rule rather than a re-derivation. Item
11 above — a null status means never charged — inverts under authorize, because
an order with nothing charged against it is exactly what that action asks for.
Everything else recorded stays load-bearing: the envelope's `success` flag and
the presence of a reference still decide, and terminal statuses are still
terminal, since a cancelled or refunded order is not a live authorization
waiting to be captured.

**When the gateway credentials are fixed, re-run the probe and pin the real
status.** If an approved authorize turns out to carry a status in the terminal
list, this branch is wrong and every authorization will read as a decline.

### 18. The live channel payload stopped carrying `campaign_id`

`channels()->details()` returned a
`payment_processor.config` with `integration_name`, `api_endpoint`, `api_key`
and `connection_id` — and **no `campaign_id`**. The synced
`config/channel.generated.php` still had it, and that file is what the
application reads, so nothing was broken. A probe that fetched the channel fresh
instead posted against campaign `0` and was refused with
`Invalid campaign id : 0`.

The lesson is narrow and worth keeping: **read the synced file, not a fresh
channel fetch**, when reproducing what the application does. The two are not
always the same, and `theme:sync`'s merge semantics are what preserve a key the
payload has stopped sending.

## The second payment provider

Everything above under "The payment provider" is the `vrio` adapter's. This
section is the `checkout_champ` one, and the two disagree about almost
everything except the envelope-shaped fact that both disagree with their own
documentation.

### 19. Placement is two calls, and the second one alone says "Customer not found"

`POST /order/import/` bills a session; `POST /leads/import/` creates the
customer and answers the `sessionId` it bills against. Calling `/order/import/`
or `/order/preauth/` without one answers `"Customer not found"`, which is how
the sequence was established rather than assumed.

The cost is a second round trip inside a request the buyer is waiting on, and a
lead created for an order that then fails — a CRM row rather than a charge, and
the provider's own model rather than a choice this storefront made.

### 20. The envelope is two keys, and `message` changes type between them

Every combination of `result` and `message` type occurs, so **`result` is the
only discriminator** — the obvious reading, string means failure, is wrong in
both directions:

| `result`  | `message` | recorded example |
|---|---|---|
| `SUCCESS` | object | a billed order: `orderId`, `orderStatus: "COMPLETE"`, `totalAmount` |
| `SUCCESS` | string | `"Card is preauthorized"` |
| `ERROR`   | string | `"Transaction Declined: Card Declined"` |
| `ERROR`   | object | `{"shipAddress1": "is a required field", ...}` |

Refusals taken verbatim from the live sandbox, and they are the fixtures under
`tests/fixtures/checkoutchamp-*.json`:

| Call | `message` |
|---|---|
| `/order/import/` with no parameters | `No products exist in the order` |
| `/order/preauth/` with no parameters | `Customer not found` |
| `/leads/import/` with only a campaign | `First and last name are required fields.` |
| `/order/query/` for an unknown order | `No orders matching those parameters could be found` |

Note the first: **an order with no campaign is reported as an empty cart.** A
configuration fault described as a cart problem is the worst possible place to
debug one, which is why the adapter refuses an unconfigured campaign before the
wire rather than letting the provider answer.

`result` is the provider's own verdict field, so unlike the other provider's
`success` flag it is not derived from the absence of an error key — but it still
cannot survive a body that never decoded, since a proxy's HTML error page has no
`result` at all. The check is for the literal `SUCCESS` rather than for the
absence of `ERROR`.

### 21. Every parameter travels in the query string, including the card and the password

This provider authenticates with `loginId` and `password` as **query
parameters**, and takes the card number, expiry and security code the same way.
That is the provider's design and the client follows it.

The consequence is not theoretical and is larger than the collection surface:
anything on the egress path that records request URLs — a forward proxy, an
egress gateway, an APM agent, a TLS-inspecting appliance, a crash reporter —
records cardholder data *and this deployment's provider password* in clear text.
A URL is the part of a request most things copy by default.

Two things follow in this codebase. `CheckoutChampWireLog` is documented as
holding credentials as well as card data, so a deployment that switches it on
treats the file as a secret to destroy rather than a log to ship. And the
adapter's declared PCI posture says all of this, so `config:validate` prints it
to an operator before they go live rather than after.

### 22. The campaign is a line's `offer_id`, and the product id is the campaign-scoped one

The EMR channel's `payment_processor.config` for this provider carries no
campaign, and it does not need to: **the campaign is per line**, carried by the
catalog as a variant's `provider.offer_id` — the same slot the other provider
fills with its offer id. A second provider therefore needed no new catalog
field, which is the strongest evidence `[14.1]` capability 3 was drawn in the
right place.

The companion `provider.product_id` is the **campaign-scoped** product id, not
the bare one. A campaign lists each product under two identifiers:

```
campaignProductId: 15271     <- what the EMR maps, and what an order is placed with
productId:         14015     <- also shown in parentheses at the front of productName
productName:       "(14015) NAD+ (500MG)"
```

Sending the bare `productId` would name a product the campaign does not offer,
which the provider reports as the cart being empty. The EMR maps the right one
already.

**Two consequences for the adapter.** The campaign is resolved from the order
rather than from configuration, so it can be *missing* or *contradictory* in a
way a configured value could not — a cart whose lines carry two different
campaigns has no correct single answer, since a cart is one order (`[13.19]`) and
an order belongs to one campaign. Both cases are refused before the wire, because
the provider answers either one with "No products exist in the order": a catalog
fault described as a cart problem, which is the worst possible place to debug one.

**A caution about `campaignQuery`.** Called with no parameters it returns a
*page*, not the account. Reading that page as the whole account is how campaign
459 came to look absent from an account that has it — pass `campaignId` to ask
about one.

### 23. The full flow, and the two calls that are not what their names suggest

Placement, recorded end to end:

```
POST /leads/import/    -> {"result":"SUCCESS","message":{ "orderId":"D6C8C390A7",
                                                          "orderStatus":"PARTIAL", ... }}
POST /order/import/    -> {"result":"SUCCESS","message":{ "orderId":"D6C8C390A7",
                                                          "orderStatus":"COMPLETE",
                                                          "totalAmount":"0.30",
                                                          "customerId":19241, ... }}
```

**`leads/import` is not only a lead.** It creates a PARTIAL order and answers the
`orderId` every later call is keyed on. The reference therefore exists *before a
card is presented*, which is what lets a refused placement still carry one
(`[13.26]`) even though the refusal envelope contains no order id at all.

**`order/import` is also the capture.** There is no endpoint named for it:

```
POST /order/preauth/   -> {"result":"SUCCESS","message":"Card is preauthorized"}
POST /order/import/    -> {"result":"SUCCESS","message":{ "orderStatus":"COMPLETE", ... }}
```

and the settling call carries the **lines and no card**. Both matter:

- **Without the lines it is refused** — `"No products exist in the order"` — and
  the order stays PARTIAL *with the funds still held*. An error that leaves money
  reserved is the worst shape a failure can take here.
- **The lines cannot be recovered from the provider.** A pre-authorized order's
  `items` is an empty stub (`productId: null`) until the settling call supplies
  them, so re-reading the order first returns nothing usable. `order_lines` is the
  only place they still exist, which is why
  `Payment\CaptureRequest` carries them and why an unreadable local row is a
  stated failure rather than something to push past.

Shipping is required on `order/import` as well as on the lead call; omitting it
answers the field map in item 20.

### 24. A PARTIAL order is reused, so a reference is not unique across attempts

Recorded: a decline leaves its PARTIAL order in place, and the **next lead call
reuses it** rather than creating a second one. Two attempts then share one
`orderId` — a decline and the retry that succeeds.

Varying the IP address produced distinct orders while varying only the name,
email and telephone did not, so the address appears to be at least part of what
the provider matches on. That has not been characterised further and should not
be relied on.

**The consequence is local, not remote.** `orders.provider_reference` is not
unique and cannot be made so. `OrderRepository::findByReference()` therefore
orders by `id DESC` and answers the newest row: without that, SQLite returns the
lowest rowid — the declined attempt — and `bin/console payment:capture` reads a
row saying the order was already captured and refuses to settle an authorization
that is really outstanding.

The reuse is not itself a problem: it is why a retry after a decline produces no
orphan order at the provider.

### 25. What is still not recorded

Two things, both declared `false` on the adapter rather than guessed at:

- **`supportsOrderSearch`.** `orderQuery` works and its projection is rich, but
  `[21.9a]`'s reverse sweep turns its findings into alerts about money, and the
  fields it would have to map — which status counts as charged, which as test —
  have not been established across a real window. Declaring false produces *no
  sweep*, which the sweep is explicitly built to distinguish from a clean bill of
  health.
- **`supportsPromotions`.** The client exposes no discount-quote endpoint, so
  `[14.4]` hides the control rather than offering a box that can only reject.

---

## What to take from all of this

Three patterns run through every item above.

**Failures here are silent by default.** A list read as an object, a filter
that is ignored, a cents value stored as dollars, a discount code under the
wrong key — none of them raises anything. Every one returns a success and a
wrong answer. When something in this integration looks wrong, the working
hypothesis should be a shape mismatch that both systems consider fine, not an
error you can go and find in a log.

**The documentation is a hypothesis; the wire is the evidence.** Several items
above exist specifically because a docblock or a written summary described a
shape the live system rejects. Where this document and an annotation disagree,
change the annotation.

**When you find a new one, write it down here.** Each of these cost a day. The
only thing that makes that a one-time cost is the note.
