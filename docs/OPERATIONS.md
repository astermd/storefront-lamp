# Operations

This document is for whoever runs this storefront: the person who owns the
crontab, reads the failure mail and answers when a number looks wrong.

**There is no scheduler in this application.** Nothing here runs on its own.
Every job below is a command you invoke, and until something in your
deployment — cron, a systemd timer, a container scheduler — invokes it, it does
not run. That is deliberate: a storefront that schedules its own jobs has two
sources of truth about when they ran. It also means that the four jobs in the
first section are, today, jobs nobody is running.

Commands are shown as `bin/console <name>`, run from the application root.
All output in this document was produced by running the command shown.

A few sentences cite behaviour rules like `[21.9a]`. Those ids are resolved in
[`SPEC-REFERENCE.md`](SPEC-REFERENCE.md); you can read this document without
them.

---

## 1. The scheduled jobs

Four commands are written to be run on a schedule. Three of them are
**dry-run by default** — they report and change nothing until you add
`--apply`. The fourth has no `--apply` at all.

| job | needs `--apply`? | suggested cadence | what it costs to skip |
|---|---|---|---|
| `emr:reconcile` | yes, to act | hourly | a paid order with no clinical record |
| `provider:reconcile` | **no such flag** | daily | a charge nothing here knows about |
| `abandonment:signals` | no (read-only) | hourly | recovery email nobody sends |
| `db:prune` | yes, to act | daily | data kept past its stated retention |

### 1.1 `emr:reconcile` — orders the EMR was never told about

**What it does.** Walks the local `orders` table for rows whose treatment sync
never completed, and — under `--apply` — tells the EMR about them. It is the
forward sweep: it starts from a charge this storefront recorded and asks
whether the clinical system heard about it.

**What it protects against.** The window between taking a buyer's money and
creating their clinical record. If the EMR call fails at checkout, the buyer
has paid and no clinician has anything to act on. Nothing else in the
application retries that.

**Real output:**

```
$ bin/console emr:reconcile
Orders the EMR was never told about: 0
  reconciliation backlog: 0
  unreconcilable (no analytics session): 0
  examined this run: 0, synced: 0, failed: 0, skipped: 0

(dry run — use --apply to sync)
```

Exit code: **0**.

The counts, in order:

- **Orders the EMR was never told about** — every unsynced row, at any age.
  Includes orders placed in the last few seconds, which is normal.
- **reconciliation backlog** — unsynced orders placed more than **15 minutes**
  ago (the grace window). These are past the point where the checkout path
  might still finish the job itself. **This is the figure to alert on.**
- **unreconcilable (no analytics session)** — orders with no session uuid. The
  EMR keys a treatment on that uuid and it is forbidden to invent one, so these
  can never be synced by any number of retries. Each one needs a person. A
  non-zero value that does not go down is the expected shape.
- **examined / synced / failed / skipped** — what this particular run did. In
  a dry run all four read as work not done.

**Cadence: hourly.** The grace window keeps it off orders the checkout path has
not finished with, and the EMR deduplicates a sync per session, so overlapping
runs cannot create a second clinical record. Hourly means a transient EMR
outage is retried within the hour, and the 24-hour repeat-failure threshold
(reported by `ops:status`) has real meaning: an order still unsynced after a
day has failed roughly twenty-four times, not once.

**Exit codes.**

- **0** — the backlog was measured. Printed for an empty backlog, a full one,
  and one full of orders the EMR refused this run.
- **1** — the `orders` table could not be read; nothing is printed at all.

An order the EMR keeps refusing does **not** fail the run, and that is
deliberate: re-running changes nothing about it, so a non-zero exit would page
somebody on every run until a human acted.

**When it fails.** Exit 1 means the database, not the EMR. The failure line
names the exception class and, for a driver error, its SQLSTATE — never the
driver's message, because this command's stdout ends up in a mail spool and the
message can quote row contents back. Check the database this job reads, then
re-run; the sweep is idempotent.

### 1.2 `provider:reconcile` — charges the storefront never recorded

**What it does.** The reverse sweep. It asks the payment provider what orders
it took in a recent window and looks for a local row for each one. It starts
from the money and works back to this application, which is the opposite
direction to `emr:reconcile` and answers a different question.

**What it protects against.** A charge that happened with nothing local to show
for it — the storefront crashed between the provider accepting the card and the
order row being written. `emr:reconcile` cannot see these, because it starts
from a local row that does not exist.

**Real output:**

```
$ bin/console provider:reconcile
Orders placed at the provider but not recorded locally: 0
  examined this run: 0 of 0, matched: 0
  unmatched but never charged: 0
  unmatched sandbox orders: 0
```

Exit code: **0**.

- **Orders placed at the provider but not recorded locally** — the headline.
  Each one is a charge nothing on this side knows about. When it is non-zero
  the command also prints the provider's own order references, one per line,
  because looking each up in the provider's dashboard is the only recovery
  there is.
- **examined this run: N of M** — how many orders were read against how many
  the provider says the window holds. When these differ, the run was truncated
  and the headline is a floor, not a total. See §4.4.
- **unmatched but never charged** — orders with no local row whose card was
  never charged. Counted apart because they are not lost money; see
  [`INTEGRATION-NOTES.md`](INTEGRATION-NOTES.md) on the provider's null status.
- **unmatched sandbox orders** — test-flagged orders. In a sandbox account
  every order is one of these, so folding them into the headline would make the
  monitored figure permanently wrong.

**There is no `--apply`, and there deliberately never will be.** Every other
outbound command in this application is dry-run with `--apply` to act; this one
has no second behaviour for a flag to select. An unmatched provider order
cannot be turned into a local order row, because the search projection the
provider returns carries **no analytics session, no order lines, no buyer and
no order total**. The only thing `--apply` could do is fabricate a record of a
charge — invent the amount, invent the cart, invent the buyer — and a
fabricated order record is worse than a missing one, because it looks
authoritative. A flag that appeared to offer recovery this provider cannot give
would be worse still. The recovery path is a person reading the printed
references in the provider's own dashboard.

**Cadence: daily.** The default window is 48 hours, so a daily run overlaps the
previous one by a full day and a missed night costs nothing. Do not run it less
often than the window: consecutive windows must meet end to end or orders fall
between them.

**Exit codes.**

- **0** — this run compared **every** order in its window against a local row.
  Printed for a clean window and for a window full of orphans alike. Also
  printed, with an explanatory line, when the configured provider has no order
  search at all — that is a deployment fact, not an outage.
- **1** — the number above is a floor rather than a total. Four causes, and the
  message says which: the provider was not answered; a local row could not be
  read; the window held more orders than one run reads (truncation); or the
  requested window was unusable (`--hours` not a number, or shorter than the
  sweep's own 15-minute grace period).

**When it fails.** Read the message, not just the code. A provider outage
clears itself on the next run — the 48-hour window covers the run that failed.
Truncation does not clear itself and needs the remedy in §4.4. An unreadable
local row prints `reconcile.local_read_failed` log lines naming each order.

### 1.3 `abandonment:signals` — the abandoned-journey feed

**What it does.** Prints one JSON document on stdout describing every journey
that has stopped moving for long enough to count as abandoned. It writes
nothing, anywhere. Running it twice produces the same document; each signal
carries a `signal_key` so the consumer deduplicates across polls without this
command remembering anything.

A journey counts as abandoned after **one hour** of no movement, and the sweep
looks back **30 days** (both configurable in `config/abandonment.php`).

**What it protects against.** Nothing, directly — this is a revenue feed, not a
safety net. It exists so the marketing platform can send a "you left something
behind" email. The storefront exposes the state; the platform does the sending.

**Real output** (session identifiers redacted — see the warning below):

```
$ bin/console abandonment:signals
{
    "count": 15,
    "generated_at": "2026-08-30T20:06:14+00:00",
    "signals": [
        {
            "signal_key": "<session-uuid>:cart_abandoned:2026-08-20T17:05:56+00:00",
            "state": "cart_abandoned",
            "session_uuid": "<session-uuid>",
            "opportunity_id": null,
            "furthest_step": null,
            "resume_url": "http://127.0.0.1:8080/?amd_session=<session-uuid>",
            "marketing_consent": "not_asked",
            "last_moved_at": "2026-08-20T17:05:56+00:00",
            "idle_seconds": 874818
        }
    ]
}
```

Exit code: **0**.

`count` and `generated_at` wrap the list on purpose: an unwrapped array cannot
express the difference between "nobody abandoned anything" and "the poll never
ran".

> ### This command's output is health information. Treat it as such.
>
> Every signal carries a `resume_url`, and **in this deployment a resume link
> never expires and is not revoked when the journey completes.** The link is
> the raw analytics session identifier: `?amd_session=<uuid>`. Anyone holding
> that URL holds indefinite access to that person's intake answers — the
> medical questionnaire they filled in.
>
> **This is a deliberate design decision, not an oversight, and it is not to be
> "fixed" into revoking.** What follows from it is an operational obligation:
> the output of this command belongs in the marketing automation platform and
> **nowhere else.**
>
> Not a CI artefact. Not a shell history. Not a log file. Not a cron mail
> spool. Not a monitoring system's captured stdout. Every one of those outlives
> the journey, and the link does not expire to catch up with them.
>
> Pipe it directly into the importer. Do not tee it. Do not redirect it to a
> file "just to check". If you must inspect it by hand, redact
> `resume_url` and `session_uuid` before you paste it anywhere — as this
> document does.
>
> The compensating control is the audit trail: every use of a resume link and
> every sweep that serialises a journey into a signal is recorded in the
> `events` table as an access to health information. With no expiry, that log
> is the only record that a link was used.

**Cadence: hourly**, matching the one-hour idle threshold. More often only
produces the same document; much less often and a recovery email arrives too
late to recover anything.

**Exit codes.**

- **0** — a document was produced and it is the whole current signal set.
  `count: 0` is a measurement and is printed on stdout as usual.
- **1** — the sweep could not read `sessions`. **stdout is then empty**, so a
  pipeline that consumes only stdout imports nothing rather than importing an
  all-clear. The failure line goes to **stderr** — this is the one command in
  the set that does that, because its stdout is a machine-readable document and
  an error line mixed into it would make the JSON parser fail on the reporting
  rather than on the outage.

**When it fails.** It is one query, so there is no partial state: it either
read its window or it did not. Check the database. Nothing was sent, and
nothing needs undoing.

**A note on `furthest_step`.** It may read `null` on every signal. On a
database whose session rows predate the field, that is expected rather than a
defect — the journey state was serialised before the key existed. Judge it
against a journey started after the current deployment, not against old rows.

### 1.4 `db:prune` — retention, actually enforced

**What it does.** Deletes operational data past its retention period, from five
places:

| what | period comes from | default |
|---|---|---|
| `checkout_attempts` | `--attempt-days` | 30 days |
| `rate_limits` | `--rate-limit-hours` | 24 hours |
| `sessions` | `retention.operational.session_days` | 90 days |
| `events` | `retention.operational.event_days` | 365 days |
| files under `storage/logs/` | `retention.operational.log_days` | 30 days |

The first two are command options because they are mechanical — how long an
idempotency key or a rate-limit counter is still useful to the software. The
last three come from `config/retention.php` and **cannot** be overridden from
the command line: how long a person's journey is kept is a policy decision that
belongs somewhere reviewable, not in a crontab flag.

**What it protects against.** Two things at once. Unbounded growth in tables
nothing has ever deleted from, and — more importantly — holding personal and
health-adjacent data past the period you have told people you hold it for. Data
that is merely *supposed* to expire does not expire.

**Real output:**

```
$ bin/console db:prune
checkout_attempts older than 30d — would delete: 0
rate_limits with windows closed over 24h ago — would delete: 0
sessions last moved before 2026-06-01T20:04:50+00:00 — would delete: 0
events older than 2025-08-30T20:04:50+00:00 — would delete: 0
storage/logs files last written before 2026-07-31T20:04:50+00:00 — would delete: 0

(dry run — use --apply to delete)
```

Exit code: **0**.

**Every cutoff is named in the output.** That is the point of the format: you
can read back the exact instant each sweep compared against and check it
against the period you think you configured, without trusting a count.

**A period set to zero disables that expiry** and the command says so in words
rather than reporting a silent `0` that could equally mean "nothing was old
enough":

```
sessions — expiry disabled (retention.operational.session_days is 0)
```

Zero never means "delete everything". That is never what an operator meant by
setting a period to nothing, and the mistake would be unrecoverable.

#### What it refuses to delete

This is the interesting half of the command.

- **A session an `orders` row references is never deleted, at any age.** The
  configured period is a floor, not an override. A deleted session row is a
  journey that cannot be resumed and an order that cannot be reconciled, and
  nothing can put it back. Sessions held back this way are counted and reported
  separately, so the difference between the session count and the period is
  explained rather than left looking like a sweep that missed:

  ```
  Sessions past their period kept because an order references them: 12
  ```

- **No configured period may delete inside the reconciliation window.** Both
  reconciliation sweeps read backwards over recent activity, and a session
  deleted inside that window is a charge whose journey no longer exists to be
  matched to. The floor is **172,800 seconds — two days** — the widest of the
  two sweeps' windows. A session or event period *shorter* than two days is
  held back to two days; a longer one is honoured exactly as written. The log
  sweep is deliberately **not** held back: neither sweep reads a log file, and
  the wire log is the one artefact here that can hold verbatim card and
  identity payloads, so a deployment that wants it gone in a day gets it gone
  in a day.

- **An unresolved checkout attempt is never deleted, at any age.** These are
  requests that reached the payment provider and were never answered. Money may
  have moved with nothing local confirming it. Age proves the request is not
  coming back; it proves nothing about whether the card was charged. They are
  counted and named instead:

  ```
  Unresolved checkout attempts older than 30d (kept, and each needs a person): 3
    Look each one up by the `checkout.attempt_unresolved` log line that recorded it.
  ```

  Each is also a cart its buyer cannot retry. Both halves need a person.

- **`orders`, `order_lines` and `order_consents` are never touched, at any
  age.** Transaction records are retained for financial obligations. The period
  is declared in `config/retention.php` (7 years by default) so it is stated
  rather than implied by the absence of a job, but nothing in this application
  acts on it — archival is your procedure, not the software's.

**Cadence: daily**, with `--apply`. Every retention here is measured in hours
at the very least, so a missed run costs nothing but a slightly larger table.

**Exit codes.**

- **0** — every sweep this run attempted completed: a dry run that counted, or
  an `--apply` that deleted.
- **1** — one of them did not. Two shapes: a retention option that is not a
  whole number of at least 1 (in which case **nothing at all** is touched), or
  a repository or filesystem call that threw.

The strictness on options is the point of having a floor. `--attempt-days
thirty` is not silently cast to `0`; a typo in a crontab must not be able to
spell "delete everything" and then be forgiven with a zero exit.

**When it fails.** Read the whole failure block, not just the first line. **The
sweeps are not one transaction**, and they run in the order printed. Under
`--apply`, a failure in the log sweep means every database delete has already
happened. The command therefore names what it deleted before it stopped:

```
Prune failed: <exception class> (<SQLSTATE>)
checkout_attempts older than 30d — deleted: 41
rate_limits with windows closed over 24h ago — deleted: 900
(this run stopped after that, so re-running it resumes rather than repeats)
```

Re-running is safe and resumes rather than repeats.

---

## 2. `ops:status` — the monitored counts in one place

Every figure that represents money or clinical risk, reported by one command.
It exists so that a monitoring system has a single thing to call and so that
`ops:status` and `emr:reconcile` can never disagree about the same question —
the shared figures are read from the same repository, not re-derived.

**Real output:**

```
$ bin/console ops:status
Orders placed at the provider but not recorded locally: not checked
  (--provider asks the provider; it makes an outbound call)

Orders recorded locally but never synced to the EMR: 0
  reconciliation backlog: 0
  repeatedly failing: 0
  no session, so no retry can ever drain them: 0

Journeys reaching payment without an EMR session: 0 of 1 checkouts

Declines: 0 of 0 charge attempts
```

Exit code: **0**.

`--json` emits the same figures as one JSON document, each carrying its own
measured/unavailable state, plus a top-level `complete` flag.

### What each count means

**Orders placed at the provider but not recorded locally.** The reverse sweep's
headline, and the only figure requiring an outbound call — which is why it is
behind **`--provider`** and reads `not checked` without it. A status command
that a monitoring system polls every minute should not hammer the payment
provider by default. **Any non-zero value is money that was taken with nothing
on this side to show for it.** Run `provider:reconcile` for the references and
look each one up in the provider's dashboard.

**Orders recorded locally but never synced to the EMR.** Paid orders with no
clinical record yet. Non-zero is normal for orders placed seconds ago.

- **reconciliation backlog** — the same, but older than **15 minutes**. Past
  the point the checkout path might still finish. Non-zero means run
  `emr:reconcile --apply` and find out why the automatic path did not.
- **repeatedly failing** — older than **24 hours**. At an hourly cadence this
  is roughly two dozen failed attempts. Something is systematically wrong: a
  revoked permission, a rejected payload, an EMR outage nobody noticed.
  **Non-zero here should page somebody.**
- **no session, so no retry can ever drain them** — no analytics session uuid,
  so no amount of retrying will help. These do not clear on their own and are
  not a backlog; they are a permanent gap needing a person. Watch the number
  for *growth*, not for being non-zero.

**Journeys reaching payment without an EMR session: N of M checkouts.**
Reported as a ratio on purpose. A deployment with analytics switched off has no
session for any journey, so `M of M` is a configuration statement, not an
alarm. What matters is **divergence**: sessions are configured, and some
proportion of buyers reach payment without one. Every such order is a future
entry in the "no session" count above. A ratio that climbs is a bug in session
handling.

**Declines: N of M charge attempts,** measured over the last **30 days**. When
non-zero it breaks down by the provider's stated reason, with at most 20
distinct reasons before the tail is folded into `(other)` and any decline whose
reason went missing filed under `(no reason recorded)` — so the breakdown
always adds up to the total. A rising decline rate is usually a payment
configuration problem, not a run of unlucky buyers.

Both of the last two figures are read from the local `events` trail rather than
the `orders` table, and the difference matters: the EMR keeps one checkout
record per session and updates it in place, so a decline the buyer retried past
is gone from it, and no order row exists at all for a charge that never landed.
The append-only local trail is the only place either question can still be
answered.

### Exit codes

- **0** — every figure the run attempted was measured.
- **1** — at least one figure could **not** be read.

**Read exit 1 as "this report is incomplete", not as "something is wrong with
the storefront".** A figure that failed to read reports as unavailable rather
than as zero, precisely so that a monitoring system never treats a database
error as an all-clear. A figure nobody asked for is not a failed one: skipping
`--provider` leaves that count `not checked` and the run still exits 0.

---

## 3. The other commands

The full set:

```
$ bin/console list
  abandonment:signals  Emit the current set of abandoned-journey signals as JSON, for an external system to poll
  cache:clear          Wipe the storage cache directory contents
  config:validate      Validate the merged catalog structurally, optionally against the live EMR
  db:migrate           Create the database (if needed) and apply pending migrations
  db:prune             Report (and, with --apply, delete) operational data past its retention period
  emr:ping             Fetch the configured EMR channel and print a connectivity summary
  emr:reconcile        Report (and, with --apply, sync) orders the EMR was never told about
  forms:cache-purge    Remove every cached teleform definition
  forms:record         Capture a teleform metadata record and definition to disk
  media:prune          List (or, with --force, delete) orphaned files in public/assets/media
  ops:status           Report the monitored counts that represent money or clinical risk
  provider:ping        Resolve the configured payment adapter and print a connectivity summary
  provider:reconcile   Report orders the payment provider took that are not recorded locally
  theme:sync           Sync the generated catalog and channel config from the configured EMR channel
```

### `db:migrate`

Creates the database if it is missing and applies pending migrations. Run it on
every deploy, before traffic reaches the new code.

```
$ bin/console db:migrate
Nothing to migrate.
```

Exit: **0**, or **1** if a migration failed. Migrations are tracked by filename,
so an applied file is never re-run.

### `cache:clear`

Wipes the contents of the storage cache directory: compiled templates, cached
teleform definitions, and **the cached EMR bearer token**. That last one is why
this command appears in §4.2.

Exit: **0**.

### `config:validate`

Validates the merged catalog — the synced catalog plus the override layer —
structurally, and checks the deployment's configuration for things that must
not ship.

```
$ bin/console config:validate
ERROR: debug: app.debug.wire_log is ON — every provider and EMR call is being written to
storage/logs/ unredacted: card numbers, bearer tokens, and the identity-check payloads
carrying Social Security Numbers and dates of birth. Unset WIRE_LOG before this deployment
takes a real payment or runs a real identity check.
warning: product 6a8a88-nad-500mg: variants differ in price — verify prices vary by plan
length only, never by dose (spec [29.3])
warning: product 6a8a86-tesamorelin-3mg-ml-35-days: 1 variant(s) need provider identifiers
in the override layer — Tesamorelin (3mg/ml | 35 Days)
warning: payment: 3 catalog variants have no provider mapping and cannot be ordered
note: payment: PCI posture — Reduced scope via reference-order reuse: [...]
```

> **`config:validate` exits `2` on error, not `1`.**
>
> Verified: the run above returned exit code **2**. There is no `1` for a
> validation error — the command returns `0` (clean, or warnings only) or `2`
> (at least one ERROR line).
>
> **This is how it gets got wrong:**
>
> ```
> $ bin/console config:validate | tail -3
> warning: payment: 3 catalog variants have no provider mapping and cannot be ordered
> note: payment: PCI posture — [...]
> $ echo $?
> 0
> ```
>
> The `0` is `tail`'s exit code, not the validator's. Any pipeline — `| tail`,
> `| grep`, `| tee logfile` — reports the **last** command's status, and a
> deploy gate written that way passes a configuration that just told you it
> holds card numbers in a log file. Run it unpiped and check `$?`, or in bash
> use `set -o pipefail`, or capture to a variable first.

Warnings are informational; a warning does not fail the run. `note:` lines are
purely descriptive. The PCI posture line is printed on every run and is a
statement of the deployment's payment architecture, not a finding.

### `emr:ping` / `provider:ping`

Connectivity checks. These are the correct way to ask "is the integration up" —
see §4.1.

```
$ bin/console emr:ping
Channel: Demo Store
Products: 5
Provider: vrio
```

```
$ bin/console provider:ping
Provider: vrio
vrio api.vrio.app — campaign 147, 20 items, connection 1
PCI posture: Reduced scope via reference-order reuse: [...]
```

Both exited **0**. Both return **1** when the call fails or the adapter cannot
be resolved. `emr:ping` proves that credentials work, the channel resolves and
the catalog is readable; `provider:ping` proves the payment adapter is
configured and the provider answers.

### `theme:sync`

Fetches the configured EMR channel and regenerates the catalog files.

> **Dry-run by default. `--apply` overwrites this deployment's catalog.**

Both files it writes are gitignored; the committed `*.generated.example.php`
files beside them are shape references, not a catalog to run on.

Without flags it prints unified diffs of what *would* be written and touches
nothing:

```
$ bin/console theme:sync
--- a/products.generated.php
+++ b/products.generated.php
@@ -25,9 +25,9 @@
             'price_cents' => 10000,
             'price_unit' => '/ month',
-            'image' => '/assets/media/example-monthly-therapy.4afac944.jpg',
+            'image' => '/assets/img/product-placeholder.svg',
[...]
media: 6 images would be localised
warning: 2 variants lack provider identifiers
```

Exit: **0**.

`--apply` writes two files. `config/products.generated.php` is **overwritten
wholesale** — every hand edit in it is lost. Customisations belong in
`config/products.overrides.php`, which this command never touches.
`config/channel.generated.php` is merged rather than replaced: hand-added keys
the new payload does not mention survive.

**Read the dry-run diff before applying.** The diff above is a real example of
why: it shows the sync replacing localised media paths with placeholders,
because media localisation only runs under `--apply`. A diff you did not read
is a catalog change you did not review.

Exit codes for `--apply`: **0** clean, **1** if the channel could not be fetched
or the catalog could not be built, and **2** if the files were written and the
structural validator then found errors in them. Exit 2 there means the same
thing it means for `config:validate` — and carries the same piping trap.

### `media:prune`

Lists files under `public/assets/media` that no catalog entry references.

```
$ bin/console media:prune
No orphaned media.
```

Exit: **0**. Listing only; `--force` deletes. Run the listing form after a
`theme:sync --apply` that removed products.

### `forms:record` and `forms:cache-purge`

Two intake-form utilities, neither of which belongs in a schedule.

- **`forms:record --teleform=<id> --out=<dir>`** captures one teleform's
  metadata record and definition to disk. A diagnostic: it is how you get the
  EMR's actual form definition in front of a developer when a form renders
  wrongly. Exit **0**, or **1** for a missing option, an unreadable teleform or
  an unwritable output directory.
- **`forms:cache-purge`** removes every cached teleform definition, forcing the
  next request to fetch fresh ones. Run it after a form is republished in the
  EMR and the storefront is still rendering the old one. Exit **0**.

---

## 4. Operational traps

Each of these has cost somebody a day.

### 4.1 Reachability is `emr:ping` — never a curl to the token endpoint

A bare `POST /v1/auth/api-credentials/token` with no credentials returns
**HTTP 500 by design**. It is not an outage, it is not a misconfiguration, and
it says nothing whatever about whether the API is up.

If you check reachability by curling the token endpoint you will conclude the
EMR is down while it is serving traffic normally, and you will do it during an
incident when that conclusion is most expensive.

**Use `bin/console emr:ping`.** It performs a real authenticated call with the
deployment's real credentials against the real channel, which is the question
you actually meant to ask.

### 4.2 After any EMR permission change, run `cache:clear` first

The EMR **bakes a permission snapshot into the bearer token** — the token's JWT
carries a `user_info.permission_key` claim — and this storefront caches that
token in `storage/cache/emr-token.json` until its stated expiry. Measured on the
current token: `iat` to `exp` is exactly **24 hours**.

So a permission granted server-side appears to have had no effect for up to a
day. The call keeps returning 403 and every instinct says the grant did not
take.

**After any permission change:**

```
bin/console cache:clear        # or: rm storage/cache/emr-token.json
bin/console emr:ping
```

*Then* conclude something. Three separate investigations here read 403 from a
stale token before this was spotted. It also runs the other way: a permission
*revoked* server-side stays usable from this storefront until the token
expires.

### 4.3 `WIRE_LOG` must never be enabled anywhere real

`app.debug.wire_log` writes every EMR and payment-provider call to
`storage/logs/`, request and response, **verbatim and unredacted**. That is the
entire point of it — a redacted transcript cannot be replayed by hand, and
replaying the exact bytes is how a disagreement with a provider gets settled.

While it is on, those files hold, in clear text:

- primary account numbers, security codes and expiry dates;
- live bearer tokens and the OAuth client secret;
- identity-check payloads carrying Social Security Numbers and dates of birth;
- the buyer's name, address and telephone number, alongside all of it.

That is cardholder data at rest in this application's own storage, which its
payment architecture otherwise never holds. The SDK's own redactor — which
would drop the identity-verify body as a known PHI path — is **switched off
along with it**, deliberately, because redaction is what makes a transcript
unreplayable. So the wire log spills identity documents as well as cards.

It is a local debugging switch, against sandbox test cards, and **never
something a deployment taking real payments or running real identity checks may
enable.**

`config:validate` reports it as an **ERROR**, not a warning, which is why that
command's exit code is worth getting right (§3). The files land under
`storage/`, which is excluded from version control, so a transcript cannot be
committed by accident — but nothing stops it being backed up, shipped to a log
aggregator, or read by anyone with filesystem access.

If you find it was on: unset `WIRE_LOG`, and treat every file in
`storage/logs/` written while it was on as a card-data and PHI breach.
`db:prune` expires that directory on a 30-day default, and its cutoff is
deliberately **not** held back by the reconciliation floor so that a deployment
which wants those files gone in a day can set `RETENTION_LOG_DAYS=1` and get
them gone in a day.

### 4.4 The reverse sweep's order-search limit, and what truncation means

The reverse sweep reads at most **200 orders** per run
(`OrderSearch::DEFAULT_LIMIT`). Its default window is **48 hours** with a
**15-minute** grace period at the near end.

**Those numbers are close together, not comfortably apart.** The account these
values were sized against took **143 orders in 3 days** — about 95 in a
48-hour window. The limit is roughly twice the observed volume, which is
headroom and not a guarantee. **A deployment busier than that truncates**, and
the value is therefore a deployment setting in practice rather than a universal
default.

**There is no `--limit` option.** The only window control is `--hours`.

**Truncation fails the run rather than silently returning a partial answer.**
When the window holds more orders than one run reads, the command prints what
went unmeasured, prints the remedy, and exits **1** — because no exit code can
carry "mostly", and a partly blind sweep prints a number that looks exactly
like a measured one.

**Which half goes unread is not arbitrary.** Nothing in the request asks for a
sort order, so the ordering is the provider's own, and on every observed window
that is **newest first**. The limit therefore drops the **oldest** orders in
the window — the ones old enough to have genuinely failed to record, and the
only ones that age out of the lookback entirely before another run at these
settings can reach them. Truncation drops exactly the orders the sweep exists
to find.

**The remedy is to shorten the window *and* run that often**, so consecutive
windows still meet end to end:

```
# instead of one daily run over 48 hours:
0 */4 * * *   bin/console provider:reconcile --hours 6
```

Shortening the window without increasing the frequency opens a gap between
runs, which loses orders exactly as truncation does.

One interaction to know about: `--hours` can read further back than the
retention floor protects. The floor holds sessions for the *default* two-day
window; a widened `--hours` run reads past it. **Run the sweep before the
prune**, not the other way round.

### 4.5 `storage/logs/` is as sensitive as the abandonment feed

The operator log stamps the analytics session identifier on every line written
inside a journey. That is `[20.13]` and it is the point of the log: one
correlation identifier is what joins a storefront log line to the EMR record
and to the provider order, and it is what makes a "this exists at the provider
but nowhere in our own records" investigation tractable — which is the kind of
investigation the two reconcile sweeps in §1 exist to open.

The same identifier is the resume link's only secret. The link is
`?amd_session=<uuid>` — the bare session identifier, nothing else — and per
§1.3 it never expires and completing the journey does not revoke it. So a log
line naming a session is a resume link that can be reassembled by hand, and a
log file is that for every journey it mentions.

**Read access to `storage/logs/` is therefore close to resume access to the
intake answers of every journey in those files.** Not equal to it: the
identifier has to be recognised and pasted into a URL first. But close enough
that the handling rules from §1.3 apply unchanged, and for the same reason.

Where the exposure is *not*: in what the lines say. Nothing health-, identity-
or credential-shaped is written to the operator log, and the redaction pass
that ensures that is value-based rather than key-based, so it catches card data
sitting under a key whose name means nothing. The exposure is the
correlatability, not the content.

What that asks of you:

- **Log shipping is journey-data shipping.** An aggregator, a monitoring
  vendor, a support bundle, a paste into a ticket — each is a copy of resume
  material in a place with its own retention and its own access list. Decide
  that deliberately rather than by default.
- **The retention period is the mitigation, so let it run.** Files under
  `storage/logs/` expire on `retention.operational.log_days` — 30 days by
  default — and `bin/console db:prune --apply` is what enforces it (§1.4).
  A deployment that never runs the prune keeps every resume identifier it has
  ever logged, indefinitely.
- **A deletion request has to reach the logs too.** They are named in the
  procedure below for this reason, not as an afterthought.
- `storage/` is gitignored, so a log file cannot be committed by accident.
  That is the only part of this handled for you.

This is separate from `WIRE_LOG` (§4.3), which is a different and much larger
problem: that switch puts card numbers and identity documents into the same
directory verbatim. The paragraphs above are about the ordinary log, with
redaction working exactly as designed.

---

## 5. Data retention and deletion requests

`config/retention.php` is the policy, written down. It divides the data into
four categories that behave differently on purpose.

| category | what it is | on a deletion request | period |
|---|---|---|---|
| **marketing** | attribution, UTM, affiliate ids, abandonment state, consent | **delete** — no basis survives withdrawal | follows the session period |
| **operational** | sessions, events, logs | **delete** on schedule; no long-term basis | 90 / 365 / 30 days |
| **transaction** | orders, charges | **retain** for financial and tax obligations | 7 years (2557 days) |
| **clinical** | intake answers, clinician decisions, prescriptions, treatments | **retain** — the EMR is the authority | declared `null`; not this system's decision |

The periods are configuration rather than constants because the correct period
varies by jurisdiction. Override them with `RETENTION_SESSION_DAYS`,
`RETENTION_EVENT_DAYS`, `RETENTION_LOG_DAYS` and `RETENTION_ORDER_DAYS`. **The
shipped defaults are conservative, and they are not legal advice.** A
deployment is expected to set its own.

The clinical period is declared as `null` rather than as a number, deliberately.
A number there would be this storefront deciding something it is explicitly not
the authority on.

Only `db:prune` enforces any of this. A period changed in configuration and not
followed by a scheduled prune is a period that does not exist.

### The deletion-request procedure, and its limit

**State the limit first, because it is the part that gets promised wrongly:
this application cannot delete a clinical record, and there is no transport by
which it could ask the EMR to.**

The EMR SDK exposes **exactly one deletion route across its entire `Resource/`
directory**. Verified:

```
$ grep -rn "function delete" vendor/astermd/sdk/src/Resource/
vendor/astermd/sdk/src/Resource/Sessions.php:156:    public function delete(string $session): Response
```

One route, on `Sessions`. It removes an **analytics session** — a tracking
record — **not a clinical record.** There is no `Treatments::delete()`, no
`Patients::delete()`, no `IntakeSubmissions::delete()`. Nothing in this
application can reach a clinical record to remove it, and inventing a receiver
that nothing shows the EMR would call would be a worse answer than saying so.

So the procedure has two halves, and they are carried out in different systems.

**Half one — what this storefront does.** For the marketing and operational
categories it owns:

1. Identify the person's analytics session or sessions. First-touch
   attribution, derived source category, abandonment state and consent all live
   inside those rows, so they go together.
2. Remove them. `db:prune` does this by age; a targeted request is a database
   deletion against the identified rows, and `sessions()->delete()` removes the
   corresponding analytics session at the EMR.
3. **Check `storage/logs/` and any log aggregator the deployment ships to.**
   Deletion has to reach the copies that are easy to forget, and a deployment
   that ever ran with `WIRE_LOG` on has verbatim payloads on disk (§4.3).
4. Note what was **not** deleted and why: order records are retained under the
   transaction category, and clinical records are not this system's to remove.

**Half two — what the EMR does.** A deletion request touching intake answers,
clinician decisions, prescriptions or treatments is an operator procedure
**carried out in the EMR**, by whoever administers it, under whatever
clinical-records regulation applies. This storefront's part is to say so, and
to have deleted its own categories so that the copy it held is not left behind.

**Do not tell a requester that this application has deleted their clinical
data.** It has not, it cannot, and nothing here can ask the EMR to.

Two things constrain the operational half in practice:

- **A session an order references is never deleted by the scheduled sweep**, at
  any age. A targeted deletion that removes one anyway turns a recoverable
  charge into an orphan; decide that deliberately, not by running a broad
  `DELETE`.
- **The audit trail is retained longer than the sessions it describes** (365
  days against 90) because it is the record of who read health information and
  when. With resume links that never expire (§1.3), that log is the only record
  a link was used. Deleting it to satisfy a request removes the evidence of the
  exposure along with the exposure.

---

## 6. A suggested crontab

Cadences from §1, in one place. Adjust paths, and read §4.4 before assuming the
daily reverse sweep suits your volume.

```cron
# AsterMD storefront — scheduled jobs.
# Nothing in the application runs these; this file is the scheduler.

APP=/srv/storefront
MAILTO=ops@example.com

# Orders the EMR was never told about. Hourly: a failed sync is retried within
# the hour, and the 24-hour repeat-failure threshold then means ~24 attempts.
17 * * * *   cd $APP && bin/console emr:reconcile --apply

# Charges the provider took that were never recorded here. Daily; the default
# window is 48h, so this overlaps by a day. If a run reports truncation,
# switch to a shorter --hours AND a matching frequency (see §4.4).
40 3 * * *   cd $APP && bin/console provider:reconcile

# Retention. Daily, after the reverse sweep — the sweep reads sessions the
# prune may remove, so its window must be read before the prune runs.
15 4 * * *   cd $APP && bin/console db:prune --apply

# Monitored counts, for whatever scrapes them. Exit 1 means the REPORT is
# incomplete, not that the storefront is unhealthy.
*/15 * * * * cd $APP && bin/console ops:status --json > /var/lib/storefront/status.json

# Abandoned-journey feed. Hourly, matching the one-hour idle threshold.
# ┌─────────────────────────────────────────────────────────────────────────┐
# │ THIS COMMAND'S STDOUT IS HEALTH INFORMATION.                            │
# │ Every signal carries a resume link that never expires and is not         │
# │ revoked when the journey completes. Anyone holding one holds indefinite  │
# │ access to that person's intake answers.                                  │
# │                                                                          │
# │ It must be piped STRAIGHT INTO the marketing automation platform and     │
# │ nowhere else. Not a file. Not a log. Not a CI artefact. Not this         │
# │ crontab's MAILTO — set MAILTO='' on this line so a failure does not      │
# │ mail a document full of live links to a shared inbox.                    │
# └─────────────────────────────────────────────────────────────────────────┘
MAILTO=""
25 * * * *   cd $APP && bin/console abandonment:signals | /usr/local/bin/import-abandonment
MAILTO=ops@example.com
```

Two notes on that file.

**The `MAILTO` toggle around the abandonment line is not decoration.** cron
mails a job's output on failure, and on some configurations on success too. A
`MAILTO` pointing at a shared operations inbox turns the feed into a durable,
searchable archive of permanent resume links in a mailbox many people can read.
Set it empty for that line and let the importer report its own failures through
a channel you chose deliberately.

**Where the destination lives matters as much as the pipe.** If your automation
platform is reached over the network, the link is now in that platform's
storage under that platform's retention, not yours, and it never expires. That
is a decision to make once, in the open, rather than by whichever integration
happened to be easiest.
