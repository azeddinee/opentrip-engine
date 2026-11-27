# Read-only REST API quick start

Use the public catalogue from a headless frontend without a plugin API key. Replace the example domain with your WordPress site's REST base URL and replace tour ID 123 with a published tour ID returned by the list endpoint.

## cURL examples

Set the base URL in a POSIX shell (Linux/macOS or Git Bash):

```sh
BASE="https://example.com/wp-json/opentrip-engine/v1"
curl -fsS "$BASE/tours?page=1&per_page=10"
curl -fsS "$BASE/tours?destination=morocco&page=1&per_page=10"
curl -fsS "$BASE/tours/123"
curl -fsS "$BASE/destinations"
curl -fsS "$BASE/tours/123/departures"
```

The destination filter takes a slug, not the destination's display name. Get available slugs from the destinations endpoint. To see pagination headers together with the response body, use:

```sh
curl -fsS -i "$BASE/tours?page=1&per_page=10"
```

The list response is a JSON array. Read `X-WP-Total`, `X-WP-TotalPages` and `X-OTE-Page` from the headers. Pages start at 1; `per_page` must be between 1 and 50. An empty array is a valid result, not an error.

## JavaScript examples

Run this module in a browser page served from the WordPress site's origin, or another origin whose access is allowed by the site's CORS configuration. Cross-origin requests may also need the pagination headers to be explicitly exposed. Replace the base URL as above.

```js
const base = "https://example.com/wp-json/opentrip-engine/v1";

async function get(path, params = {}) {
  const url = new URL(base + path);
  url.search = new URLSearchParams(params).toString();
  const response = await fetch(url);
  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    throw new Error(error.message || `HTTP ${response.status}`);
  }
  return { data: await response.json(), headers: response.headers };
}

async function showCatalogue() {
  const { data: tours, headers } = await get("/tours", {
    page: 1, per_page: 10
  });
  const { data: destinations } = await get("/destinations");
  console.log("Total:", headers.get("X-WP-Total"));
  console.log("Pages:", headers.get("X-WP-TotalPages"));
  console.log("Current page:", headers.get("X-OTE-Page"));

  const list = document.createElement("ul");
  for (const tour of tours) {
    const item = document.createElement("li");
    item.textContent = `${tour.title}: ${tour.price} ${tour.currency}`;
    list.append(item);
  }
  if (!tours.length) {
    const item = document.createElement("li");
    item.textContent = "No tours found.";
    list.append(item);
  }
  document.body.append(list);

  if (destinations.length) {
    const { data: filtered } = await get("/tours", {
      destination: destinations[0].slug, page: 1, per_page: 10
    });
    console.log("Tours in destination:", filtered);
  }
  if (tours.length) {
    const id = tours[0].id;
    const { data: tour } = await get("/tours/" + id);
    const { data: departures } = await get("/tours/" + id + "/departures");
    console.log(tour.title, departures.map(d => ({
      start: d.start_date, seats: d.seats_remaining,
      adultPrice: d.adult_price, currency: d.currency
    })));
  }
}

showCatalogue().catch(error => {
  const message = document.createElement("p");
  message.textContent = error.message;
  document.body.append(message);
});
```

For another page, call `get("/tours", { page: 2, per_page: 10 })` if there is a second page. Keep the destination filter when paging through filtered results. If pagination headers are unavailable, stop when the next page returns an empty array.

## Handling errors and availability

- A 400 response can indicate an invalid parameter, such as `per_page=51`. Correct the request before retrying.
- A 404 response from a single-tour or departures route means the tour is missing or is not publicly available.
- A 500 response or a failed network request should show a useful error rather than an empty catalogue.
- A successful departures response can be an empty array. Show "No available departures" instead of treating it as an error.

The tour's `price` is its catalogue price. Departure `adult_price` is the configured effective adult price, and the departure's `currency` is returned without conversion. Availability and rates may change after any request; this API does not hold seats or confirm bookings.

See the [REST API reference](REST-API.md) for the full response fields and endpoint contract.
