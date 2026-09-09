# Specification reference

Source files in this repository cite behaviour rules by id. They look like this:

```php
 * `[7.8]` establishes that a bundled line is not the buyer's to change;
 * this guard applies that same rule to `add()` when a request names the
 * child's slug directly [...]
```

They appear in comments, in configuration docblocks, in template annotations
and in test names, and they all mean the same thing: **the code they sit next
to exists in order to satisfy that rule.**

## Why the citations are there

The storefront was built against a written behaviour contract — a numbered set
of statements about what the funnel must do, covering the cart, the intake
form, checkout, upsells, failure handling, retention and the rest. Much of that
behaviour is not self-evident from the code. A cart line that refuses to be
removed, an intake step that will not advance, a verification call whose result
is recorded but ignored: each looks like a bug until you know it was asked for.

The citation is what separates the two. A rule id next to a piece of code is a
claim that the behaviour is deliberate and an invitation to check it — and its
absence is equally informative, because unclaimed behaviour is nobody's
decision.

## What this document is

**The contract document itself is not part of this repository.** This file is
the whole of it that ships. It resolves every rule id the code actually cites
to a one-line statement of what that rule requires, so that a citation can be
looked up without the original to hand.

A few things worth knowing before reading:

- **Only cited rules appear here.** The contract contains more rules than the
  code cites; the uncited ones resolve nothing a reader of this repository will
  encounter, so they are omitted.
- **Each entry is one line.** Where a rule's substance was a table or a list —
  a routing table, a set of placements, a category breakdown — the entries are
  folded into the line and separated by semicolons. Long ones are cut with an
  ellipsis.
- **Ids are grouped by the contract's own sections**, in numeric order, and
  ordered numerically within each section.
- **Some ids carry a letter suffix** (`[7.14b]`, `[8.0h]`). Those are clauses
  inserted between existing ones; the suffix is part of the id, not a variant
  of the number before it.
- **A citation records intent, not completeness.** Some cited rules describe
  behaviour this storefront deliberately does not implement, and the code
  citing them is usually the code that refuses to pretend otherwise.

## 1. Vocabulary and core entities

- **`[1.1]`** — One analytics session links to at most one opportunity in practice, though the EMR model allows more.
- **`[1.5]`** — A cart entry may declare a parent entry, marking it as a bundled or free-attached child.

## 2. Configuration and the catalog model

- **`[2.2]`** — Products are matched between layers by **stable EMR product identifier**, never by slug or name.
- **`[2.4]`** — An override may deliberately re-key an entry by declaring an explicit slug.
- **`[2.5]`** — Variant overrides are applied per-variant and must not clobber the base's variant ordering or drop variants the override doesn't mention.
- **`[2.10]`** — **Slug** = a short deterministic prefix derived from the EMR product identifier, plus the slugified product name.
- **`[2.12]`** — **Lab tests** attached to a product become standalone `lab` products and are recorded as mandatory bundles of their parent.
- **`[2.13]`** — **Add-ons** attached to a product become standalone `free-addon` products and are recorded as free attachments of their parent.
- **`[2.14]`** — When the same nested product is referenced by two parents, it is emitted once and both parents reference the same slug.
- **`[2.15]`** — When the EMR carries no variants for a product, one variant is synthesised from the product's single price row.
- **`[2.18]`** — Provider identifiers that the channel cannot supply (notably for lab and free-addon products) must be hand-added to the override layer.
- **`[2.21]`** — Config validation must assert that the declared active provider has a registered adapter, and that the adapter's required credential keys are all populated.

## 3. Media and asset caching

- **`[3.3]`** — A failed image download is reported to the operator but does not fail the sync.
- **`[3.6]`** — **Form definitions are fetched from a signed remote URL on effectively every request** that renders a form.
- **`[3.10]`** — Cached filenames carry a **content hash or version suffix**, so a changed asset produces a new URL.
- **`[3.11]`** — **Form definitions are cached server-side** with an explicit time-to-live and an operator-invokable purge.
- **`[3.12]`** — Cache invalidation is driven by the sync process for catalog media, and by time-to-live plus explicit purge for form definitions.
- **`[3.14]`** — Orphan collection: media belonging to products no longer in the catalog is identified and removable by an operator command.

## 4. Identity, session and journey lifecycle

- **`[4.1]`** — An analytics session is created **lazily, on first need**, and in practice that means on the landing page — as soon as the visitor arrives, not deferred to the first cart action.
- **`[4.2]`** — Session creation is **idempotent**: if a session identifier already exists for this browser, it is reused and no new session is minted.
- **`[4.3]`** — Session creation must **forward the visitor's own IP address and user agent**, so the EMR attributes the session to the end user's device and location, not to the storefront server.
- **`[4.4]`** — The session-creation payload carries whatever first-touch attribution is already known: the landing referrer, the UTM triple (source/medium/campaign), and the first five custom sub-identifiers.
- **`[4.5]`** — If the EMR does not return a session identifier, that is a hard error at the point of creation — but every caller wraps it so that a failure degrades to "no session" rather than a broken page (see §19).
- **`[4.6]`** — The session identifier is stored in a cookie: 30-day lifetime, HTTP-only, same-site lax, secure when served over TLS.
- **`[4.10]`** — The adopted identifier must be **shape-validated** before it is trusted; a malformed value is ignored silently.
- **`[4.12]`** — Because the session cookie outlives the local working state, a returning visitor can arrive with a valid session but no local memory of what already happened.
- **`[4.13]`** — Lifecycle "initiated" events are fired **only if the server has not already recorded them.** This is what prevents duplicate lead records and duplicate form submissions on a return visit.
- **`[4.14]`** — This reconciliation is a **single read**, not one call per fact.
- **`[4.15]`** — Reaching the receipt page **ends the journey.** After the completion actions in §16 have fired, the working state is wiped down to two things: the order receipt, and a guard flag preventing the completion actions from re-firing on refresh.
- **`[4.16]`** — Everything else is cleared: cart, form answers, captured attribution, buyer contact, and any held payment credential.
- **`[4.17]`** — The receipt is retained specifically so that refreshing the page still shows the order.
- **`[4.18]`** — The **analytics session is torn down separately and on a delay.** The receipt page must keep the session alive because upsells are reached from it.
- **`[4.19]`** — This delayed teardown also covers the "closed the browser mid-upsell" case: the signal persists in client storage, so whenever the visitor next returns to any page, the stale session is cleared.

## 5. Traffic attribution

- **`[5.1]`** — Tracking parameters may arrive **encrypted under a single query key** rather than as plain parameters.
- **`[5.2a]`** — **Decryption happens in-process, using a locally held key.** It must not require a network call to the EMR or to the affiliate-link service.
- **`[5.2b]`** — The key is deployment configuration and must be **rotatable without a code release**.
- **`[5.3]`** — Decryption failure — absent, tampered, garbage, or wrong key — must **never break the page.** It falls back silently to whatever plain parameters are present, and logs the failure for operators.
- **`[5.4]`** — The encrypted wrapper key itself must **never leak into stored tracking data.** It is lifted out before normalisation.
- **`[5.6]`** — Every recognised vendor alias is mapped onto a single canonical key, so the rest of the system deals in one vocabulary.
- **`[5.9]`** — One raw parameter may legitimately feed **two different canonical keys**; this overlap is intentional and must be preserved.
- **`[5.10]`** — Provider tracking-slot names (`tracking1`, `sourceValue1`, …) are themselves accepted as inbound aliases, so traffic forwarded from another CRM still normalises correctly.
- **`[5.11]`** — Encrypted values **win over plain values, per canonical key.** Plain values fill only the gaps.
- **`[5.12]`** — Attribution is captured **only while nothing has been captured yet.** Once any tracking parameter set has been stored for this journey, later visits never overwrite it.
- **`[5.13]`** — The **landing referrer** is captured separately and also first-touch, even when there are no tracking parameters at all (organic and direct referrals).
- **`[5.14]`** — A **source category** is derived heuristically, first match wins: An affiliate identifier is present → affiliate; UTM medium is email or newsletter → email_campaign; UTM source or medium contains a known social network token → social_media; A landing referrer exists → referral; Otherwise → no category.
- **`[5.15]`** — **Device type, browser, and operating system** are derived from the user agent by coarse classification.
- **`[5.16]`** — The **client IP** is resolved in a fixed preference order: CDN real-client header, then the first hop of the forwarded-for chain, then the direct peer address.
- **`[5.18]`** — UTM source is **additionally** forwarded to the EMR at completion, because it has no guaranteed slot in any payment provider's tracking fields.
- **`[5.20]`** — Consent flags on the opportunity are **hardcoded to true** — a gap, and the one `[26.10]` closes by recording what was actually consented to instead.
- **`[5.21]`** — Attribution must **survive a resumed session.** Today it lives only in browser-scoped working state, so a visitor who returns via a resume link after their working state expired arrives with no attribution and the order is placed untracked.

## 6. Catalog presentation

- **`[6.1]`** — The product listing shows only `rx` and `otc` products.
- **`[6.2]`** — The listing price is a "from" price, taken from the product's first variant.

## 7. Cart rules

- **`[7.1]`** — Adding an unknown product is rejected with a domain error.
- **`[7.4]`** — **Default price on add:** rx products enter at zero; Everything else takes the first variant's price.
- **`[7.5]`** — **Mandatory bundles** declared on a product are added automatically, each recorded as a child of the product that pulled it in.
- **`[7.6]`** — **Free attachments** declared on a product are added the same way.
- **`[7.7]`** — Auto-added items are only added if not already present, so two parents requiring the same lab test produce one line, not two.
- **`[7.8]`** — An entry that has a parent **cannot be removed directly.** The attempt is rejected with a message naming the parent it is bundled with.
- **`[7.9]`** — Removing a parent removes the parent **and every entry that declares it as parent**, in one operation.
- **`[7.10]`** — The cart is mirrored to the EMR on **every mutation.** The first mirror for a journey creates the remote cart; every subsequent one updates it.
- **`[7.11]`** — The mirrored payload carries, per line: the EMR product identifier, the product name, and the quantity.
- **`[7.12]`** — Mirroring failure is logged and swallowed.
- **`[7.13]`** — **Quantity increment bypasses the geo gate and the bundle rules.** Adding a product a second time only increments; it does not re-evaluate whether the shipping territory is still permitted, nor whether newly-required bundles should come along.
- **`[7.14a]`** — **A `free-addon` line is always priced at zero on add**, regardless of what the catalog says its standalone price is.
- **`[7.14b]`** — **This is the line that separates the two attachment kinds**, which are otherwise identical in behaviour (`[7.18]`): free-addon; mandatory_bundle.
- **`[7.15]`** — There is no quantity ceiling, no stock concept, and no way to decrement a quantity — only add and remove-entirely.
- **`[7.16]`** — The cart must expose an explicit quantity-set operation, bounded by a per-product maximum, and re-running the geo and bundle rules on every change.
- **`[7.18]`** — Whether a given supply attaches as a **mandatory bundle** or as a **free attachment** is a per-supply configuration choice.
- **`[7.20]`** — A supply that is out of catalog, geo-blocked in the buyer's territory, or otherwise unpurchasable **must not silently drop.** It either blocks its parent with a stated reason, or is declared optional in configuration.

## 8. Funnel routing

- **`[8.0c]`** — **Bypassing the page must not bypass the rules.** Every §7 rule still runs on the logical add: geo gating, mandatory bundles, supply attachment, and cart mirroring.
- **`[8.0f]`** — **A single order carries at most one `rx` product.** A buyer wanting two prescription treatments places two orders.
- **`[8.0h]`** — Adding a second `rx` product must therefore be handled explicitly, not left to chance.
- **`[8.1]`** — After the cart, the next step is chosen by this table, first match wins: Cart is empty → Home; Any cart item opts into a dedicated pre-qualification step → Pre-qualification; Any cart item is rx → Intake; Otherwise → Checkout.
- **`[8.2]`** — **Pre-qualification is opt-in per product.** The default is that pre-qualification questions are folded into the intake form; a product must explicitly declare both "requires pre-qualification" and which form to use in order to get a dedicated step.
- **`[8.3]`** — **Intake dispatch:** the intake step routes to the form of the _first_ cart item that declares one.
- **`[8.4]`** — Fixed step transitions: pre-qualification completion → intake; intake completion → plan selection; plan selection → checkout; checkout success → upsell queue if non-empty, else receipt.
- **`[8.5]`** — **The eligibility rule is dead code.** A pure rule exists that disqualifies a visitor on age under 18, BMI under 27, or pregnancy — with missing answers treated as disqualifying — and a "not eligible" page exists to receive them.
- **`[8.6]`** — **Routing is only evaluated on explicit forward navigation.** Typing a later step's URL directly is not guarded: a visitor can land on checkout with an empty cart, or on plan selection having never completed intake.
- **`[8.7]`** — Only the _first_ cart item's form is used for intake.
- **`[8.8]`** — Routing must become **declarative** rather than a hardcoded chain — see §22.

## 9. Pre-qualification

- **`[9.1]`** — The pre-qualification form is **defined server-side by the EMR** and fetched at render time.
- **`[9.2]`** — The form to use is taken from the first cart item that opts into a dedicated pre-qualification step.
- **`[9.4]`** — **As soon as both first name and email are known** — detected client-side on field blur, before the form is submitted — the storefront posts what it has so far and creates the lead record.
- **`[9.5]`** — Early capture fires **at most once per page load.** A failed request re-arms it so a network blip does not permanently lose the capture.
- **`[9.6]`** — Early capture returns whether a record was actually created, so the client can distinguish "captured" from "already had one".
- **`[9.7]`** — The full record payload is rebuilt from **everything known so far** on every write.
- **`[9.8]`** — If an opportunity identifier is already known, the write is an **update**.
- **`[9.9]`** — If not, the write is a **create — but only once the minimum viable payload is present**, which is first name plus email.
- **`[9.10]`** — A newly created opportunity's identifier is remembered for the rest of the journey, and is recoverable from server-recorded session state on a return visit.
- **`[9.11]`** — Prefill precedence: **server-saved answers win**, and local working state is used only when the server returns nothing.
- **`[9.12]`** — Only fields that exist in the _current_ form definition are prefilled.

## 10. Intake — the form engine

- **`[10.1]`** — A form is referenced by identifier on the product.
- **`[10.2]`** — The resolution must tolerate **three response shapes**: a signed URL to fetch the definition from, the definition inline, or the definition nested under a wrapper key.
- **`[10.3]`** — When the definition cannot be loaded, the page renders an explicit "form unavailable" message rather than an empty or broken form.
- **`[10.5]`** — A definition is a list of **pages**, each holding a list of **fields**.
- **`[10.6]`** — **All steps are delivered to the browser at once** and step navigation is client-side.
- **`[10.6a]`** — **Delivery is server-rendered markup, not client-built DOM.** Every step is rendered as real markup and hidden; navigation toggles visibility.
- **`[10.6b]`** — **Stated no-script behaviour at intake.** `[10.6a]` makes the fields present without scripts, but conditional logic, termination evaluation, progressive save, and step navigation all require them.
- **`[10.7]`** — A field may declare **subfields**.
- **`[10.9]`** — Choice options must accept **two shapes**: a plain string, or an object carrying a separate value and display label.
- **`[10.10]`** — Buttons declare their own action: previous page, next page, or submit.
- **`[10.11]`** — Fields marked hidden in the definition are rendered but not displayed, so they can still carry values.
- **`[10.12]`** — Structural and display-only elements — dividers, headings, paragraphs, alerts, modals, progress indicators, captchas, buttons — **carry no submitted value** and are excluded from anything sent to the EMR.
- **`[10.13]`** — **Authorable field types that silently do not render**: rating, slider, file upload, signature, image, image-selection, toggle, checkbox, terms, agreement, ranking, input-table, repeatable section, and all pickers other than date.
- **`[10.16]`** — **Combinator semantics:** rules are evaluated left to right.
- **`[10.17]`** — Conditions are re-evaluated on **every input and change event**, against the complete current form state — including values on other pages.
- **`[10.18]`** — An unrecognised operator evaluates false rather than throwing.
- **`[10.19]`** — **The required-attribute must track visibility.** Because all pages live in one form, a required field on a hidden page or a hidden conditional branch is not focusable, and native browser validation will silently block submission with no visible cause.
- **`[10.20]`** — Advancing a step validates only the **visible required fields of that step**: text-like controls must be non-empty after trimming; radio groups must have a selection; hidden fields are skipped.
- **`[10.22]`** — **All validation is client-side only.** The server accepts whatever is posted.
- **`[10.23]`** — On every step advance, the **complete accumulated answer set** is posted to the server and an in-progress event is recorded.
- **`[10.24]`** — The event carries the visitor's **position as a page/total pair**, clamped to the form's real page count.
- **`[10.25]`** — Position is omitted entirely when the form definition is unavailable, rather than reported as a guess.
- **`[10.26]`** — A failed progressive save **does not block the visitor** from advancing.
- **`[10.27]`** — Answers **accumulate and merge**; they are never replaced wholesale.
- **`[10.28]`** — The BMI field is a composite of a height subfield, a weight subfield, and a **hidden unit-system subfield**.
- **`[10.29]`** — The unit system is read from the hidden subfield's stored or default value, checking both the subfield itself and its properties.
- **`[10.31]`** — Calculation: Metric: height in centimetres converted to metres; Imperial: weight in pounds divided by height in inches squared; Result rounded to one decimal place.
- **`[10.32]`** — The result is displayed as a score plus a category, and written into a hidden field so it is submitted as an ordinary answer.
- **`[10.33]`** — Category bands: under 18.5 underweight; under 25 normal; under 30 overweight; otherwise obese.
- **`[10.34]`** — Calculation runs once on load so prefilled values show a result immediately, and on every subsequent input.
- **`[10.36]`** — **Prefilling a multiple-choice answer with exactly one selection flattens it to a scalar**, so it fails to re-check the box on the way back in.
- **`[10.38]`** — `disable` is distinct from `hide` and the difference is behavioural, not cosmetic: a hidden field is invisible and carries no expectation, whereas a disabled field **stays visible so the visitor can see it exists and understand that something they answered earlier made it unavailable.** Use hide to reduce noise; use disable to explain a consequence.
- **`[10.39]`** — A disabled field is excluded from validation and submits no value, exactly as a hidden one does.
- **`[10.41c]`** — **Residual case to watch: thresholds over a computed value.** A rule like "BMI under 27 disqualifies" is not a discrete question — BMI is calculated (`[10.31]`), not answered.
- **`[10.42]`** — A termination rule declares three things: the condition, the message to show, and the **termination mode**.
- **`[10.43]`** — Two termination modes must be supported: Soft → An interstitial appears explaining why the visitor cannot continue, with a way to correct the answer and continue if it was a mistake. Use where a mistyped answer is plausible; Hard → The journey ends. The visitor is routed to a terminal page, the funnel is closed, and the answer cannot be edited to escape it. Use for genuine clinical disqualifiers.
- **`[10.44]`** — **Termination is evaluated immediately on answer**, not deferred to submission.
- **`[10.44a]`** — **A hard termination blocks step advancement.** The visitor cannot reach the next step of the form, and cannot reach any later funnel step.
- **`[10.45]`** — **Termination must be re-evaluated server-side** on every progressive save and on final submission.
- **`[10.46]`** — A hard termination emits a distinct lifecycle event carrying **which rule fired** — the whole point of capturing a disqualification is knowing why.
- **`[10.47]`** — After a hard termination, the cart is cleared of the product that triggered it.
- **`[10.48]`** — A terminated journey may still be resumed by a retargeting link (§21), but re-enters at the terminal state and re-evaluates the rule against the stored answers.

## 11. Building the EMR record from answers

- **`[11.1]`** — Each form field may declare a **target path** on the EMR record.
- **`[11.3]`** — Only one target root is supported today.
- **`[11.4]`** — Fields with no target, no answer, or an empty answer are omitted.
- **`[11.5]`** — The payload is **always rebuilt in full from everything known so far**, so a later partial write can never unset an earlier field.
- **`[11.6]`** — **Readiness threshold for creation:** first name plus email.
- **`[11.7]`** — **Unit normalisation before sending:** Height is always sent as a whole number of centimetres; Weight is always sent as kilograms to one decimal place; Non-numeric values pass through untouched; The target match must work with or without a trailing value segment on the path.
- **`[11.8]`** — The channel binding is attached to every create and every update.
- **`[11.9]`** — The attribution block from §5 is attached on create when it has any content.
- **`[11.11]`** — Only the opportunity target root is handled.
- **`[11.12]`** — Consent flags are forced to true when the record is built — the same gap as `[5.20]`, closed the same way by `[26.10]`.

## 12. Plan and variant selection

- **`[12.4]`** — On selection, both the chosen variant identifier **and the variant's price** are written back onto the cart entry.
- **`[12.7]`** — Selection must be validated server-side: the variant must exist on that product, and every Rx line must have one before checkout is reachable.
- **`[12.9]`** — An invalidated selection routes the visitor back to selection rather than silently defaulting to the first variant.

## 13. Checkout

- **`[13.1]`** — Collected buyer fields: first name, last name, email, phone, address line, city, territory (uppercased two-letter code), postal code.
- **`[13.2]`** — Billing address is taken to be the same as shipping.
- **`[13.3]`** — The submitted territory is recorded on the cart as the shipping territory.
- **`[13.4]`** — Payment credential collection depends on the active provider's strategy — see §15.
- **`[13.5]`** — Server-side validation is limited to "present and trimmed".
- **`[13.5b]`** — **Checkout must be assembled dynamically from intake data.** Every field the intake already answered is prefilled from the stored answers, resolved through the same field-mapping used to build the EMR record (§11) so there is one mapping, not two.
- **`[13.5c]`** — Prefill precedence matches the rest of the system (`[9.11]`): server-stored answers first, local working state as fallback.
- **`[13.5g]`** — When intake was skipped (a product with no form, per `[8.3]`), checkout falls back to collecting everything.
- **`[13.6]`** — On submit, the geo gate is **re-run against the freshly submitted territory** across every cart line — not just against whatever was known earlier.
- **`[13.7]`** — If anything is blocked, the visitor stays on checkout with a message **naming the blocked products**, and the cart is left completely intact.
- **`[13.8]`** — Applying a code is a two-phase operation against the provider: Validate the code against the cart's offers; Calculate the total discount across all lines.
- **`[13.9]`** — An empty code and an invalid code produce distinct messages.
- **`[13.10]`** — Lines with no resolvable offer are skipped when building the validation set.
- **`[13.11]`** — The stored promotion is the code plus the calculated discount amount.
- **`[13.12]`** — **The discount is clamped so the total can never go negative.** This clamp is applied everywhere the total is computed: on display, on the funnel event, and on the order.
- **`[13.13]`** — Removing a code clears the stored promotion and returns to checkout.
- **`[13.14]`** — Promotion validation and calculation failures degrade to "invalid code" / "zero discount" rather than erroring.
- **`[13.16]`** — Within an order, the discount code is attached to the **first line only**.
- **`[13.17]`** — The promotion is dropped after a successful checkout so it cannot bleed into the upsell flow.
- **`[13.18]`** — Promotion capability is assumed to exist.
- **`[13.19]`** — **The whole cart becomes ONE order.** Every line — top-level products, mandatory bundles, and free attachments alike — is a line item on a single charge.
- **`[13.22]`** — The order payload includes **request context**: the visitor's IP address, the visitor's user agent, and the analytics session identifier.
- **`[13.23]`** — Provider-formatted attribution fields are merged into the payload — see §14.
- **`[13.24]`** — **A placement counts as successful only when both** an order reference was returned **and** the returned status is not a decline or reject status.
- **`[13.25]`** — **A missing order reference is a failed placement, not an exception.** The visitor must be able to see a reason and retry, not hit an error page.
- **`[13.26]`** — A declined charge may still return an order reference — the provider creates the order and the card fails.
- **`[13.27]`** — Order reference and status live at **different response paths on success versus decline.** The adapter is responsible for finding them at every known path.
- **`[13.28]`** — A human-readable decline reason is extracted by checking a known list of response paths in order, falling back to a generic buyer-safe message when none is present.
- **`[13.29]`** — Every non-success response is logged in full for operator review; a successful response is not.
- **`[13.30]`** — **A decline stops the funnel.** Specifically: An order-declined funnel event is recorded; The reason is shown to the buyer; The cart is left completely intact; The buyer's contact details are preserved so the form re-fills for the retry; The buyer stays on checkout.
- **`[13.31]`** — A thrown error during placement is treated the same as a decline, with the generic message.
- **`[13.32]`** — In order: Capture running totals; Clear the cart in both working state and memory; Drop the promotion; Store the placed orders; Reset the completion guard flag; Hand the client the "order placed" signal that drives delayed session teardown (`[4.18]`); Build the upsell queue; Route to the upsell flow if the queue is non-empty.
- **`[13.34]`** — **Payment method is hardcoded** to card on every event and payload.
- **`[13.35]`** — **No tax and no shipping cost** exist anywhere in the model.
- **`[13.36]`** — Country is hardcoded, and the territory validation set is US-only.
- **`[13.37]`** — **Idempotency:** each checkout attempt carries a key that the provider (or the storefront's own guard) uses to reject a duplicate submission.
- **`[13.38]`** — The checkout submit path must be protected against replay of a stale form (cart mutated in another tab between render and submit) by re-validating the cart contents and total against what was displayed.

## 14. The payment provider adapter contract

- **`[14.1]`** — Every adapter declares: Identity → The provider category key it handles. Two products that are the same underlying system share one adapter.; Credential shape → Which keys it needs from the channel's payment-processor configuration, and how they map into the storefront's credential block; ….
- **`[14.2]`** — Adding a provider must be **one adapter plus one registry entry**.
- **`[14.3]`** — The adapter is resolved once per request from configuration and injected.
- **`[14.4]`** — An adapter that does not support a capability must **say so**, and the storefront must adapt its UI accordingly, rather than the capability failing at runtime.
- **`[14.5]`** — CRMs expose only **generic tracking slots**; none of them have named UTM fields.
- **`[14.6]`** — Empty values are omitted from the payload rather than sent blank.
- **`[14.6a]`** — **Discount scope is a declared adapter property, not a fixed rule.** `[13.16]` ("attach the code to the first line only") is correct for providers that apply discounts per line item — it prevents double-application.
- **`[14.6b]`** — Where a provider owns its own promotion objects, the storefront's validate and calculate steps (`[13.8]`) delegate to them rather than computing a discount the provider will then recompute differently.
- **`[14.6c]`** — Adapters may declare **routing hints** — provider-side identifiers that decide which campaign, account, or descriptor an order is placed under.
- **`[14.7]`** — When a provider has fewer slots than the canonical key set, the adapter must **report which keys were dropped**, at least to the operator log.
- **`[14.8]`** — **Attribution slot map** (20 generic slots): Slot 1 ← affiliate identifier; Slots 2–14 ← custom sub-identifiers 1–13; Slots 15–19 ← the five UTM parameters; Slot 20 ← custom sub-identifier 14; Custom sub-identifiers 15–19 have no slot and are dropped.
- **`[14.10]`** — Decline is determined by an explicit set of terminal status codes; any other status, or no status at all, counts as placed.
- **`[14.12]`** — Card scheme is derived from the card number prefix and submitted as a provider-specific type code.
- **`[14.13]`** — Orders are placed against a channel-level campaign, with campaign forcing and offer restriction enabled.
- **`[14.22]`** — **The outcome contract must have three states, not two:** placed, declined, and **pending-action** (3-D Secure / SCA challenge).

## 15. Payment credential handling and reuse

- **`[15.1]`** — The card is exchanged for a token at collection time.
- **`[15.3]`** — This is the strategy that meaningfully **reduces PCI scope**, because the storefront never handles or stores the primary account number.
- **`[15.4b]`** — Therefore an adapter declaring tokenization **must also declare its collection surface**, and the checkout page must render that surface rather than its own card fields.
- **`[15.4c]`** — **Consequence for the checkout page: the payment section is provider-dependent and cannot be a fixed block of markup.** Under raw carry-forward it renders the theme's own card fields; under tokenization it hosts the provider's fields.
- **`[15.5]`** — When the provider offers **no tokenization** — which is the case for Vrio — the raw card details must be held for the duration of the journey and **re-submitted with every upsell order.**
- **`[15.7]`** — **This is what places the deployment in full PCI scope.** It is a deliberate, disclosed trade-off, not an oversight, and it must be disclosed in operator documentation for any deployment using such a provider.
- **`[15.8]`** — Constraints when this strategy is active: The credential is held for the minimum possible window; It is never written to logs; It is wiped on journey teardown.
- **`[15.9]`** — Some providers support charging against a **prior order reference**: the upsell submits the earlier order's identifier and the provider retrieves the stored instrument from its own vault.
- **`[15.10]`** — **Where a provider supports this, it is preferred over raw carry-forward**, because it removes the need to hold the card at all.
- **`[15.11]`** — Whether a given provider supports this is **determined at adapter-implementation time**, by checking that provider's capability — it is not assumed from the provider family.
- **`[15.12]`** — When this strategy is active, journey state holds only the **reference order identifier**, never the card.
- **`[15.13]`** — The upsell flow asks the adapter for a **payment credential handle** for the current journey and submits whatever it gets back.
- **`[15.14]`** — The storefront holds raw card data **only** when the active adapter declares raw carry-forward, and only for the journey's duration.
- **`[15.15]`** — The deployment's PCI posture is therefore a **function of the configured provider**, and must be reported by the configuration validator so an operator knows which scope they are in before going live.

## 16. Post-purchase upsell flow

- **`[16.1]`** — After a successful checkout, the queue is built from the upsell layer: any upsell whose "offer after" list contains a slug that was just purchased qualifies.
- **`[16.2]`** — The queue is **deduplicated** (an upsell triggered by two purchased products appears once) and ordered by configuration order, not by purchase order.
- **`[16.3]`** — Exactly **one offer is presented per step.** The visitor accepts or declines, and the queue advances.
- **`[16.4]`** — An entry in the queue that no longer resolves to a configured upsell is skipped silently and the queue advances.
- **`[16.5]`** — When the queue empties, the visitor goes to the receipt.
- **`[16.6]`** — Arriving at the upsell step with an empty queue redirects to the receipt.
- **`[16.7]`** — An **offered** event is recorded when the step renders; **accepted** or **declined** when the visitor acts.
- **`[16.8]`** — Accepting places a **separate single-line order** on the same campaign, reusing the buyer's contact details and the payment credential handle per §15.
- **`[16.9]`** — Declining advances with no charge.
- **`[16.10]`** — A successful upsell charge **folds into the running totals** so the final order event reflects everything bought.
- **`[16.11]`** — Unlike checkout, **a declined upsell charge does not stop the flow.** It is recorded as declined and the queue advances.
- **`[16.12]`** — Every upsell order — placed or declined — is recorded locally and appended to the journey's placed-order list with its declined status.
- **`[16.16]`** — The upsell step is reachable directly by URL at any time; it defends itself only by checking whether a queue exists.
- **`[16.17]`** — Upsell definitions must be validated with the same rigour as the catalog: every referenced product exists, every provider identifier is present and in the campaign, and every "offer after" slug resolves.

## 17. Completion and teardown

- **`[17.1]`** — Reaching the receipt page triggers completion, **exactly once per checkout**.
- **`[17.2]`** — **Treatment sync** reports every provider order from this journey — the checkout order plus every accepted upsell — to the EMR in a **single batched call.**
- **`[17.3]`** — Treatments are **not** created per-order at placement time.
- **`[17.4]`** — UTM source is forwarded with the sync because it has no guaranteed provider slot (`[5.18]`), along with the visitor's user agent.
- **`[17.5]`** — A **final funnel event** is recorded: order-placed when at least one order succeeded, order-declined when none did.
- **`[17.6]`** — The opportunity reference is read **before** the journey state is wiped.
- **`[17.7]`** — Then the journey is torn down per `[4.15]`–`[4.19]`.
- **`[17.8]`** — **This is the most significant correctness gap in the current system.** Because treatment sync only happens at the receipt page, a buyer who places their order, accepts one or more upsells, and then **closes the browser before reaching the receipt** leaves those charges recorded at the provider and locally, but **never reported to the EMR.** The clinical side never learns the treatment was purchas…

## 18. Event taxonomy

- **`[18.1]`** — The storefront emits a defined event stream.
- **`[18.2]`** — The first funnel event of a journey **opens** the funnel record; every subsequent one **updates** it.
- **`[18.3]`** — **Event recording must never break the storefront.** Every emission is failure-tolerant and logged on failure.
- **`[18.4]`** — **The local audit log is dead.** A durable append-only event table exists and is wired into the dependency graph, but **nothing ever writes to it.** Either implement it as the local forensic trail it was intended to be, or remove it.

## 19. Persistence model

- **`[19.2]`** — **Orders:** one record per provider order, carrying the session link, the provider order reference, the EMR treatment reference, the anchor product slug, the amount in minor units, a status, and a timestamp.
- **`[19.8]`** — **Journey state must become durable and server-side**, keyed by the analytics session.
- **`[19.9]`** — Order records must carry a real status reflecting the placement outcome, and the treatment reference must be written back when the sync succeeds — which is what makes reconciliation possible.

## 20. Failure behaviour and degradation

- **`[20.1]`** — The governing principle: **tracking and analytics must never break the storefront; payment and eligibility must stop the buyer when they fail.**
- **`[20.2]`** — These failures are logged and swallowed, and the visitor sees no error: analytics session creation, cart mirroring, every funnel event, form definition fetch (renders an explicit unavailable state), form prefill, progressive save, opportunity create and update, treatment sync, promotion validate and calculate, and media download during sync.
- **`[20.3]`** — Payment decline, geo block, and an unknown product must all stop forward progress with a clear reason.
- **`[20.6]`** — **Full provider responses are logged on any non-success**, and those responses echo the submitted order — including buyer contact and shipping details.
- **`[20.8]`** — Synthetic identifiers are forbidden.
- **`[20.9]`** — Every external call is instrumented with outcome and latency: EMR session, cart mirror, form definition fetch, form save, opportunity write, provider placement, promotion validate and calculate, treatment sync.
- **`[20.10]`** — **Alert on the swallowed failures specifically.** These break nothing visible and are therefore the ones that go unnoticed for days.
- **`[20.11]`** — **Funnel-shape alerting is the primary signal, not infrastructure health.** Alert on step-to-step conversion deviating from its own baseline.
- **`[20.12]`** — Explicitly monitored counts, each of which represents money or clinical risk: orders placed at the provider but not recorded locally; orders recorded locally but never synced to the EMR (`[17.8]`); journeys reaching payment without an EMR session; decline rate by reason; reconciliation backlog and repeated reconciliation failures (`[21.8]`).
- **`[20.13]`** — A correlation identifier ties storefront logs, the EMR record, and the provider order together.
- **`[20.14]`** — Logs and traces are subject to the redaction rule `[20.6]`.
- **`[20.15]`** — A health endpoint reports: configuration validity, EMR reachability, provider reachability, database writability, form-definition cache state, and whether URLs are running in the degraded fallback mode.

## 21. Abandonment and resume

- **`[21.2]`** — The parameter is shape-validated, and the referenced session must exist.
- **`[21.3]`** — The resume link is **authoritative** over the browser's current session, because it comes from a message the storefront itself sent.
- **`[21.4]`** — **Security constraint:** a resume link grants access to a stranger's intake answers, which are health information.
- **`[21.5]`** — Restoring a session must restore the whole journey, not just the identity: cart lines and quantities, shipping territory, all answered form data across both forms, the chosen variant per Rx line, first-touch attribution and landing referrer, the opportunity link, the assigned flow and step variants (§22), and the current funnel position.
- **`[21.7]`** — On resume, the visitor lands at their **furthest completed step**, not at the beginning.
- **`[21.8]`** — **A server-side reconciliation job closes `[17.8]`.** It periodically finds locally recorded orders that carry no EMR treatment reference and syncs them.
- **`[21.9a]`** — **Reconciliation must run in both directions.** `[21.8]` covers orders recorded locally but missing from the EMR.
- **`[21.9b]`** — This is only possible because the analytics session identifier is submitted with every order (`[13.22]`).
- **`[21.10]`** — The storefront emits a distinguishable abandonment state at each of these points, so downstream marketing and clinical systems can act on them: Cart abandoned → Cart populated, then no activity for a configured interval; Lead abandoned → Name and email captured, form never completed; Intake abandoned → Intake started or partially completed, never submitted; Checkout abandoned → Checkout visited, no placement attempted; Payment abandoned → Placement attempted and declined, never retried; ….
- **`[21.11]`** — Each abandonment state carries the resume link and the furthest completed step, so a retargeting message can drop the visitor back exactly where they left off.
- **`[21.11a]`** — **The consuming channel is email automation.** The storefront's job is to expose the abandonment state and a working resume link; the sending is done by the marketing automation platform.
- **`[21.11b]`** — Whether a visitor may be emailed at all is governed by the consent captured under §26.
- **`[21.12]`** — Abandonment detection is **server-side and time-based**, not dependent on the browser firing an unload event.
- **`[21.13]`** — Return-visit deduplication (`[4.12]`–`[4.14]`) must be preserved: a resumed visitor must not re-fire initiated events for steps already recorded.

## 22. Multi-flow and experimentation

- **`[22.1]`** — A **flow** is a named, ordered sequence of steps with declared entry conditions.
- **`[22.11]`** — Entering a step whose preconditions are unsatisfied **redirects to the earliest unsatisfied step of the assigned flow.** This closes `[8.6]` structurally rather than by adding ad-hoc guards to each page.
- **`[22.13]`** — Identity verification is a **capability the theme provides; its placement is a store-owner decision.** The theme ships the step; the client decides where it sits.
- **`[22.14]`** — Four supported placements, all of which occur in practice: Within intake; After checkout; On the receipt page; Emailed link, completed later.
- **`[22.15]`** — This is exactly why §22 exists.
- **`[22.16]`** — Each placement declares whether it is **blocking or non-blocking.** Blocking prevents advancement; non-blocking records the outcome and lets the journey continue.
- **`[22.17]`** — **Pre-payment placement is the one that costs nothing when it fails.** Every other placement converts a failure into a held order, a refund, or a chase.
- **`[22.19]`** — Asynchronous placement means verification can complete **long after the journey ended and the session was torn down.** It therefore needs the same durable, session-independent inbound handling as clinical review outcomes (`[29.5]`, `[29.9]`) — including authentication on the inbound result.
- **`[22.20]`** — Verification outcome and timestamp are recorded on the order regardless of placement, because it is a compliance artefact rather than a funnel convenience.
- **`[22.21]`** — Identity documents are **health-adjacent personal data** and fall under §30: retention period, deletion handling, and access audit all apply.

## 23. URL structure and routing contract

- **`[23.6]`** — Every page emits a `canonical` link pointing at its canonical URL, so a link built by hand in the non-canonical form does not fragment indexing.
- **`[23.7]`** — **One rule, one place.** URL generation throughout the templates and redirects must go through a single path-building helper that emits the canonical form.
- **`[23.9]`** — **Fallback:** where rewriting is genuinely unavailable — a restricted shared host with no rewrite module — the storefront must still function, falling back to an extension-bearing or query-routed form.
- **`[23.10]`** — The fallback must never be selected silently.

## 24. SEO, structured data, and answer-engine visibility

- **`[24.2]`** — The indexing flag is enforced **twice** — as a response header and as a meta tag — from a single config value.
- **`[24.4]`** — All of the following are **generated from configuration and catalog data**, with no per-client code: Page title → Title template + page context; Meta description → Per-page override, else product description, else config default; Canonical link → The canonical URL (`[23.6]`); Social sharing card → Title, description, product image, site name; Structured data → Catalog + organisation config (§24.3); Sitemap → Every indexable route + every catalog product; ….
- **`[24.5]`** — Every generated value is **overridable per page and per product** in the override layer (§2.1), so a client who wants bespoke copy has a supported path that survives a re-sync.
- **`[24.6]`** — Funnel steps — cart, intake, checkout, upsell, receipt — are **excluded from indexing and from the sitemap** by default.
- **`[24.7]`** — The indexing-discouragement flag remains a **single master switch** that overrides everything, so a staging deployment cannot leak into an index by misconfiguration of an individual page.
- **`[24.8]`** — Emitted automatically from catalog and config: organisation identity, the storefront as a site with a search action, each product with its name, image, description, brand, and offer, and breadcrumb trails.
- **`[24.9]`** — Offer data must reflect **real state**: the actual price from the resolved variant, the real currency, and genuine availability.
- **`[24.10]`** — **Regulated-product caution:** prescription products carry claims and availability constraints that vary by territory.
- **`[24.11]`** — Structured data is validated as part of config validation (§2.5), so a malformed emission is caught at deploy time rather than in a search console weeks later.
- **`[24.13]`** — The storefront publishes a machine-readable description of itself and its crawlable surface, and declares its policy for AI crawlers as **configuration** — because whether a client wants their catalog ingested by answer engines is a commercial decision, not a technical default.
- **`[24.14]`** — Product content must be present in the **server-rendered HTML**, not injected by script.

## 25. Form presentation standards

- **`[25.4]`** — **Validation errors are presented inline, adjacent to the offending field**, and are programmatically associated with it so assistive technology announces them.
- **`[25.6]`** — Error messages state **what is wrong and how to fix it**, not that something is invalid.
- **`[25.7]`** — **Field organisation:** related fields group under a legend; the column-span hints already carried in form definitions drive layout; every input has a real associated label — never placeholder-as-label, which disappears on focus exactly when it is needed.
- **`[25.8]`** — Required fields are marked visually **and** programmatically, and the marking convention is stated once so it is consistent across pre-qualification, intake, and checkout.
- **`[25.9]`** — Layout is responsive by default and single-column on narrow viewports.
- **`[25.10]`** — Progress through a multi-step form is always visible: which step, how many remain.
- **`[25.11]`** — Interactive targets meet minimum touch-target sizing.
- **`[25.14]`** — Additionally required, and not implied by the rules above: a logical focus order through every step; step transitions announced to assistive technology, since a client-side step change is invisible to a screen reader without one; no information conveyed by colour alone; and text and controls that survive zoom and reflow.

## 26. Consent and legal copy

- **`[26.1]`** — The theme ships **default consent copy that is usable as-is.** A client who customises nothing still gets legally-shaped, industry-standard wording rather than a blank or a placeholder.
- **`[26.2]`** — Consent is collected as **explicit affirmative action** — an unchecked control the visitor must check.
- **`[26.3]`** — The consents that must be individually representable: Terms, privacy, and telehealth consent; Marketing communications; Transactional messaging.
- **`[26.4]`** — **Marketing consent and transactional messaging consent are separate.** Bundling them is the compliance mistake this rule exists to prevent — a visitor who declines marketing must still receive their shipping notification.
- **`[26.5]`** — Blocking consent must be granted before the order can be placed.
- **`[26.6]`** — Consent capture records **what was consented to, the exact copy version shown, and when.** A consent record that cannot reproduce the wording the visitor saw is not evidence of anything.
- **`[26.7]`** — Copy is **versioned.** Changing it produces a new version; existing records keep pointing at the version that was actually displayed.
- **`[26.8]`** — Every consent control is **overridable per deployment** — wording, links, and which consents appear — because jurisdiction and client counsel differ.
- **`[26.9]`** — Document links (terms, privacy, telehealth consent) are configuration.
- **`[26.10]`** — **This resolves `[5.20]` and `[11.12]`.** The hardcoded always-true consent flags are removed.
- **`[26.11]`** — Consent may be collected in the intake form (as authored fields) **or** at checkout.

## 27. Checkout order bumps

- **`[27.1]`** — An **order bump** is an offer presented **on the checkout page**, accepted before the order is submitted.
- **`[27.5]`** — Each bump declares: the product and variant offered, its display copy, its position among other bumps, and optionally a **bump-specific price** that differs from the product's catalog price — the discount is the entire persuasive mechanism.
- **`[27.6]`** — Bumps are **deduplicated** when two cart products trigger the same one, and ordered by configured position.
- **`[27.7]`** — A bump offering something already in the cart is **suppressed**, not shown and ignored.
- **`[27.8]`** — A configured maximum number of bumps renders on one checkout page.
- **`[27.9]`** — Bump definitions are validated with the same rigour as the catalog (`[16.17]`): the product exists, provider identifiers are present and in the campaign, and the trigger slugs resolve.
- **`[27.10]`** — Toggling a bump updates the order summary and total **without leaving the checkout page** and without losing anything already typed into the form.
- **`[27.11]`** — Accepting a bump adds it to the cart through the **normal cart rules** (§7): its own supply attachments come along, and the geo gate applies.
- **`[27.12]`** — A bump price override applies to that line only and does not alter the catalog.
- **`[27.13]`** — Bump acceptance and decline emit events (§18) so bump conversion is measurable separately from post-purchase upsell conversion.
- **`[27.14]`** — Bumps re-evaluate when the cart changes.
- **`[27.15]`** — A bump is subject to the same geo, promotion, and discount rules as any other line.

## 28. Form rendering modes

- **`[28.3]`** — **The mode is a delivery choice, not a behaviour change.** Conditional logic (§10.4), termination rules (§10.10), field mapping (§11), progressive save (`[10.23]`), and the event taxonomy (§18) mean the same thing in all three modes.
- **`[28.16]`** — The definition API states which definition it served, but **the storefront does not implement version negotiation or answer-key migration.** Rationale: clinicians supply the question set per medication in advance, the business owner configures it in the EMR before live traffic runs, and questions change during testing but are very rarely edited once live.

## 29. Clinical review and the fulfilment lifecycle

- **`[29.3]`** — A modified prescription would in principle create a price discrepancy: the buyer was charged for what they selected, the clinician authorised something else.
- **`[29.5]`** — Review outcomes arrive **asynchronously, hours or days later**, through a notification from the EMR.
- **`[29.9]`** — Inbound state notifications are **authenticated**: an endpoint that can move an order's fulfilment state must not accept an unauthenticated caller.
- **`[29.23]`** — Minimum defences: rate limiting on placement and on promotion validation (the latter also being a code-enumeration surface), velocity limits per card, per address, and per network origin, duplicate-order detection within a short window, and bot mitigation on the early-capture and checkout endpoints.
- **`[29.26]`** — The buyer is told plainly that they are not eligible, that they will not receive the product, and **that a refund is coming and how it will reach them.** A disqualification screen that does not mention the money reads as a scam to someone who has just paid.

## 30. Data retention, deletion, and audit

- **`[30.2]`** — **Resolution: the data is not one category.** The storefront must distinguish and handle differently: Marketing data; Clinical record; Transaction record; Operational data.
- **`[30.4]`** — **The storefront is not the authority on clinical retention.** It forwards the request to the EMR and reports what the EMR decided.
- **`[30.5]`** — Every category carries an explicit retention period in configuration, because the correct period varies by jurisdiction and the theme ships to multiple markets.
- **`[30.6]`** — Operational data expiry is **enforced by a job, not by intent.** Session records, event logs, and debug logs that outlive their period are deleted automatically.
- **`[30.7]`** — **Access to health information is audited**: who or what read it, when, and why.
- **`[30.8]`** — The local audit trail (`[18.4]`, currently dead) is the natural home for this and becomes a compliance requirement rather than a debugging convenience — which changes its priority from "nice to have" to "must exist".
- **`[30.9]`** — Deletion must reach **every** copy, including the ones easy to forget: cached form answers, working state, debug logs, monitoring traces (`[20.14]`), and anything already handed to an email automation platform (`[21.11a]`).
