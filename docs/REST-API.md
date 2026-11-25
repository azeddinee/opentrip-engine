# OpenTrip Engine REST API

OpenTrip Engine exposes a versioned, read-only REST API for public tour catalogue data. It is intended for headless websites, mobile apps and third-party integrations.

## Base URL

`https://example.com/wp-json/opentrip-engine/v1`

The API uses the site's WordPress permalink and REST configuration. Replace `example.com` with the WordPress site's domain.

## Public endpoints

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/tours` | List published tours |
| GET | `/tours/{id}` | Retrieve one published tour |
| GET | `/tours/{id}/departures` | List currently available departures for a published tour |
| GET | `/destinations` | List destination terms used by tours |

All endpoints are read-only and publicly accessible. No booking, customer, review-author, admin, or payment data is exposed by this API.

## List tours

```bash
curl "https://example.com/wp-json/opentrip-engine/v1/tours?page=1&per_page=10"
```

Optional query parameters:

- `page`: 1-based page number; defaults to `1`.
- `per_page`: number of tours per page; defaults to `10`, maximum `50`.
- `destination`: destination taxonomy slug, for example `morocco`.

Pagination metadata is returned in the `X-WP-Total` and `X-WP-TotalPages` response headers. `X-OTE-Page` reports the requested page.

## Retrieve a tour

```bash
curl "https://example.com/wp-json/opentrip-engine/v1/tours/123"
```

A tour response contains only deliberately selected public fields:

- `id`, `slug`, `title`, `link`, `excerpt`, `content`
- `price`, `currency`, `duration`
- `featured_image` and `gallery` image IDs, URLs and alternative text
- `destinations`
- `itinerary` with day, title, city, activities, overnight, meals and transport
- `inclusions` and `exclusions`

Draft, private, missing and password-protected tours return HTTP `404`. The API does not serialize arbitrary post meta.

## Available departures

```bash
curl "https://example.com/wp-json/opentrip-engine/v1/tours/123/departures"
```

Only future departures that are open and have remaining capacity are returned. Each item includes the departure ID, tour ID, start/end dates, seats remaining, price and currency. Closed, full and past departures are omitted.

This endpoint reports inventory at the time of the request; it is not a seat hold or booking confirmation. Availability can change immediately after the response.

## Destinations

```bash
curl "https://example.com/wp-json/opentrip-engine/v1/destinations"
```

Only non-empty destination terms are returned. Each item includes its ID, name, slug, description, published-tour count and archive link.

## Errors

- `400`: invalid request parameter.
- `404`: tour does not exist or is not publicly available.
- `500`: unexpected server-side error.

WordPress REST API error responses include a machine-readable `code`, human-readable `message` and optional `data.status`.

## Security and privacy boundaries

- Routes use explicit public permission callbacks because they expose read-only catalogue information only.
- Only published, non-password-protected tours can be retrieved.
- Booking records, customer names/emails, booking references, internal booking statuses, private review metadata, settings and payment information are not included.
- The API does not create bookings, reserve seats, confirm departures, process payments or modify WordPress content.
- Do not add customer or booking fields to public responses. Any future write endpoint must be designed separately with authentication, capability checks, nonces/application passwords as appropriate, rate limiting, validation and privacy review.
- Public tour content is rendered through the standard WordPress content filter. Site administrators should review public tour content and shortcode output before publishing.

## Versioning

The namespace is versioned as `opentrip-engine/v1`. Breaking response changes should be introduced in a new namespace rather than silently changing v1.

## Headless frontend example

A frontend can fetch the catalogue using the standard WordPress REST API:

```js
const response = await fetch(
  "https://example.com/wp-json/opentrip-engine/v1/tours?per_page=12"
);

if (!response.ok) {
  throw new Error(`OpenTrip Engine API error: ${response.status}`);
}

const tours = await response.json();
```

No API key is required for these public read-only endpoints.
