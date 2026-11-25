# OpenTrip Engine

A lightweight, open-source tour catalogue and booking-request plugin for WordPress. No WooCommerce required.

**Current status: 1.0.0-beta.5 — transactional inventory, configurable notifications and hardened forms.** This release is a booking-request and seat-inventory engine; it does not process payments or taxes.

## Features
- Tour custom post type with title, description, excerpt and featured image.
- Destination taxonomy.
- Tour fields: price per person, ISO currency, duration and informational capacity.
- `[opentrip_tours]` lists published tours.
- `[opentrip_tours destination="morocco" limit="6"]` filters by destination slug.
- `[opentrip_booking_form]` displays a request form for the current tour.
- `[opentrip_booking_form tour_id="123"]` displays a form for a specified published tour.
- Booking requests and review moderation records are restricted to site administrators in the WordPress dashboard. Booking records cannot be deleted from the dashboard; cancel a confirmed request to release seats while preserving the inventory audit trail.
- Internal request statuses: Pending, Confirmed and Cancelled.
- Admin booking table with tour, customer, travel date, traveller count and status.
- Configurable agency notification recipient and on/off controls for agency and customer emails under **Tours → Settings**.
- Customer email receipt with a unique booking reference after request submission.
- Customer email notifications when an administrator changes a request to Confirmed or Cancelled.
- Booking reference displayed in the post-submission confirmation message and in the admin booking details.
- Responsive frontend CSS for tour cards and booking forms.
- Admin departure manager with dates, capacity, price, currency and safe close/reopen controls.
- Booking form selects a real departure and displays remaining seats.
- Booking requests capture adult and child counts plus rooms required.
- Day-by-day itinerary editor for up to 60 days, with city, activities, overnight stay, meals and transportation.
- `[opentrip_itinerary]` renders the current tour's itinerary; `[opentrip_itinerary tour_id="123"]` renders a selected tour's itinerary.
- Per-tour inclusions and exclusions editor with one item per line, saved as sanitized tour metadata.
- `[opentrip_inclusions]` displays included and excluded items for the current tour; `[opentrip_inclusions tour_id="123"]` displays a selected tour's lists.
- Media Library gallery picker for each tour, supporting up to 40 images.
- Visitor review form with 1–5 star ratings; submissions are saved as Pending and require administrator approval.
- `[opentrip_reviews]` displays approved reviews and their average rating; `[opentrip_review_form]` displays the review form for the current tour.
- Review author email is stored privately in review metadata and is not displayed publicly; booking and review forms include a honeypot and a atomic limit of five submissions per email address per form type in a rolling 15-minute window. The limiter uses an atomic database counter keyed by a salted email hash and form type; raw email addresses are not stored in the limiter table, and old hashes are cleaned up opportunistically after 24 hours.
- `[opentrip_gallery]` renders the current tour gallery; `[opentrip_gallery tour_id="123" columns="4"]` renders a selected tour gallery.
- Itinerary data is stored as structured tour metadata and sanitized on save.
- The estimated total and unit price are saved as a booking-time price snapshot.
- Transactional booking-status changes and per-booking seat reservation ledger; repeated confirmations do not increment inventory twice, and cancellation releases reserved seats.
- Nonce verification, capability checks, bounded input validation, input sanitization and output escaping.
- Booking and review data are available through WordPress privacy export and erasure tools.

## Testing

Run the lightweight departure inventory smoke tests with `php tests/departures-smoke.php`. The CI workflow tests WordPress 6.2.2 with PHP 7.4 and the latest WordPress with PHP 8.3 against MySQL 8.0. PHP syntax and inventory smoke tests run across PHP 7.4–8.3. Each WordPress integration matrix leg currently passes 18 tests and 108 assertions, covering content types, shortcode output/escaping, inventory capacity, transactional confirmation/cancellation, departure close/reopen, booking form output, email settings and content, database-backed rate limits, and privacy export/erasure. A multi-process load test and full browser-driven submission tests are still outstanding. Review the Actions results before treating a commit as verified.

## Requirements
- WordPress 6.2+
- PHP 7.4+

## Install
1. Download the packaged plugin ZIP from [GitHub Releases](https://github.com/azeddinee/opentrip-engine/releases).
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Upload the ZIP and activate it.
4. Create tours under **Tours → Add New**.
5. Add destination terms under **Tours → Destinations**.
6. Place `[opentrip_tours]` on a page.
7. Open **Tours → Departures** and create dated departures with capacity and price.
8. Open **Tours → Settings** to configure the agency notification address and email toggles.
9. Add `[opentrip_booking_form]` to a single tour template/page.

## Itinerary workflow in 0.5.0
Edit a tour and use the **Day-by-Day Itinerary** panel. Enter a JSON array with one object per day. Supported keys are `day`, `title`, `city`, `activities`, `overnight`, `meals` and `transport`. Add `[opentrip_itinerary]` to the tour template or page to display the schedule. The editor currently uses JSON rather than a drag-and-drop repeater; invalid JSON is not saved.

## Customer booking communications in 0.9.0
After a request is saved, the customer receives an email receipt with a reference such as `OTE-000123` and a summary of the tour, departure, traveller count and estimated total. The on-page success message also displays the reference. The receipt clearly states that the request is not confirmed and that no payment has been taken. When an administrator changes the request status to Confirmed or Cancelled, a corresponding email can be sent to the customer if enabled in **Tours → Settings**. Agency notification recipient and notification toggles are configurable there. These messages use WordPress `wp_mail()`; reliable delivery depends on the site's mail configuration. This release does not add customer login, self-service booking lookup, payment processing or automated invoices.

## Reviews workflow in 0.8.0
Add `[opentrip_reviews]` and `[opentrip_review_form]` to a tour template or page. Optional `tour_id` can target a specific tour; `[opentrip_reviews limit="5"]` controls the number displayed (1–50). New reviews are held for moderation under **Tours → Tour Reviews** and appear publicly only after an administrator publishes them. The review form requires a name, email, 1–5 rating and review text. Email addresses are not shown on the frontend. This MVP does not verify that a reviewer booked or completed the tour. The honeypot and email-scoped rate limit are basic anti-abuse controls, not a substitute for a WAF.

## Tour gallery workflow in 0.7.0
Edit a tour and use the **Tour Photo Gallery** panel to select images from the WordPress Media Library. The selected images are saved in their selected order (up to 40). Add `[opentrip_gallery]` to the tour template or page. Optional attributes: `tour_id` and `columns` (1–6). The gallery uses the attachment links so visitors can open the full image. Drag-and-drop reordering and a lightbox are not included yet.

## Inclusions and exclusions workflow in 0.6.0
Edit a tour and use the **Tour Inclusions & Exclusions** panel. Enter one item per line in each box. The frontend shortcode `[opentrip_inclusions]` displays the two lists on the current tour; use `[opentrip_inclusions tour_id="123"]` for a specific tour. Empty lists are hidden, and each list is limited to 100 unique items.

## Agency workflow in 0.6.0
Requests start as Pending. An administrator can confirm a request; confirmation attempts an atomic seat reservation against the selected departure. If capacity is no longer available, confirmation is rejected. Cancelling a confirmed request releases its seats. The basic quote currently applies the departure's per-traveller price equally to adults and children. Room count is recorded but does not alter the quote. Infant-specific pricing, child discounts, room allocation and payment processing are not implemented yet. This is an inventory workflow, not payment processing or a guarantee that a tour operator has operationally approved the trip. Email delivery depends on WordPress mail configuration; configure SMTP separately if needed.

## Important limitations
This beta does not process payments, calculate taxes, or provide customer accounts. Pending requests do not hold seats; only confirmed requests reserve seats. Seat inventory and booking status transitions run in a database transaction with a locked booking-status row and a per-booking reservation ledger. This prevents duplicate seat increments for repeated confirmations; multi-process load testing is still required before claiming production-scale concurrency validation. Public forms use a honeypot, bounded input validation and an atomic database-backed limit of five submissions per email address per form type in a rolling 15-minute window. The limiter stores a salted email hash rather than the raw address and does not trust proxy IP headers. It is not a substitute for host/WAF protection: distributed abuse can use different email addresses. CAPTCHA and IP-based edge rate limiting are not built in.

Booking records contain personal data. Site operators are responsible for privacy notices, retention, access controls, backups and HTTPS. Privacy exporter/eraser integration is available; automated coverage is included for booking and review export/erasure. Site owners remain responsible for reviewing legal retention obligations and configuring their privacy policy.

## Roadmap
- [x] Customer booking receipt, reference number and status update emails
- [x] Basic moderated tour reviews and star ratings
- [x] Basic PHP syntax check workflow (GitHub Actions)
- [x] Initial WordPress integration tests (content types, shortcode output and MySQL inventory)
- [x] Departure dates and atomic seat inventory reservation
- [x] Customer booking receipts and status-change notifications
- [x] Atomic database-backed email-scoped public form rate limiting
- [x] Transactional booking status and seat reservation ledger
- [x] Booking form and customer email integration coverage
- [x] Configurable email notification settings
- [x] WordPress privacy exporter/eraser integration
- [ ] Permission-checked REST API
- [ ] Pricing rules and multi-currency formatting
- [ ] Optional payment gateway adapters
- [ ] Translation and accessibility review

## Contributing
See [CONTRIBUTING.md](CONTRIBUTING.md). Bug reports and focused pull requests are welcome.

## License
GPL-2.0-or-later. See [LICENSE](LICENSE).
