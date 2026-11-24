# OpenTrip Engine

A lightweight, open-source tour catalogue and booking-request plugin for WordPress. No WooCommerce required.

**Current status: 0.4.0 — traveller details and quote snapshot MVP.** This release collects booking requests; it is not a complete reservation or payment system.

## Features
- Tour custom post type with title, description, excerpt and featured image.
- Destination taxonomy.
- Tour fields: price per person, ISO currency, duration and informational capacity.
- `[opentrip_tours]` lists published tours.
- `[opentrip_tours destination="morocco" limit="6"]` filters by destination slug.
- `[opentrip_booking_form]` displays a request form for the current tour.
- `[opentrip_booking_form tour_id="123"]` displays a form for a specified published tour.
- Booking requests are stored privately in the WordPress dashboard.
- Internal request statuses: Pending, Confirmed and Cancelled.
- Admin booking table with tour, customer, travel date, traveller count and status.
- New-request notification sent to the WordPress admin email using `wp_mail()`.
- Responsive frontend CSS for tour cards and booking forms.
- Admin departure manager with dates, capacity, price and currency.
- Booking form selects a real departure and displays remaining seats.
- Booking requests capture adult and child counts plus rooms required.
- The estimated total and unit price are saved as a booking-time price snapshot.
- Atomic seat reservation when an administrator confirms a request; cancellation releases reserved seats.
- Nonce verification, capability checks, input sanitization and output escaping.

## Requirements
- WordPress 6.2+
- PHP 7.4+

## Install
1. Download this repository as a ZIP.
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Upload the ZIP and activate it.
4. Create tours under **Tours → Add New**.
5. Add destination terms under **Tours → Destinations**.
6. Place `[opentrip_tours]` on a page.
7. Add `[opentrip_booking_form]` to a single tour template/page.

## Agency workflow in 0.4.0
Requests start as Pending. An administrator can confirm a request; confirmation attempts an atomic seat reservation against the selected departure. If capacity is no longer available, confirmation is rejected. Cancelling a confirmed request releases its seats. The basic quote currently applies the departure's per-traveller price equally to adults and children. Room count is recorded but does not alter the quote. Infant-specific pricing, child discounts, room allocation and payment processing are not implemented yet. This is an inventory workflow, not payment processing or a guarantee that a tour operator has operationally approved the trip. Email delivery depends on WordPress mail configuration; configure SMTP separately if needed.

## Important limitations
This MVP does not process payments, calculate taxes, or provide customer accounts. Pending requests do not hold seats; only confirmed requests reserve seats. The seat reservation update is atomic at the database level, but the plugin still needs integration tests and broader production hardening.

Booking records contain personal data. Site operators are responsible for privacy notices, retention, access controls, backups and HTTPS. Privacy exporter/eraser integration and automated tests are planned before a stable production release.

## Roadmap
- [x] Basic PHP syntax check workflow (GitHub Actions)
- [ ] Automated WordPress integration tests
- [x] Departure dates and atomic seat inventory reservation
- [ ] Email notification settings and customer confirmations
- [ ] WordPress privacy exporter/eraser integration
- [ ] Permission-checked REST API
- [ ] Pricing rules and multi-currency formatting
- [ ] Optional payment gateway adapters
- [ ] Translation and accessibility review

## Contributing
See [CONTRIBUTING.md](CONTRIBUTING.md). Bug reports and focused pull requests are welcome.

## License
GPL-2.0-or-later. See [LICENSE](LICENSE).
