# Stripe hosted payments

OpenTrip Engine includes an optional Stripe Checkout adapter. It is disabled by default. When enabled, an administrator can create a hosted payment link for a booking request that has already been marked **confirmed** by the agency.

The plugin never renders card fields, receives card numbers or CVC values, or stores cardholder data. Customers enter payment details on Stripe's hosted Checkout page. Payment is a separate state from booking confirmation: a successful payment webhook updates the payment record but does not confirm, cancel or otherwise change the booking status.

## Configure Stripe

1. Use HTTPS on the WordPress site and ensure outgoing HTTPS requests are allowed.
2. In Stripe, create a restricted secret key suitable for Checkout Session creation. Use an `sk_test_` key while testing; use an `sk_live_` key only after end-to-end test payments and webhook verification succeed.
3. In WordPress, open **OpenTrip Engine → Settings**.
4. Enable Stripe Checkout, paste the secret key, paste the webhook signing secret, and choose the percentage of the saved quote to request (1–100; default 100).
5. The key prefix determines test or live mode. The secret fields are password inputs, never rendered back into the settings page, and are stored as non-autoloaded WordPress options. Leaving a secret field blank keeps the saved value.
6. Copy the webhook URL shown under the webhook secret field. In Stripe, add a webhook endpoint for that URL and subscribe to:
   - `checkout.session.completed`
   - `checkout.session.async_payment_succeeded`
   - `checkout.session.async_payment_failed`
   - `checkout.session.expired`
7. Copy the endpoint's signing secret (starts with `whsec_`) into the matching WordPress test or live configuration.

Use a separate Stripe webhook endpoint and signing secret for test and live mode. The adapter verifies the signature against the raw request body, enforces a five-minute timestamp tolerance, checks the Stripe session ID, booking reference, currency, amount and live/test mode, and records event IDs for idempotent processing.

## Create a payment link

1. Open a booking request in WordPress.
2. Mark the booking request **Confirmed** using the existing booking-status control.
3. In the Payment panel, select **Create Stripe payment link**.
4. The adapter creates or reuses a Stripe Checkout Session using the immutable quote snapshot and configured percentage.
5. If customer notifications are enabled and email delivery succeeds, the hosted link is emailed to the customer. Otherwise, copy the existing link from the Payment panel and send it through the agency's normal process.

A still-valid pending Checkout Session is reused. When a link expires or reaches a terminal state, a new session can be created. The adapter keeps a bounded history of previous session IDs and amounts so delayed webhook events from an earlier link can still be matched safely.

## Payment states and booking workflow

- `pending`: a hosted payment link exists and is awaiting a verified webhook.
- `paid`: Stripe confirmed payment.
- `failed`: Stripe reported an asynchronous payment failure.
- `expired`: Stripe reported that the Checkout Session expired.
- `not_started`: no payment link has been created.

The webhook never changes `_ote_booking_status`. If a payment arrives after a booking is no longer confirmed, the payment is recorded and an admin warning is shown so the agency can review the booking and arrange any required refund manually. A browser redirect to the success URL is **not** treated as proof of payment; only a verified server-to-server webhook updates the payment state.

The public tour REST API does not expose secret keys, webhook secrets, payment URLs, Checkout Session IDs or transaction IDs. The webhook endpoint is publicly reachable so Stripe can call it, but every event must pass signature and session validation.

## Amounts, currencies and limitations

- Amounts are calculated from the saved booking quote; no currency conversion or tax calculation is performed.
- The configured percentage is applied to the quote total and rounded to the currency's supported minor unit. Zero-decimal currencies and Stripe's ISK/UGX whole-unit handling are accounted for.
- Stripe's own supported-currency rules, minimum charges, account capabilities and payment-method availability still apply. If Stripe rejects an amount or currency, no payment link is saved.
- The adapter does not initiate refunds, subscriptions, partial refunds, disputes or payouts. Handle refunds and disputes in the Stripe Dashboard and record the outcome in the agency's booking/accounting workflow.
- Payment status and amount/currency may remain on anonymized bookings for accounting and audit. The WordPress personal data exporter includes payment status and amount details; the eraser removes local Checkout URLs, session identifiers and transaction identifiers.
- If Stripe is not enabled or configured, the existing booking-request workflow is unchanged.

## Security checklist

- Never commit or share Stripe secret keys or webhook signing secrets in source control, support tickets or screenshots.
- Keep WordPress, backups and database access restricted; non-autoloaded options are not encrypted at rest.
- Use HTTPS and keep test and live credentials separate.
- Configure the webhook URL exactly as displayed in WordPress and test both successful and failed payments before switching to live mode.
- Do not treat the return URL, a query parameter or a customer screenshot as payment proof.
- Keep the agency's booking confirmation and refund decisions manual; the adapter only records the verified payment event.

## References

- [Stripe Checkout Sessions API](https://docs.stripe.com/api/checkout/sessions)
- [Create a Checkout Session](https://docs.stripe.com/api/checkout/sessions/create)
- [Verify webhook signatures](https://docs.stripe.com/webhooks/signature)
- [Currencies and minor units](https://docs.stripe.com/currencies)
