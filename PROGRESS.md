# Progress log

Times are simulated to show one working day. The order of work follows the real commit history, and each entry names the commit it produced.

**08:45 — Setup** (`e3d4aa4`)
Created a fresh Laravel 13 application with SQLite and PHPUnit, installed Laravel Boost, and confirmed that `.env` and `*.sqlite` are gitignored. The skeleton tests pass.

**09:00 — Ticket A (Fenwick) started** (`825afea`)
Started with Fenwick because payments carry the highest risk: duplicate orders, or forged requests creating orders. Built the `orders` and `failed_webhooks` tables, with a unique key on `(provider, provider_payment_id)` from the beginning. Added `POST /webhooks/payment-provider` with HMAC-SHA256 verification over the exact raw body and a signed timestamp (300-second tolerance). Moved the route outside the `web` middleware group after finding that the database session driver would have written a row for every forged request. Invalid or stale signatures return `401` and store nothing.

**09:40 — Northgate interrupt: Ticket B** (`56f9ecb`)
Paused Fenwick. Northgate imports are still running and silently saving wrong dates, and every hour adds more corrupt rows, whereas Fenwick has no live traffic yet.
- **Fenwick status when parked:** signature verification is committed and tested. Order creation and failure tracking are not started, and are the first thing to resume.
- **Northgate work:** replaced guessing with one strict date format per office, rejecting values that don't format back to the exact input. The import is all-or-nothing, and each row keeps its raw date and source batch.

**10:25 — Northgate: historical audit decision** (`82e13f6`)
A forward-only fix leaves every date already saved wrong, so it is not enough. Decided against a bulk "fix" script, because guessing old dates would cause a second corruption incident. Built `shipments:audit-dates` instead:
- It re-parses the retained raw value and is a dry run by default. `--apply` is required before anything changes.
- Rows with no raw value, or a raw value that cannot be parsed, are flagged for source-file review, never changed, and make the command exit non-zero.

Remaining Northgate work is client input (formats, original files, approval to apply), recorded for the status report.

**11:00 — Ticket A resumed** (`235adff`, `89fdd63`)
Picked Fenwick up exactly where it was parked.
- **Order creation** happens in `PaymentWebhookProcessor`: payload validation, then `firstOrCreate()` relying on the unique key so retries and concurrent deliveries end with one order. Unknown event types are acknowledged and ignored.
- **Failure tracking:** failures are saved with atomic attempt counts and logged at error level. They return non-2xx so the provider retries, and a later success marks them resolved.
- **Monitoring:** `webhooks:check-failures` runs every five minutes and exits non-zero while failures are unresolved.

**12:15 — Marlow interrupt: Ticket C** (`485a3cb`)
Paused Fenwick again.
- **Fenwick status when parked:** all code is committed and its tests pass. End-to-end verification against a running server is still to do.
- **Marlow work:** built `GET /api/events/upcoming` with the next ten published events starting strictly after now, ordered by start time then ID, exposing only public fields.
- **Authentication:** the ticket does not mention it and the app has no auth system, so I did not invent one. Instead I recorded public access as an open client question that blocks release.

**12:50 — Ticket A verification** (no new commit)
Returned to Fenwick for the parked verification. Re-ran the signature and processor test suites. Then sent the sample event to a running server with a real signed `curl`:
- The first delivery returned `created` and the retry returned `duplicate`, leaving exactly one order.
- A forged signature and a stale signature both returned `401`.
- No session rows or failure rows were written, and `webhooks:check-failures` exited 0.

No code changes were needed. These checks became the Ticket A part of `review:demo` in the next entry.

**13:30 — Review system** (`4319e1a`)
Made verification repeatable for a reviewer:
- **`composer review`** installs, builds a fresh SQLite database, runs the full suite and then `review:demo`, stopping at the first failure.
- **`review:demo`** proves all three tickets without a server, inside a transaction that is rolled back.
- **CI** runs `composer review` on PHP 8.3 and 8.4.

Two problems found and fixed on the way:
- The lock file had Symfony 8.1, which requires PHP 8.4, so the 8.3 job could not have installed. I pinned the Composer platform to PHP 8.3, which moved 17 Symfony packages to 7.4.
- Under the test runner, output from commands run inside the demo was captured by a mock, so the demo gave each of them its own output buffer.

CI passed on both PHP versions on its first run.

**14:30 — Final handoff** (`05c0efa` and this commit)
- **Docs:** wrote the README, starting with the reviewer quick start, and DECISIONS.md with assumptions, AI overrides, scaling risks and deliberate omissions. Added this log and the client status report.
- **Acceptance test:** before handing over, clone the repository into a fresh folder, follow only the README quick start word for word, and confirm it finishes in under five minutes with `All checks passed`. Also confirm that no `.env`, SQLite database or `vendor/` is tracked, Pint is clean and the latest CI run is green.

## Ticket ledger

No ticket was silently dropped. Every interruption recorded where the paused ticket stopped, and every paused ticket was resumed and finished.

| Ticket | Interrupted | Resumed | Finished | Open items (client) |
| ------ | ----------- | ------- | -------- | ------------------- |
| A — Fenwick payment webhook | 09:40 (Northgate), 12:15 (Marlow) | 11:00, 12:50 | 12:50, with automated proof in `review:demo` at 13:30 | Provider documentation, live signing secret, alert channel, whether refunds matter |
| B — Northgate shipment dates | — | — | 10:25 | Confirm office formats, supply original files for older rows, approve `--apply` |
| C — Marlow upcoming events | — | — | 12:15 | **Confirm public access before release**, confirm limit and UTC times |

Known gaps are documented rather than hidden. They are listed in [DECISIONS.md](DECISIONS.md), section 4: for example, no queue for Fenwick, duplicate `external_id` rows in one Northgate file (last row wins), and no authentication for Marlow.
