#!/usr/bin/env sh
set -eu

WP_CLI_PHAR=${WP_CLI_PHAR:-/tmp/wp-cli.phar}
WP_PATH=${WP_PATH:-/tmp/ote-e2e-wordpress}
WP_URL=${WP_URL:-http://127.0.0.1:8080}
DB_NAME=${DB_NAME:-wordpress_e2e}
DB_USER=${DB_USER:-root}
DB_PASSWORD=${DB_PASSWORD:-root}
DB_HOST=${DB_HOST:-127.0.0.1:3306}

if [ ! -f "$WP_CLI_PHAR" ]; then
  echo "WP-CLI PHAR not found at $WP_CLI_PHAR" >&2
  exit 1
fi

rm -rf "$WP_PATH"
mkdir -p "$WP_PATH"
php "$WP_CLI_PHAR" core download --path="$WP_PATH" --force
php "$WP_CLI_PHAR" config create --path="$WP_PATH" --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASSWORD" --dbhost="$DB_HOST" --skip-check --skip-salts
php "$WP_CLI_PHAR" core install --path="$WP_PATH" --url="$WP_URL" --title="OpenTrip E2E" --admin_user="e2e-admin" --admin_password="e2e-test-password" --admin_email="e2e@example.test" --skip-email

PLUGIN_PATH="$WP_PATH/wp-content/plugins/opentrip-engine"
mkdir -p "$PLUGIN_PATH"
cp -R opentrip-engine.php includes assets languages readme.txt LICENSE README.md SECURITY.md "$PLUGIN_PATH/"
php "$WP_CLI_PHAR" --path="$WP_PATH" plugin activate opentrip-engine

TOUR_ID=$(php "$WP_CLI_PHAR" --path="$WP_PATH" post create --post_type=ote_tour --post_status=publish --post_title="E2E Test Tour" --post_content="A public tour used by automated browser tests." --porcelain)
export TOUR_ID
php "$WP_CLI_PHAR" --path="$WP_PATH" eval '
global $wpdb;
$tour_id = (int) getenv( "TOUR_ID" );
$start = gmdate( "Y-m-d", strtotime( "+14 days" ) );
$end = gmdate( "Y-m-d", strtotime( "+15 days" ) );
$inserted = $wpdb->insert(
  $wpdb->prefix . "ote_departures",
  array(
    "tour_id" => $tour_id,
    "start_date" => $start,
    "end_date" => $end,
    "capacity" => 8,
    "reserved" => 0,
    "price" => 120,
    "currency" => "USD",
    "status" => "open",
    "created_at" => current_time( "mysql", true ),
  )
);
if ( false === $inserted ) {
  fwrite( STDERR, "Could not seed E2E departure: " . $wpdb->last_error . PHP_EOL );
  exit( 1 );
}
'

BOOKING_PAGE_ID=$(php "$WP_CLI_PHAR" --path="$WP_PATH" post create --post_type=page --post_status=publish --post_title="E2E Booking" --post_name="e2e-booking" --post_content="[opentrip_booking_form tour_id=\"$TOUR_ID\"]" --porcelain)
REVIEW_PAGE_ID=$(php "$WP_CLI_PHAR" --path="$WP_PATH" post create --post_type=page --post_status=publish --post_title="E2E Review" --post_name="e2e-review" --post_content="[opentrip_review_form tour_id=\"$TOUR_ID\"]" --porcelain)
printf '%s\n' "$BOOKING_PAGE_ID" > /tmp/ote-e2e-booking-page-id
printf '%s\n' "$REVIEW_PAGE_ID" > /tmp/ote-e2e-review-page-id
