# Luminous Labs — Senior PHP take-home

One Laravel 13 application covering three client tickets: the Fenwick Retail payment webhook (Ticket A), Northgate shipment dates (Ticket B) and Marlow upcoming events (Ticket C). Decisions, assumptions and trade-offs are in [DECISIONS.md](DECISIONS.md), the triage log is in [PROGRESS.md](PROGRESS.md) and the client updates are in [STATUS_REPORT.md](STATUS_REPORT.md).

## Reviewer quick start (≤ 5 minutes)

[![Review](https://github.com/shuvo7670/luminous-labs-assignments/actions/workflows/review.yml/badge.svg)](https://github.com/shuvo7670/luminous-labs-assignments/actions/workflows/review.yml)

One command installs the app, builds a fresh SQLite database, runs the full test suite and demonstrates every ticket. You don't need npm, a web server or a database server.

### Requirements

- PHP 8.3 or newer
- Composer 2
- The `pdo_sqlite` PHP extension

Check all three at once:

```bash
php -v && composer -V && php -m | grep -i sqlite
```

You should see PHP 8.3 or newer, Composer 2.x, and `pdo_sqlite` in the extension list.

### Run it

```bash
git clone https://github.com/shuvo7670/luminous-labs-assignments.git && cd luminous-labs-assignments && composer review
```

`composer review` runs these steps in order and stops at the first one that fails, with a non-zero exit code:

1. `composer install --no-interaction`
2. Copy `.env.example` to `.env` if `.env` is missing
3. `php artisan key:generate`
4. Create `database/database.sqlite` if it is missing
5. `php artisan migrate:fresh --seed --force`
6. `php artisan test` (76 tests)
7. `php artisan review:demo`

### Expected output

The test step ends with `Tests: 76 passed`. Then `review:demo` prints one line per check. Output from the commands it runs, the simulated audit mismatch and the ordered event list appear between these lines and are trimmed here:

```text
Every demo write runs inside a database transaction that is rolled back at the end.

Ticket A: Fenwick payment webhook
  PASS  First signed delivery of pay_fenwick_0001 returns 200 (got 200 {"status":"created"})
  PASS  Retried delivery returns 200 (got 200 {"status":"duplicate"})
  PASS  Exactly 1 order exists for pay_fenwick_0001 (got 1)
  PASS  Delivery with a bad signature returns 401 (got 401)
  PASS  webhooks:check-failures exits 0 (got 0)

Ticket B: Northgate shipment dates
  PASS  shipments:import exits 0 (got 0)
  PASS  UK 07/03/2026 is saved as 2026-03-07 (got 2026-03-07)
  PASS  US 07/03/2026 is saved as 2026-07-03 (got 2026-07-03)
  PASS  Dry-run shipments:audit-dates exits 0 (got 0)
  PASS  Dry-run audit reports shipment [NG-US-2001] should be 2026-07-03
  PASS  Dry run leaves the stored date unchanged (got 2026-03-07)

Ticket C: Marlow upcoming events
  PASS  GET /api/events/upcoming returns 200 (got 200)
  PASS  Returns between 1 and 10 events (got 10)
  PASS  Events are ordered by starts_at, then id
  PASS  Each event exposes only id, name, starts_at, venue, description
  PASS  Every returned event is published and starts after now

All checks passed
```

**How long it takes:** under a minute. From a fresh clone it took 38.9 s with an empty Composer cache (everything downloaded) and 7.9 s with a warm one. A slow network mainly affects the `composer install` step.

CI runs the same command on PHP 8.3 and 8.4 for every push and pull request. A second CI job runs the migrations and the full test suite on MySQL 8.4 and PostgreSQL 17 ([.github/workflows/review.yml](.github/workflows/review.yml)).

### Troubleshooting

- **`pdo_sqlite` is missing, or the migrate step fails with `could not find driver`.** Enable the SQLite extension, then run `composer review` again. On Debian or Ubuntu, install `php8.3-sqlite3` (match your PHP version). Homebrew PHP on macOS already includes it. On Windows, uncomment `extension=pdo_sqlite` in `php.ini`.
- **`composer install` reports a PHP platform requirement.** The dependencies are locked for PHP 8.3 or newer, so upgrade PHP.
- **The webhook secret is a local demo value.** `.env.example` sets `PAYMENT_PROVIDER_WEBHOOK_SECRET=change-me-before-serving-webhooks`. This value is public, so replace it before the app receives real webhooks. `review:demo` signs with whatever value is configured. If you change it, use the same value in the curl examples below. If it is empty, every webhook gets `401` and the Ticket A checks show `FAIL`.
- **Alert emails go to the log file locally.** `.env.example` sets `PAYMENT_WEBHOOK_ALERT_EMAIL=operations@example.com` and `MAIL_MAILER=log`, so alert emails are written to `storage/logs/laravel.log` instead of being sent.
- **Rerunning is safe.** Run `composer review` as often as you like. Each run rebuilds the local SQLite database with `migrate:fresh --seed`, so anything you added to `database/database.sqlite` is lost, and it writes a new `APP_KEY` to `.env`. `review:demo` rolls back everything it writes. Nothing outside the project directory is touched.

## Manual checks per ticket

These optional checks use the database seeded by `composer review`. Start the app in one terminal:

```bash
php artisan serve
```

Run the commands below from the project root in a second terminal.

### Ticket A — Fenwick payment webhook

Send the sample event with a valid signature:

```bash
payload_file=docs/sample-payment-succeeded.json
timestamp=$(date +%s)
signature=$( { printf '%s.' "$timestamp"; cat "$payload_file"; } | openssl dgst -sha256 -hmac 'change-me-before-serving-webhooks' | awk '{print $NF}')
curl -i http://127.0.0.1:8000/webhooks/payment-provider \
  -H 'Content-Type: application/json' \
  -H "Payment-Signature: t=${timestamp},v1=${signature}" \
  --data-binary "@${payload_file}"
```

The first request returns `200 {"status":"created"}`. Sending it again returns `200 {"status":"duplicate"}`, and there is still exactly one order. Change any character of `v1`, or reuse a timestamp more than 300 seconds old, and the response is `401 {"message":"Invalid signature."}`. Nothing is stored for a `401`.

Now send a correctly signed event that is missing its payment data, then run the failure check:

```bash
bad_payload='{"id":"evt_manual_failure","type":"payment.succeeded"}'
timestamp=$(date +%s)
signature=$(printf '%s.%s' "$timestamp" "$bad_payload" | openssl dgst -sha256 -hmac 'change-me-before-serving-webhooks' | awk '{print $NF}')
curl -i http://127.0.0.1:8000/webhooks/payment-provider \
  -H 'Content-Type: application/json' \
  -H "Payment-Signature: t=${timestamp},v1=${signature}" \
  --data-binary "$bad_payload"
php artisan webhooks:check-failures
```

The request returns `422` so the provider would retry it. The check lists the failure, prints `Alert emailed to operations@example.com.` and exits non-zero. The email is written to `storage/logs/laravel.log`, and a second run within the hour does not email again.

How the endpoint works:

- **Signature header.** `Payment-Signature: t=<unix timestamp>,v1=<hex HMAC-SHA256>`. The HMAC covers `<timestamp>.<exact raw request body>` and uses `PAYMENT_PROVIDER_WEBHOOK_SECRET`. It is compared with `hash_equals`, and the timestamp must be within 300 seconds of the current time.
- **Order creation.** A `payment.succeeded` event creates an order keyed by a unique `(provider, provider_payment_id)` index, so retries and concurrent deliveries end with one order.
- **Other event types.** Authenticated events of any other type are acknowledged with `200 {"status":"ignored"}`.
- **Failures.** An invalid payload returns `422` and an unexpected error returns `500`. Either way the failure is saved in `failed_webhooks` (repeats of the same event increment `attempts`) and logged at error level, and the non-2xx response makes the provider retry. When a later delivery of that event succeeds, the failure is marked resolved. `webhooks:check-failures` runs every five minutes (`php artisan schedule:list`) and emails the alert address at most once an hour while failures remain.

### Ticket B — Northgate shipment dates

Each regional office has exactly one accepted date format. A value that does not format back to the exact input is rejected, which catches values like `31/02/2026` and `2026-3-7`.

| Office          | Format       | `07/03/2026` means |
| --------------- | ------------ | ------------------ |
| `northgate-uk`  | `DD/MM/YYYY` | 7 March 2026       |
| `northgate-us`  | `MM/DD/YYYY` | 3 July 2026        |
| `northgate-iso` | `YYYY-MM-DD` | (rejected)         |

```bash
php artisan shipments:import docs/sample-shipments.csv        # all-or-nothing import; keeps the raw date
php artisan shipments:audit-dates                             # dry run: re-parses raw dates, changes nothing
php artisan shipments:audit-dates --batch=batch-us-42         # limit the audit to one source batch
php artisan shipments:audit-dates --apply                     # correct dates proven by the raw value
```

- **Import.** The import checks the header row (`external_id`, `regional_office`, `shipment_date`, optional `source_batch`) and the column count of every row. It runs in one transaction, so a single bad row imports nothing and the error names the row. Rows are matched by `external_id`, so re-importing a file is safe.
- **Audit.** After a clean import the audit reports `0 mismatched`. `review:demo` shows it catching a simulated historical misread. Rows with no raw value, or a raw value that cannot be parsed, are reported as `Needs source-file review`. They are never changed, and the command exits non-zero.

### Ticket C — Marlow upcoming events

```bash
curl -s http://127.0.0.1:8000/api/events/upcoming
```

The endpoint returns up to 10 events that are `published` and start strictly after now, ordered by `starts_at` and then `id`. Each event exposes only public fields:

```json
{
  "data": [
    {
      "id": 10,
      "name": "ipsum maxime est",
      "starts_at": "2026-09-15T21:00:00+00:00",
      "venue": "Gradytown Hall",
      "description": "Aut dolorum itaque sapiente reiciendis repellat."
    }
  ]
}
```

The endpoint is intentionally unauthenticated. The application has no auth system and the ticket does not define one. Marlow must confirm that public access is acceptable before release (see [DECISIONS.md](DECISIONS.md)).

## Tests

```bash
php artisan test
```

The 76 tests run against in-memory SQLite locally, and against MySQL and PostgreSQL in CI. Any test that depends on time freezes it with `travelTo`, and signed requests are built the same way the provider signs them. Coverage is weighted by risk:

- **Ticket A (Fenwick): 35 tests.** Money and security.
- **Ticket B (Northgate): 33 tests.** Data integrity, mostly one-line parser value cases.
- **Ticket C (Marlow): 4 tests.** A read-only list.

| Ticket | Test | What it covers |
| ------ | ---- | -------------- |
| A | [VerifyPaymentProviderSignatureTest](tests/Feature/Http/Middleware/VerifyPaymentProviderSignatureTest.php) | Valid signature over non-canonical raw JSON; the 300 s boundary; wrong secret, re-encoded body, tampered, missing, malformed, stale and future signatures; an unconfigured secret. Every rejection is `401` and stores nothing. |
| A | [PaymentWebhookControllerTest](tests/Feature/Http/Controllers/PaymentWebhookControllerTest.php) | Order creation (UTC `paid_at`, upper-case currency); the same event retried; a different event for the same payment; **a delivery that loses a concurrent insert race** (fails without the unique key); unknown event types; eight malformed payloads (`422` plus a failure row); failures without an event ID kept separate; an unexpected exception (`500`, failure row, error log); repeated failures incrementing `attempts`; recovery resolving the failure, including on a duplicate. |
| A | [CheckFailedWebhooksTest](tests/Feature/Console/Commands/CheckFailedWebhooksTest.php) | Unresolved failures listed and emailed with a non-zero exit; at most one email per hour; a warning when no alert address is set; success and exit 0 when all are resolved. |
| B | [ShipmentDateParserTest](tests/Unit/Services/ShipmentDateParserTest.php) | `07/03/2026` parsed differently per office at UTC midnight; impossible, unpadded, wrong-office, two-digit-year, time-suffixed, padded and empty values rejected; unknown offices. |
| B | [ImportShipmentsTest](tests/Feature/Console/Commands/ImportShipmentsTest.php) | Per-office import keeping the raw date; update by `external_id`; optional `source_batch`; UTF-8 BOM; invalid rows rolling back earlier rows; invalid headers; missing file. |
| B | [AuditShipmentDatesTest](tests/Feature/Console/Commands/AuditShipmentDatesTest.php) | Dry run reports without changing; `--apply` corrects; `--batch` limits scope; missing or unparseable raw values are flagged, left unchanged and exit non-zero. |
| C | [UpcomingEventControllerTest](tests/Feature/Http/Controllers/UpcomingEventControllerTest.php) | Past, starting-now, draft and cancelled events excluded; ordering with a tie; limit of 10; exact JSON with only public fields. |
| — | [ReviewDemoTest](tests/Feature/Console/Commands/ReviewDemoTest.php) | `review:demo` passes and leaves no data behind; a failing check exits non-zero. |

Two things are not covered by automated tests:

- **Parallel processes.** The concurrent-insert race is reproduced deterministically inside one process, not with multiple processes.
- **Real delivery.** Scheduler failures and alert emails reaching a real inbox or alert channel depend on infrastructure outside this application.
