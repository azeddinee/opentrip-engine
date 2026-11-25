=== OpenTrip Engine ===
Contributors: azeddinee
Tags: tours, travel, booking, destinations
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.0.0-beta.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight tour catalogue, departure inventory and booking-request engine for WordPress.

== Description ==

Publish tours, organize destinations, build day-by-day itineraries, publish tour inclusions and exclusions, manage tour photo galleries, collect moderated guest reviews and star ratings, display tour cards, collect booking requests, manage dated departures and seat inventory, record traveller counts and room requirements, snapshot estimated booking totals, track request status and notify the agency and optionally the customer without WooCommerce. Customers receive a unique request reference; customer and agency email notifications can be configured in Tours > Settings.

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New Plugin > Upload Plugin.
2. Activate OpenTrip Engine.
3. Create tours under Tours.
4. Use [opentrip_tours] to display tours.
5. Open Tours > Departures and create future dates with capacity and price.
6. Open Tours > Settings to configure the agency recipient and email notification toggles.
7. Use [opentrip_booking_form] on a single tour page to collect requests.

== Itinerary Builder ==

Edit a tour and use the Day-by-Day Itinerary panel. Enter a JSON array with one object per day. Supported keys: day, title, city, activities, overnight, meals and transport. Display the current tour itinerary with [opentrip_itinerary], or use [opentrip_itinerary tour_id="123"]. The current editor is JSON-based; invalid JSON is not saved.

== Tour Inclusions & Exclusions ==

Edit a tour and enter one item per line in the Tour Inclusions & Exclusions panel. Add [opentrip_inclusions] to the tour template or page to display the two lists, or use [opentrip_inclusions tour_id="123"] for a specific tour. Empty lists are hidden.

== Tour Reviews & Ratings ==

Add [opentrip_reviews] and [opentrip_review_form] to a tour page or template. Optional tour_id attributes target a specific tour; the reviews shortcode accepts limit="5" (1–50). Submissions are saved as Pending under Tours > Tour Reviews and must be published by an administrator before appearing publicly. Reviews require a name, email, 1–5 star rating and text. Email addresses are not displayed publicly. The MVP does not verify bookings. Public forms include a honeypot and an atomic database-backed limit of five submissions per email address per form type in a rolling 15-minute window; the limiter stores a salted hash, not the raw email, and is not a replacement for host or WAF protection. Booking and review records are restricted to users with site-administrator permissions. Booking records cannot be deleted from the dashboard so seat history is not accidentally lost; cancel a booking to release seats.

== Tour Photo Gallery ==

Edit a tour and select images in the Tour Photo Gallery panel. Display the gallery with [opentrip_gallery], or use [opentrip_gallery tour_id="123" columns="4"]. Up to 40 images are supported. Drag-and-drop ordering and a lightbox are not included yet.

== Customer Booking Emails ==

When a customer submits a request, the plugin emails a receipt with a reference such as OTE-000123. The page confirmation also displays the reference. The receipt states that the request is pending and no payment has been taken. When an administrator changes the status to Confirmed or Cancelled, the customer receives an update email if customer notifications are enabled under Tours > Settings. Agency notification recipient and agency/customer notification toggles are configurable there. Delivery depends on the WordPress site's wp_mail() configuration. This release does not provide customer accounts, self-service booking lookup, payments or invoices.

== Frequently Asked Questions ==

= Does it process payments? =
No. OpenTrip Engine collects booking requests but does not process payments.

= What traveller details are captured and how are they priced? =
Booking requests capture adults, children with ages, infants with ages, and rooms. Each departure can set adult, child, infant and per-room prices, plus inclusive infant and child age limits. Adult price falls back to the existing base price; child price falls back to adult price; infant and room prices are zero unless configured. Every age is validated server-side. Infants count as travellers but do not consume departure seats. The quote is calculated in the departure currency without conversion and saved as a booking-time breakdown, so later rate changes do not alter existing bookings.

= How do departures and seats work? =
Administrators create dated departures with a capacity and can close or reopen future departures when needed. Customers select a departure when submitting a request. Requests reserve seats only after an administrator confirms them; cancelling a confirmed request releases its seats.

= Can I track booking request status? =
Yes. Administrators can mark requests Pending, Confirmed or Cancelled. These are internal statuses only.

= Are pending requests holding seats? =
No. Seats are reserved only when a request is confirmed.

= Does OpenTrip Engine support WordPress privacy requests? =
Yes. It provides personal data export and erasure callbacks for booking and review data matched by email address. Erasure anonymizes booking contact details and removes review author contact details and review text. Booking records remain in anonymized form so inventory history is preserved. Site owners must still assess legal retention requirements and maintain their own privacy policy.

== Testing ==

Run `composer install` and `vendor/bin/phpunit -c phpunit.xml.dist` after installing the WordPress test library and configuring a test database. GitHub Actions tests WordPress 6.2.2 with PHP 7.4 and latest WordPress with PHP 8.3 against MySQL 8.0. Separate jobs run PHP syntax and lightweight smoke tests across PHP 7.4–8.3. Each WordPress integration matrix leg currently passes 23 tests and 138 assertions, including REST API public/private visibility, content sanitization, pagination, departure and destination filtering, booking form output, customer/agency email content, email-scoped submission limits, inventory capacity boundaries, transactional booking confirmation/cancellation and privacy erasure. The CI workflow runs multi-process MySQL stress tests on both integration matrix legs: 20 independent booking processes compete for five seats, and 10 processes concurrently confirm the same booking to verify idempotency. Full live browser-driven form-submission tests remain outstanding.

== Contributing ==

Bug reports and feature requests can be submitted using the GitHub issue templates. Please include WordPress, PHP and database versions with bug reports, provide reproducible steps, and remove customer data or credentials from logs. Pull requests should be focused, include tests where practical, follow WordPress security practices, and update documentation for user-facing changes. See CONTRIBUTING.md and CODE_OF_CONDUCT.md in the source repository.

== REST API ==

A read-only public REST API is available under `/wp-json/opentrip-engine/v1`. Endpoints list published tours, retrieve one published tour, list future open departures with remaining seats, and list non-empty destinations. The API never exposes booking/customer records or arbitrary private post metadata. Draft, private and password-protected tours return 404. See docs/REST-API.md in the source repository for request examples, pagination and privacy boundaries.

== Changelog ==

= 1.0.0-beta.6 =
* Add configurable adult, child and infant departure pricing.
* Add inclusive child age bands and per-room charges.
* Validate traveller ages and preserve itemized booking-time quote snapshots.
* Include quote breakdowns in admin booking details and customer receipts.


= 1.0.0-beta.6 =
* Add configurable adult, child, infant and room pricing to departures.
* Validate child and infant ages and preserve immutable booking quote snapshots.
* Show the saved quote breakdown in booking administration and customer receipts.

= 1.0.0-beta.5 =
* Add transactional booking status and per-booking seat reservation ledger.
* Add atomic database-backed submission limits and erase limiter hashes through privacy requests.
* Add configurable agency/customer email notifications and departure close/reopen controls.
* Harden public form input handling and correct HTTP error response codes.
* Expand WordPress integration and compatibility testing.

= 1.0.0-beta.4 =
* Add privacy-preserving, email-scoped best-effort rate limits to booking and review submissions.
* Expand integration coverage for booking form output, customer emails and inventory capacity boundaries.

= 1.0.0-beta.3 =
* Add WordPress personal data export and erasure support for bookings and reviews.
* Preserve booking status when seat release fails and reject confirmation for past departures.
* Add a booking-form honeypot and bounded customer input validation.
* Add PHPUnit Polyfills and expand WordPress integration tests.

= 1.0.0-beta.2 =
* Add initial WordPress integration tests backed by MySQL.
* Run WordPress integration tests in GitHub Actions.

= 1.0.0-beta.1 =
* Add PHP 7.4–8.3 syntax-check matrix and departure inventory smoke tests.
* Document test scope and remaining integration-test requirements.


= 0.9.0 =
* Send customer booking receipt and status-change emails.
* Generate and display a unique booking reference.

= 0.8.0 =
* Add visitor review submission, 1–5 star ratings and administrator moderation.
* Add approved review display with average rating and review count.


= 0.7.0 =
* Add Media Library gallery picker and frontend gallery shortcode.

= 0.6.0 =
* Add per-tour inclusions and exclusions editor and frontend shortcode.

= 0.5.0 =
* Add structured day-by-day itinerary editing and frontend shortcode output.

= 0.4.0 =
* Capture adult/child counts and rooms; save a booking-time price snapshot.

= 0.3.0 =
* Add departure management, departure selection on booking forms and atomic seat reservation/release when confirming or cancelling requests.

= 0.2.0 =
* Add booking request status management, admin list columns, admin email notification and frontend styles.

= 0.1.0 =
* Initial MVP: tours, destinations, tour listing and booking requests.
