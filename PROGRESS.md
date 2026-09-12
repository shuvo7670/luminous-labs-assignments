# Progress log

Timestamps are simulated across one session within the 4-hour ceiling. The order of work follows the real commit history.

**09:00 — Setup, then Ticket A (Fenwick)** (`e3d4aa4`, `825afea`)
Started with Fenwick: it is the main task and carries the most risk (duplicate orders, forged requests). Put the unique key on `(provider, provider_payment_id)` into the schema from the start, then added signature verification over the raw body with a signed timestamp. The route sits outside the `web` group, because the database session driver would otherwise write a row for every forged request.

**09:25 — Northgate interrupt (URGENT)** (`56f9ecb`)
Paused Fenwick after signature verification; order creation and failure tracking were parked. Switched straight away, because Northgate is writing wrong data every day and Fenwick has no live traffic yet. Replaced the guessing parser with one strict format per office. Imports are now all-or-nothing and keep the raw date.

**09:45 — Northgate: fixing the parser is not enough** (`82e13f6`)
Wrong dates are already saved, so a forward-only fix would leave them wrong. Built an audit that re-parses the stored raw value. It is a dry run by default and needs `--apply` to change anything. Rows without a raw value are flagged for source-file review, never guessed. Noted what Northgate must provide: formats, old files and approval.

**10:05 — Ticket A resumed** (`235adff`, `89fdd63`)
Picked up at the parked point:
- validation of `payment.succeeded`, and `firstOrCreate()` backed by the unique key
- `200` for duplicates and unknown event types
- failures saved, logged and answered with non-2xx so the provider retries, then marked resolved by a later success
- a scheduled check every five minutes

**11:00 — Marlow interrupt ("whenever you get a sec")** (`485a3cb`)
It was small and low-risk, so I did it now instead of letting it slip, in about 20 minutes with light tests. Parked Fenwick with the code done and end-to-end verification still pending. No auth exists and nobody can clarify today, so I returned public fields only and flagged public access as a release blocker instead of inventing auth.

**11:20 — Ticket A verification** (no commit)
Back to Fenwick. A signed `curl` to a running server returned `created`, then `duplicate`, with one order. Forged and stale signatures got `401`, and nothing was stored for them.

**11:35 — Reviewer path** (`4319e1a`)
Added `composer review`, `review:demo` and CI, so all three tickets can be verified in under five minutes. On the way, fixed a PHP 8.3 install blocker caused by locked Symfony 8.1 packages.

**12:05 — Docs** (`05c0efa`, `6bd56b6`)
Wrote the README quick start, DECISIONS.md, this log and the client status report.

**12:25 — Self-review against the brief** (`this commit`)
Re-read the brief before submitting and fixed the parts it would mark down:
- **Race test:** added a test that reproduces the check-then-insert race and fails if the unique key is removed.
- **Alerts:** email alerts, so failures reach a person.
- **Databases:** CI on MySQL and PostgreSQL.
- **Test weighting:** heavier on Fenwick than on the other tickets.
- **Status report:** shorter, plainer paragraphs.

**12:50 — Handoff**
From a fresh clone, the README quick start ends with `All checks passed` in under five minutes, and CI is green. Total time is about 3 h 50 min.

## Ticket ledger

No ticket was silently dropped. Every interruption recorded where the paused ticket stopped, and every paused ticket was resumed and finished.

| Ticket | Paused by | Resumed | Finished | Waiting on the client |
| ------ | --------- | ------- | -------- | --------------------- |
| A — Fenwick webhook | Northgate (09:25), Marlow (11:00) | 10:05, 11:20 | 11:20; race test and alerts added at 12:25 | Provider name, live secret, alert email address, refunds question |
| B — Northgate dates | — | — | 09:45 | Office formats, original files for older rows, approval to apply |
| C — Marlow events | — | — | 11:00 | **Public access decision before release** |

Known gaps are documented rather than hidden. They are listed in [DECISIONS.md](DECISIONS.md), section 4.
