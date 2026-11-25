# Traveller pricing and quote snapshots

OpenTrip Engine calculates each booking estimate from the selected departure's rates. Rates are denominated in the departure's ISO currency; the plugin never converts currencies.

## Configure a departure

Under **Tours → Departures → Add departure**, set the base price and currency. Optional fields allow an operator to set:

- **Adult price:** leave blank to use the base price.
- **Child price:** leave blank to use the adult price.
- **Infant price:** defaults to zero (free).
- **Infant maximum age:** inclusive; defaults to 1.
- **Child minimum and maximum ages:** inclusive; defaults to 2–11.
- **Room charge:** additional charge per requested room; defaults to zero.
- **Child age bands:** optional JSON array for up to ten non-overlapping age ranges, each with its own price.

Example child age bands:

```json
[
  { "min_age": 2, "max_age": 5, "price": 75 },
  { "min_age": 6, "max_age": 11, "price": 95 }
]
```

Age boundaries are inclusive. A child must match exactly one configured band. Gaps and overlaps are rejected at configuration/quote time. If no JSON bands are configured, the departure's single child age range and child price apply.

## Example family quote

For a departure with adult price USD 500, child bands 2–5 at USD 250 and 6–11 at USD 350, infant price USD 0, and room charge USD 40:

- 2 adults: USD 1,000
- 1 child aged 4: USD 250
- 1 child aged 8: USD 350
- 1 infant aged 1: USD 0
- 2 rooms: USD 80
- **Estimated total: USD 1,680**

Infants are included in the total traveller count but do not consume departure seat capacity. Adults and children consume one seat each. Operators should set this policy to match their actual transport and supplier arrangements.

## Booking form age entry

The booking form asks for child and infant counts and comma-separated ages. The number of submitted ages must exactly match the corresponding count. Child ages must be 0–17 and match a configured child band (or the default child range); infant ages must be within the departure's inclusive infant maximum.

## Immutable quote snapshot

At submission, the plugin stores the full calculated breakdown in the private booking's `_ote_quote_snapshot` metadata and the total in `_ote_total_price`. The snapshot records quantities, ages, unit rates, subtotals, room charges, total and currency. Changing departure rates later does not recalculate an existing booking. The admin booking details and customer email receipt use the saved snapshot.

This is an estimate attached to a booking request, not a payment, tax calculation, guaranteed supplier confirmation or currency conversion. Pending requests do not hold seats; seat reservations are made only when an administrator confirms the request.
