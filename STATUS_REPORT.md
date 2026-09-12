# End-of-day status updates

## Fenwick Retail — payment notifications

**Done today:** Your online store now creates an order automatically when your payment provider tells us a payment has succeeded. If the provider sends the same notification more than once, which providers do when a connection is slow, you still get exactly one order. Notifications that don't carry your secret signature, or that are more than five minutes old, are rejected, so nobody can create fake orders. If a genuine notification can't be processed, we keep a record of it, ask the provider to send it again, and check every five minutes for any that are still unresolved.

**What we need from you:**

- The name of your payment provider and a link to its notification documentation, so we can confirm the message format and signature method we have assumed.
- The live signing secret from your provider's dashboard, shared through a secure channel (not email).
- Confirmation of where operational alerts should go (for example your on-call email or Slack channel), so an unresolved payment problem reaches a person quickly.
- Whether refunds or failed payments should also update orders. Today only successful payments do.

## Northgate Logistics — shipment dates

**Done today:** New imports read each regional office's dates in that office's own format, so 07/03/2026 is 7 March from the UK office and 3 July from the US office, and the system never guesses. If any row in a file has a problem, the whole file is rejected with a message naming the row, so a partly wrong import can't slip through. We now keep each date exactly as it appeared in the original file. We also built a checking tool that lists existing shipments whose saved date doesn't match their original text. It only reports by default, and changes nothing until someone approves the corrections.

**What we need from you:**

- Confirmation of the office codes and date formats: UK is day/month/year, US is month/day/year, and ISO is year-month-day.
- The original import files for older shipments. Records imported before today don't have their original date text, so we cannot safely correct them without those files.
- Approval to apply the corrections, once you have reviewed the list the checking tool produces, and which import batches you believe are affected.

## Marlow Events — upcoming events

**Done today:** There is now a feed of your next ten upcoming events, in date order. It only includes events you have published, never drafts or cancelled events, and it shares only the event name, start time, venue and description.

**What we need from you:**

- **Before release:** confirmation that this feed may be public. It currently needs no login. If it should only be visible to signed-in users or partners, we'll add that first. This is the one open question blocking release.
- Confirmation that ten events is the right number, and that start times should be shown in UTC for your site or app to convert to local time.
