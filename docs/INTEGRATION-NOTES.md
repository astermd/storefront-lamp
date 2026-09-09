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
