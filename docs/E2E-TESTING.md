# Browser end-to-end tests

The Playwright suite runs against a disposable, real WordPress site with the plugin activated. It covers public booking and review submissions through a Chromium browser; it does not connect to a production site or payment provider.

## Requirements

- PHP 8.3 with mysqli, mbstring, XML and ZIP extensions.
- MySQL 8.0 and WP-CLI PHAR.
- Node.js 20 and npm.
- Chromium (installed by Playwright).

## Local setup

1. Start a local MySQL 8.0 database named wordpress_e2e. The defaults used by the setup script are user root, password root and host 127.0.0.1:3306.
2. Download WP-CLI to /tmp/wp-cli.phar.
3. Run the disposable site setup from the repository root:

    WP_CLI_PHAR=/tmp/wp-cli.phar sh scripts/setup-e2e-wordpress.sh

4. Start the PHP development server and export the generated page URLs:

    php -S 127.0.0.1:8080 -t /tmp/ote-e2e-wordpress
    export E2E_BASE_URL=http://127.0.0.1:8080
    export E2E_BOOKING_URL=http://127.0.0.1:8080/?page_id=$(cat /tmp/ote-e2e-booking-page-id)
    export E2E_REVIEW_URL=http://127.0.0.1:8080/?page_id=$(cat /tmp/ote-e2e-review-page-id)

5. In another terminal, install the pinned test runner, install Chromium and run the tests:

    npm install --no-save --package-lock=false
    npx playwright install chromium
    npm run test:e2e

The setup script recreates its disposable WordPress directory each time. Do not point it at a real site or production database.

## CI behavior

GitHub Actions starts a temporary MySQL service, downloads WordPress, activates the plugin, seeds one published tour and one future departure, and creates pages containing the booking and review shortcodes. It starts PHP's built-in server and runs Playwright with one worker to keep submissions deterministic.

The suite verifies:

- Accessible booking labels, traveller fieldset and visible booking form.
- A valid booking request redirects back with a status announcement and booking reference.
- An invalid child age is announced in a role=alert region.
- A valid review request is saved as pending and announces moderation status.
- Exactly one booking and one pending review are persisted; the invalid booking does not create a record.

No runtime dependency, telemetry, payment integration or production credential is added to the plugin. The browser runner is a development-only dependency pinned in package.json.

## Known scope

The suite uses Chromium only. It does not replace manual keyboard and screen-reader testing or cross-browser testing. Use the checklist in docs/ACCESSIBILITY-AND-TRANSLATIONS.md before a release.