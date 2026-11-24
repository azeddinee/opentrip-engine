# OpenTrip Engine

A lightweight, open-source tour catalogue and booking-request plugin for WordPress. No WooCommerce required.

**Current status: 0.7.0 — itinerary, inclusions/exclusions and tour gallery MVP.** This release collects booking requests; it is not a complete reservation or payment system.

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
- Day-by-day itinerary editor for up to 60 days, with city, activities, overnight stay, meals and transportation.
- `[opentrip_itinerary]` renders the current tour's itinerary; `[opentrip_itinerary tour_id="123"]` renders a selected tour's itinerary.
- Per-tour inclusions and exclusions editor with one item per line, saved as sanitized tour metadata.
- `[opentrip_inclusions]` displays included and excluded items for the current tour; `[opentrip_inclusions tour_id="123"]` displays a selected tour's lists.
- Media Library gallery picker for each tour, supporting up to 40 images.
- `[opentrip_gallery]` renders the current tour gallery; `[opentrip_gallery tour_id="123" columns="4"]` renders a selected tour gallery.
- Itinerary data is stored as structured tour metadata and sanitized on save.
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

## Itinerary workflow in 0.5.0
Edit a tour and use the **Day-by-Day Itinerary** panel. Enter a JSON array with one object per day. Supported keys are `day`, `title`, `city`, `activities`, `overnight`, `meals` and `transport`. Add `[opentrip_itinerary]` to the tour template or page to display the schedule. The editor currently uses JSON rather than a drag-and-drop repeater; invalid JSON is not saved.

## Tour gallery workflow in 0.7.0
Edit a tour and use the **Tour Photo Gallery** panel to select images from the WordPress Media Library. The selected images are saved in their selected order (up to 40). Add `[opentrip_gallery]` to the tour template or page. Optional attributes: `tour_id` and `columns` (1–6). The gallery uses the attachment links so visitors can open the full image. Drag-and-drop reordering and a lightbox are not included yet.

## Inclusions and exclusions workflow in 0.6.0
Edit a tour and use the **Tour Inclusions & Exclusions** panel. Enter one item per line in each box. The frontend shortcode `[opentrip_inclusions]` displays the two lists on the current tour; use `[opentrip_inclusions tour_id="123"]` for a specific tour. Empty lists are hidden, and each list is limited to 100 unique items.

## Agency workflow in 0.6.0
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
