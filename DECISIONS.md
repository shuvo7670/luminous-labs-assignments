# Decisions

## 1. Missing specification and assumptions

### Ticket A — Fenwick Retail payment webhook

The ticket did not name the payment provider or define its payload, signature scheme, retry behaviour or where alerts should go. I made these assumptions:

- **Payload shape.** The event is Stripe-shaped: a top-level event `id` and `type`, with the payment in `data.object` (`id`, `amount`, `currency`, `customer_email`, `paid_at`). See [docs/sample-payment-succeeded.json](docs/sample-payment-succeeded.json).
- **Idempotency key.** The payment `id` is stable across retries and across different events for the same payment, so it is the idempotency key. Orders are unique on `(provider, provider_payment_id)`, and the provider name is a single config value (`payment-provider`).
- **Amount.** `amount` is a JSON integer in minor units (`4999` = 49.99) and is never negative. Numeric strings and decimals are rejected, and no floating-point money is stored.
- **Currency.** `currency` is a three-letter code, stored upper-case.
- **Payment time.** `paid_at` is ISO-8601 with `Z` or a numeric offset and optional milliseconds. It is converted to UTC before storage.
- **Customer email.** `customer_email` is always present. The ticket did not list it, but the `orders` column is not nullable, so a missing email is a clear `422` instead of a database error.
- **Signature.** `Payment-Signature: t=<unix timestamp>,v1=<hex HMAC-SHA256>` over `<timestamp>.<raw body>` with one shared secret, and a 300-second tolerance in both directions.
- **Retries.** The provider retries any non-2xx response, which drives these responses:
  - A duplicate payment returns `200`, because the desired state (one order) already exists.
  - An authenticated event of an unknown type returns `200` and is ignored.
  - A malformed authenticated payload returns `422` and an unexpected error returns `500`. Both are recorded in `failed_webhooks` so a later successful retry can resolve them. A permanently malformed payload therefore keeps being retried until the provider gives up, and the `attempts` counter makes that visible.
- **A later event for the same payment** is treated as a duplicate even if its amount differs. The first event wins.
- **Rejected signatures.** Invalid or stale signatures return `401` and are never stored. Storing them would let anyone on the internet write rows to the database. For the same reason the route sits outside the `web` middleware group, so a rejected request does not create a session row either.
- **Alerting.** Production runs Laravel's scheduler, and scheduler failures and error-level logs reach the team's existing operations alert channel. The application provides the signal: `webhooks:check-failures` exits non-zero every five minutes while failures are unresolved, and every failure is logged at error level. It does not deliver the alert.

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

The ticket did not define "upcoming", visibility rules, timezone, response fields, tie ordering, result size or the intended audience. I made these assumptions:

- **Upcoming.** An event is upcoming if `starts_at` is strictly after the current instant. An event starting exactly now is excluded.
- **Visibility.** Only `published` events are visible. `draft` and `cancelled` events are hidden.
- **Timezone.** Times are stored in UTC (the application timezone) and returned as ISO-8601 with an offset. Clients convert to local time.
- **Ordering and size.** Events are ordered by `starts_at` and then `id`, so ties are deterministic. The endpoint returns only the next ten, with no pagination.
- **Fields.** Only `id`, `name`, `starts_at`, `venue` and `description` are public. `status` and the timestamps stay internal.
- **Least confident: public access.** The endpoint has no authentication because the application has no auth system and the ticket did not ask for one, so I did not invent one. The ticket does not say whether Marlow's event data is meant for the public internet. This assumption needs client confirmation before release. If public access is not acceptable, authentication must be added before the endpoint is deployed. The same note is in [routes/api.php](routes/api.php).

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
- **Tests.** They cover the same event retried and a different event for the same payment. The truly concurrent case is enforced by the unique key. It is not simulated, because a same-connection simulation inside the test transaction's savepoint would roll back the competing row.

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

1. **Database writes on the request path.** With the default SQLite database, writes queue behind one file lock, and `database is locked` errors turn into `500`s long before 100×. Production needs MySQL or PostgreSQL; the schema only uses portable schema-builder types, but it has only been run on SQLite. Even on a server database, every delivery costs a transaction. When database latency rises, requests exceed the provider's timeout and the provider retries, which multiplies the load. The unique key keeps orders correct during that retry storm, but it does nothing for throughput.
2. **PHP worker exhaustion.** Synchronous processing means traffic spikes queue in PHP-FPM, and the webhook shares that worker pool with every other route.
3. **Failure-path amplification.** During a bad deploy or database incident every delivery fails. Each failure writes a row containing the full payload plus an error log line, and each provider retry repeats the write, adding the most load exactly when the system is weakest.
4. **Our own monitor.** `webhooks:check-failures` loads every unresolved row, including payloads, and prints them all every five minutes. `failed_webhooks` has no index on `resolved_at`, and resolved rows are never pruned. During a failure storm the check becomes slow, memory-hungry and noisy.

Alerts that would catch this before customers report missing orders:

- p95 and p99 latency of `POST /webhooks/payment-provider`, alerting well below the provider's timeout.
- Rate of `5xx` and `422` responses on that route, and a separate alert on a spike in `401`s (a rotated or misconfigured secret, or an attack).
- The provider's own delivery-failure and retry rate, from its dashboard or failure notifications.
- Count of unresolved `failed_webhooks` and the age of the oldest one. The scheduled command already exits non-zero; the same numbers should also be exported as metrics.
- Orders created per minute compared with `payment.succeeded` deliveries received per minute; a growing gap means lost orders.
- Database connection-pool saturation, lock waits and deadlocks, plus PHP-FPM active workers and listen-queue length.
- A scheduler heartbeat. If `schedule:run` stops, `webhooks:check-failures` can never fail, so the monitor itself needs a dead man's switch.

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
- **No alert integration.** There is no Slack or PagerDuty code and no scheduler heartbeat; the application provides a non-zero exit code and error logs for the existing operations pipeline.

### Ticket B

- **No heuristic or multi-format date parsing.** An ambiguous value like `07/03/2026` is resolved only by the row's office.
- **No automatic repair of rows without a raw value.** Those rows need the original import file or another authoritative source.
- **No rejection of duplicate `external_id` rows within one file.** The last row wins.
- **No stored correction history.** `--apply` prints each change with the old and new date, but does not keep the previous value in the database. Operators should keep the dry-run output.
- **No check of the stored office.** The audit trusts `regional_office`; a row saved with the wrong office cannot be detected from the data.

### Ticket C

- **No authentication or roles.** The endpoint also has no pagination, filtering, caching, timezone conversion or event administration API.
- **No PHP enum for event status.** Statuses are constants on the `Event` model, because a new `app/Enums` folder needs approval under the project rules.

### Tooling and scope

- **One application.** A single small Laravel app covers all three tickets instead of three separate applications, which keeps review to one command while the tickets stay in separate services, commands and tests.
- **No frontend or npm build.** The skeleton's Vite files are left untouched.
- **`composer review` wipes the local database by design.** It runs `migrate:fresh --seed` and writes a new `APP_KEY`; `review:demo` itself rolls back its writes.
- **Composer platform pinned to PHP 8.3.** This locks Symfony 7.4 so CI can run on both 8.3 and 8.4. Removing the pin when 8.3 support is dropped allows Symfony 8.
