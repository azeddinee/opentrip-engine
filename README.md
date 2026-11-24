# OpenTrip Engine

A lightweight, open-source tour catalogue and booking-request plugin for WordPress. No WooCommerce required.

**Current status: 0.1.0 — early MVP.** This release collects booking requests; it is not a complete reservation or payment system.

## Features
- Tour custom post type with title, description, excerpt and featured image.
- Destination taxonomy.
- Tour fields: price per person, ISO currency, duration and informational capacity.
- `[opentrip_tours]` lists published tours.
- `[opentrip_tours destination="morocco" limit="6"]` filters by destination slug.
- `[opentrip_booking_form]` displays a request form for the current tour.
- `[opentrip_booking_form tour_id="123"]` displays a form for a specified published tour.
- Booking requests are stored privately in the WordPress dashboard.
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

## Important limitations
This MVP does not confirm availability, reserve inventory, prevent overbooking, calculate taxes, process payments, send transactional email, or provide customer accounts. The capacity field is informational only. Do not represent a submitted request as a confirmed booking.

Booking records contain personal data. Site operators are responsible for privacy notices, retention, access controls, backups and HTTPS. Privacy exporter/eraser integration and automated tests are planned before a stable production release.

## Roadmap
- [ ] Automated tests and CI
- [ ] Departure dates and concurrency-safe inventory
- [ ] Email notifications and settings
- [ ] WordPress privacy exporter/eraser integration
- [ ] Permission-checked REST API
- [ ] Pricing rules and multi-currency formatting
- [ ] Optional payment gateway adapters
- [ ] Translation and accessibility review

## Contributing
See [CONTRIBUTING.md](CONTRIBUTING.md). Bug reports and focused pull requests are welcome.

## License
GPL-2.0-or-later. See [LICENSE](LICENSE).
