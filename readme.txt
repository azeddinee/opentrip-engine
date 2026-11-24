=== OpenTrip Engine ===
Contributors: azeddinee
Tags: tours, travel, booking, destinations
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight tour catalogue, departure inventory and booking-request engine for WordPress.

== Description ==

Publish tours, organize destinations, display tour cards, collect booking requests, manage dated departures and seat inventory, track request status and notify the site administrator without WooCommerce.

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New Plugin > Upload Plugin.
2. Activate OpenTrip Engine.
3. Create tours under Tours.
4. Use [opentrip_tours] to display tours.
5. Use [opentrip_booking_form] on a single tour page to collect requests.

== Frequently Asked Questions ==

= Does it process payments? =
No. Version 0.1.0 collects booking requests only.

= How do departures and seats work? =
Administrators create dated departures with a capacity. Customers select a departure when submitting a request. Requests reserve seats only after an administrator confirms them; cancelling a confirmed request releases its seats.

= Can I track booking request status? =
Yes. Administrators can mark requests Pending, Confirmed or Cancelled. These are internal statuses only.

= Does it process payments? =
No. Version 0.3.0 manages departure seat inventory but does not collect payments.

= Are pending requests holding seats? =
No. Seats are reserved only when a request is confirmed.

== Changelog ==

= 0.3.0 =
* Add departure management, departure selection on booking forms and atomic seat reservation/release when confirming or cancelling requests.

= 0.2.0 =
* Add booking request status management, admin list columns, admin email notification and frontend styles.

= 0.1.0 =
* Initial MVP: tours, destinations, tour listing and booking requests.
