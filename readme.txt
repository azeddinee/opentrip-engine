=== OpenTrip Engine ===
Contributors: azeddinee
Tags: tours, travel, booking, destinations
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.0.0-beta.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight tour catalogue, departure inventory and booking-request engine for WordPress.

== Description ==

Publish tours, organize destinations, build day-by-day itineraries, publish tour inclusions and exclusions, manage tour photo galleries, collect moderated guest reviews and star ratings, display tour cards, collect booking requests, manage dated departures and seat inventory, record traveller counts and room requirements, snapshot estimated booking totals, track request status and notify the site administrator and customer without WooCommerce. Customers receive a unique request reference and email updates when the agency changes the request status.

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New Plugin > Upload Plugin.
2. Activate OpenTrip Engine.
3. Create tours under Tours.
4. Use [opentrip_tours] to display tours.
5. Use [opentrip_booking_form] on a single tour page to collect requests.

== Itinerary Builder ==

Edit a tour and use the Day-by-Day Itinerary panel. Enter a JSON array with one object per day. Supported keys: day, title, city, activities, overnight, meals and transport. Display the current tour itinerary with [opentrip_itinerary], or use [opentrip_itinerary tour_id="123"]. The current editor is JSON-based; invalid JSON is not saved.

== Tour Inclusions & Exclusions ==

Edit a tour and enter one item per line in the Tour Inclusions & Exclusions panel. Add [opentrip_inclusions] to the tour template or page to display the two lists, or use [opentrip_inclusions tour_id="123"] for a specific tour. Empty lists are hidden.

== Tour Reviews & Ratings ==

Add [opentrip_reviews] and [opentrip_review_form] to a tour page or template. Optional tour_id attributes target a specific tour; the reviews shortcode accepts limit="5" (1–50). Submissions are saved as Pending under Tours > Tour Reviews and must be published by an administrator before appearing publicly. Reviews require a name, email, 1–5 star rating and text. Email addresses are not displayed publicly. The MVP does not verify bookings and includes only a basic honeypot spam check.

== Tour Photo Gallery ==

Edit a tour and select images in the Tour Photo Gallery panel. Display the gallery with [opentrip_gallery], or use [opentrip_gallery tour_id="123" columns="4"]. Up to 40 images are supported. Drag-and-drop ordering and a lightbox are not included yet.

== Customer Booking Emails ==

When a customer submits a request, the plugin emails a receipt with a reference such as OTE-000123. The page confirmation also displays the reference. The receipt states that the request is pending and no payment has been taken. When an administrator changes the status to Confirmed or Cancelled, the customer receives an update email. Delivery depends on the WordPress site's wp_mail() configuration. This release does not provide customer accounts, self-service booking lookup, payments or invoices.

== Frequently Asked Questions ==

= Does it process payments? =
No. Version 0.1.0 collects booking requests only.

= What traveller details are captured? =
Booking requests capture adults, children and rooms required. The estimated total uses the departure's per-traveller price for each adult and child. Room count is recorded but does not affect the price. Infant-specific prices and child discounts are not included yet.

= How do departures and seats work? =
Administrators create dated departures with a capacity. Customers select a departure when submitting a request. Requests reserve seats only after an administrator confirms them; cancelling a confirmed request releases its seats.

= Can I track booking request status? =
Yes. Administrators can mark requests Pending, Confirmed or Cancelled. These are internal statuses only.

= Does it process payments? =
No. Version 0.3.0 manages departure seat inventory but does not collect payments.

= Are pending requests holding seats? =
No. Seats are reserved only when a request is confirmed.

== Testing ==\n\nRun `php tests/departures-smoke.php` to execute the lightweight departure inventory smoke tests. GitHub Actions checks PHP syntax and runs these tests on PHP 7.4–8.3. The tests use a fake database adapter and are not a substitute for WordPress integration tests or real MySQL concurrency testing. This beta should not be treated as a fully verified stable production release.\n\n== Changelog ==\n\n= 1.0.0-beta.1 =\n* Add PHP 7.4–8.3 syntax-check matrix and departure inventory smoke tests.\n* Document test scope and remaining integration-test requirements.\n

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
