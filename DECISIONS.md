# Decisions

## 1. Missing specification and assumptions

### Ticket A — Fenwick Retail payment webhook

The ticket did not name the payment provider or define its payload, signature scheme, retry behaviour, database or where alerts should go. I made these assumptions:

- **Payload shape.** The event is Stripe-shaped: a top-level event `id` and `type`, with the payment in `data.object` (`id`, `amount`, `currency`, `customer_email`, `paid_at`). See [docs/sample-payment-succeeded.json](docs/sample-payment-succeeded.json).
- **Idempotency key.** The payment `id` is stable across retries and across different events for the same payment, so it is the idempotency key. Orders are unique on `(provider, provider_payment_id)`, and the provider name is a single config value (`payment-provider`).
- **Amount.** `amount` is a JSON integer in minor units (`4999` = 49.99) and is never negative. Numeric strings and decimals are rejected, and no floating-point money is stored.
- **Currency.** `currency` is a three-letter code, stored upper-case.
- **Payment time.** `paid_at` is ISO-8601 with `Z` or a numeric offset and optional milliseconds. It is converted to UTC before storage.
- **Customer email.** `customer_email` is always present. The ticket did not list it, but the `orders` column is not nullable, so a missing email is a clear `422` instead of a database error.
- **Signature.** `Payment-Signature: t=<unix timestamp>,v1=<hex HMAC-SHA256>` over `<timestamp>.<raw body>` with one shared secret, and a 300-second tolerance in both directions.
- **Retries.** The provider retries any non-2xx response or timeout, which drives these responses:
  - A duplicate payment returns `200`, because the desired state (one order) already exists.
  - An authenticated event of an unknown type returns `200` and is ignored.
  - A malformed authenticated payload returns `422` and an unexpected error returns `500`. Both are recorded in `failed_webhooks` so a later successful retry can resolve them. A permanently malformed payload therefore keeps being retried until the provider gives up, and the `attempts` counter makes that visible.
- **A later event for the same payment** is treated as a duplicate even if its amount differs. The first event wins.
- **Rejected signatures.** Invalid or stale signatures return `401` and are never stored. Storing them would let anyone on the internet write rows to the database. For the same reason the route sits outside the `web` middleware group, so a rejected request does not create a session row either.
- **Database.** The brief allows PostgreSQL or MySQL. The schema uses only portable schema-builder types, and CI runs the migrations and the full test suite on MySQL 8.4 and PostgreSQL 17. SQLite is the default only so a reviewer can run everything in minutes without a database server.
- **Who sees failures.** Production runs Laravel's scheduler. Every five minutes, `webhooks:check-failures` exits non-zero while failures are unresolved and emails a summary to `PAYMENT_WEBHOOK_ALERT_EMAIL`, at most once an hour, so a person is told instead of the failure waiting in a table. Which address receives it, or whether Fenwick prefers Slack, is the client's decision.

### Ticket B — Northgate Logistics shipment dates

The ticket did not give the office identifiers or their date formats, the CSV layout, which historical rows are affected, whether the original import files still exist, or who may change historical records. I made these assumptions:

- **Office formats.** Each office exports exactly one format: `northgate-uk` is `d/m/Y`, `northgate-us` is `m/d/Y`, `northgate-iso` is `Y-m-d`. Office codes are exact and case-sensitive. Northgate must confirm the real values.
- **No format guessing.** Every CSV row identifies its office, and the parser never infers a format from the value. `07/03/2026` is 7 March for UK and 3 July for US, and nothing in the value itself can tell them apart.
- **Calendar dates.** Shipment dates have no time component. They are parsed as UTC midnight and stored as `Y-m-d`.
- **CSV layout.** The header row is `external_id`, `regional_office`, `shipment_date` and an optional `source_batch`. Unknown or duplicate columns are errors.
- **All-or-nothing import.** One bad row rejects the whole file, because partial imports are how silent corruption spreads. Operators fix the file and re-run it; matching rows by `external_id` makes re-running safe.
- **Historical repair.**
  - The stored `regional_office` on existing rows is correct.
  - The retained raw value is the only trustworthy evidence of the intended date. Rows imported before this fix have no `shipment_date_raw`, so their dates cannot be proven and they are reported as needing source-file review, never guessed.
  - `shipments:audit-dates` is a dry run by default. Passing `--apply` is the explicit authority to change historical data.

### Ticket C — Marlow Events upcoming events

The ticket did not define "upcoming", visibility rules, timezone, response fields, tie ordering or the intended audience. I made these assumptions:

- **Upcoming.** An event is upcoming if `starts_at` is strictly after the current instant. An event starting exactly now is excluded.
- **Visibility.** Only `published` events are visible. `draft` and `cancelled` events are hidden.
- **Timezone.** Times are stored in UTC (the application timezone) and returned as ISO-8601 with an offset. Clients convert to local time.
- **Ordering and size.** Events are ordered by `starts_at` and then `id`, so ties are deterministic. The endpoint returns only the next ten, with no pagination.
- **Fields.** Only `id`, `name`, `starts_at`, `venue` and `description` are public. `status` and the timestamps stay internal.
- **Least confident across all three tickets: public access.** The endpoint has no authentication because the application has no auth system and the ticket did not ask for one, so I did not invent one. The ticket does not say whether Marlow's event data is meant for the public internet, and Marlow's developer was not available. This assumption needs client confirmation before release. If public access is not acceptable, authentication must be added before the endpoint is deployed (see the plan in section 4). The same note is in [routes/api.php](routes/api.php).

## 2. AI use on Ticket A and where I overrode it

I used an AI assistant on Ticket A to draft the controller and service split, list failure-path tests and review the signature contract. I overrode two of its suggestions.

### Check-then-insert race replaced by a database unique key and `firstOrCreate()`

The first draft made idempotency an application check:

```php
if (! Order::where('provider_payment_id', $paymentId)->exists()) {
    Order::create($attributes);
}
```

Two deliveries of the same payment can arrive together; this is normal when the provider retries after a timeout while the first request is still running. Both can see no row, and both insert, creating a duplicate order. The check and the insert are separate statements, so no application code in between closes that gap.

What I built instead:

- **Unique key.** The [orders migration](database/migrations/2026_09_12_104754_create_orders_table.php) adds `$table->unique(['provider', 'provider_payment_id'])`. The database is the idempotency boundary.
- **`firstOrCreate()`.** [`PaymentWebhookProcessor::handlePaymentSucceeded()`](app/Services/PaymentWebhookProcessor.php) calls `Order::firstOrCreate()` on those two columns. In Laravel 13, `firstOrCreate()` falls back to `createOrFirst()`, which runs the insert inside a savepoint. If the unique key rejects a concurrent duplicate, it returns the row that won. Every delivery ends with the same single order, and `wasRecentlyCreated` decides whether the response says `created` or `duplicate`.
- **Failure rows.** Failures use the same pattern: `failed_webhooks.provider_event_id` is unique and found with `firstOrCreate()`, and `attempts` is changed with an atomic `increment()` instead of read-modify-write.
- **Proof.** `test_delivery_that_loses_a_concurrent_insert_race_returns_the_existing_order` in [PaymentWebhookControllerTest](tests/Feature/Http/Controllers/PaymentWebhookControllerTest.php) reproduces the race deterministically. It inserts a competing order after the request's lookup has found nothing, but before the request's own insert. The request must still return `200 duplicate` with exactly one order.
  - I checked the test against both bugs on a scratch copy. It fails when the unique key is removed from the migration, and it fails when `firstOrCreate()` is replaced with the AI's `exists()`-then-`create()` code.
  - The ordinary retry tests pass in both broken versions, because sequential retries never race. That is why this test exists.

### Raw request bytes plus a signed timestamp, instead of decoded JSON

The first draft verified the HMAC against the decoded JSON payload, re-encoded for comparison, and checked nothing else. That fails in two ways:

- **Genuine deliveries get rejected.** Re-encoding changes the bytes (whitespace, key order, escaped slashes and Unicode, number formatting), and the obvious "fix" is to loosen verification.
- **Replay.** A valid HMAC on its own does not expire, so a captured request could be replayed forever.

What I built instead, in [`VerifyPaymentProviderSignature`](app/Http/Middleware/VerifyPaymentProviderSignature.php):

- **Raw bytes.** The signed string is `<t>.` followed by `$request->getContent()`, the exact raw request body.
- **Constant-time comparison.** Signatures are compared with `hash_equals()`.
- **Timestamp.** `t` must be all digits and within `payment-provider.signature_tolerance` (300 seconds) of the current time, in either direction.
- **Empty secret.** An empty secret rejects every request and logs an error, because an HMAC with an empty key can be forged by anyone.
- **Tests.**
  - The request body is deliberately non-canonical JSON.
  - A signature computed over the compact, re-encoded body is rejected.
  - Signatures 301 seconds old or 301 seconds in the future are rejected, and exactly 300 seconds is accepted.

A replay inside the 300-second window is still accepted, and that is harmless because order creation is idempotent.

## 3. What breaks first at 100× Fenwick volume

Every webhook is processed synchronously inside the HTTP request. The request verifies the HMAC, decodes and validates the payload, selects the order by `(provider, provider_payment_id)`, inserts it inside a savepoint, updates `failed_webhooks` for that event and responds. Each delivery holds a PHP worker and a database connection for that whole time.

What breaks first, in order:

1. **Database writes on the request path.** Every delivery costs a transaction. When database latency rises, requests exceed the provider's timeout and the provider retries, which multiplies the load. The unique key keeps orders correct during that retry storm, but it does nothing for throughput. The SQLite default used for review would fail much earlier, because it has a single write lock; production uses MySQL or PostgreSQL.
2. **PHP worker exhaustion.** Synchronous processing means traffic spikes queue in PHP-FPM, and the webhook shares that worker pool with every other route.
3. **Failure-path amplification.** During a bad deploy or database incident every delivery fails. Each failure writes a row containing the full payload plus an error log line, and each provider retry repeats the write, adding the most load exactly when the system is weakest.
4. **Our own monitor.** `webhooks:check-failures` loads every unresolved row, including payloads, every five minutes. `failed_webhooks` has no index on `resolved_at`, and resolved rows are never pruned. The alert email is throttled to one an hour, but during a failure storm the check itself becomes slow and memory-hungry.

Alerts that would catch this before customers report missing orders:

- p95 and p99 latency of `POST /webhooks/payment-provider`, alerting well below the provider's timeout.
- Rate of `5xx` and `422` responses on that route, and a separate alert on a spike in `401`s (a rotated or misconfigured secret, or an attack).
- The provider's own delivery-failure and retry rate, from its dashboard or failure notifications.
- Unresolved `failed_webhooks` and the age of the oldest one. These already produce an hourly email and a non-zero exit; they should also be exported as metrics with thresholds.
- Orders created per minute compared with `payment.succeeded` deliveries received per minute; a growing gap means lost orders.
- Database connection-pool saturation, lock waits and deadlocks, plus PHP-FPM active workers and listen-queue length.
- A scheduler heartbeat. If `schedule:run` stops, `webhooks:check-failures` never runs and never emails, so the monitor itself needs a dead man's switch.

The next design step:

- Verify the signature, insert the raw event into an inbox table keyed by a unique `provider_event_id`, and return `2xx` immediately.
- Process events with idempotent queue workers that rely on the same unique keys, and alert on queue depth and the age of the oldest job.
- Index `resolved_at`, limit and paginate the failure check, and prune resolved failures.
- Load test to set real alert thresholds before the traffic arrives.

## 4. What I deliberately did not build

### Ticket A

- **No queue or webhook inbox.** Synchronous processing is simpler to review and correct at the stated volume. The unique keys make moving to queued processing a contained change.
- **No record of successful deliveries.** Only failures are stored, and an order keeps the event ID of its first delivery.
- **No other event types.** Refunds, failed payments and disputes are acknowledged and ignored.
- **No reconciliation.** A later event for an existing payment with a different amount or currency is treated as a duplicate and not flagged.
- **No secret rotation.** There is no support for multiple `v1` signatures during a rotation; there is one shared secret.
- **No manual retry.** There is no replay UI or retry command for `failed_webhooks`. Recovery relies on provider retries, and resolved failures are not pruned.
- **Email is the only alert channel.** There is no Slack or PagerDuty integration and no scheduler heartbeat, until Fenwick says where alerts should go.

### Ticket B

- **No heuristic or multi-format date parsing.** An ambiguous value like `07/03/2026` is resolved only by the row's office.
- **No automatic repair of rows without a raw value.** Those rows need the original import file or another authoritative source.
- **No rejection of duplicate `external_id` rows within one file.** The last row wins.
- **No stored correction history.** `--apply` prints each change with the old and new date, but does not keep the previous value in the database. Operators should keep the dry-run output.
- **No check of the stored office.** The audit trusts `regional_office`; a row saved with the wrong office cannot be detected from the data.

### Ticket C

- **No authentication or roles, and no invented auth.** The endpoint also has no pagination, filtering, caching, timezone conversion or event administration API.
- **If Marlow asks for auth tomorrow.** I would first ask who calls the endpoint.
  - **Marlow's own website or a partner server:** per-client API keys. That means an `api_clients` table storing hashed keys, middleware that checks `Authorization: Bearer <key>`, Artisan commands to issue and revoke keys, rate limiting, and tests for missing, invalid and revoked keys. About half a day including tests and deployment.
  - **Individual people signing in:** Laravel Sanctum with user accounts, which also needs a login flow. About one to two days.
- **No PHP enum for event status.** Statuses are constants on the `Event` model, because a new `app/Enums` folder needs approval under the project rules.

### Testing depth, deliberately different per ticket

- **Ticket A — heaviest (35 tests).** Money and security are at stake. The tests cover every signature failure mode, retries, the concurrent-insert race, eight malformed payloads, failure recording, recovery and alert emails.
- **Ticket B — 33 tests.** Silent data corruption is the risk, but most of these are one-line parser value cases (valid, ambiguous and invalid dates) plus the import's all-or-nothing behaviour and the audit's never-guess rule.
- **Ticket C — 4 tests.** It is a read-only list. The tests cover only what would leak or mislead: filtering, ordering, the limit and exact public fields.

### Tooling and scope

- **One application.** A single small Laravel app covers all three tickets instead of three separate applications, which keeps review to one command while the tickets stay in separate services, commands and tests.
- **No frontend or npm build.** The skeleton's Vite files are left untouched.
- **`composer review` wipes the local database by design.** It runs `migrate:fresh --seed` and writes a new `APP_KEY`; `review:demo` itself rolls back its writes.
- **Composer platform pinned to PHP 8.3.** This locks Symfony 7.4 so CI can run on both 8.3 and 8.4. Removing the pin when 8.3 support is dropped allows Symfony 8.
