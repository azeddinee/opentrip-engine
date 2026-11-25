const { test, expect } = require('@playwright/test');

const bookingUrl = process.env.E2E_BOOKING_URL;
const reviewUrl = process.env.E2E_REVIEW_URL;

test.describe('public booking and review shortcodes', () => {
	test('booking form exposes accessible labels and submits a real request', async ({ page }) => {
		await page.goto(bookingUrl);

		await expect(page.getByRole('heading', { name: 'Request a booking' })).toBeVisible();
		await expect(page.getByRole('group', { name: 'Travellers' })).toBeVisible();
		await expect(page.getByLabel('Full name')).toBeVisible();
		await expect(page.getByLabel('Email address')).toBeVisible();
		await expect(page.getByLabel('Child ages (comma-separated)')).toBeVisible();

		await page.getByLabel('Full name').fill('Browser Test Traveller');
		await page.getByLabel('Email address').fill('browser-booking@example.test');
		await page.getByLabel('Departure date').selectOption({ index: 1 });
		await page.getByLabel('Adults').fill('2');
		await page.getByLabel('Children').fill('1');
		await page.getByLabel('Child ages (comma-separated)').fill('8');
		await page.getByLabel('Infants').fill('0');
		await page.getByLabel('Infant ages (comma-separated)').fill('');
		await page.getByLabel('Rooms required').fill('1');
		await page.getByLabel('Message (optional)').fill('Created by the browser end-to-end suite.');

		await page.getByRole('button', { name: 'Send booking request' }).click();

		await expect(page.getByRole('status')).toContainText('Your booking request was received.');
		await expect(page.locator('.ote-booking-reference')).toContainText('OTE-');
	});

	test('invalid child age is announced and does not create a booking', async ({ page }) => {
		await page.goto(bookingUrl);

		await page.getByLabel('Full name').fill('Invalid Age Traveller');
		await page.getByLabel('Email address').fill('invalid-age@example.test');
		await page.getByLabel('Departure date').selectOption({ index: 1 });
		await page.getByLabel('Adults').fill('1');
		await page.getByLabel('Children').fill('1');
		await page.getByLabel('Child ages (comma-separated)').fill('12');
		await page.getByLabel('Infants').fill('0');
		await page.getByLabel('Infant ages (comma-separated)').fill('');
		await page.getByLabel('Rooms required').fill('1');

		await page.getByRole('button', { name: 'Send booking request' }).click();

		await expect(page.getByRole('alert')).toContainText('A child age does not match any configured child age band.');
	});

	test('review form accepts a review and announces moderation status', async ({ page }) => {
		await page.goto(reviewUrl);

		await expect(page.getByRole('heading', { name: 'Write a guest review' })).toBeVisible();
		await page.getByLabel('Your name').fill('Browser Review Guest');
		await page.getByLabel('Email (not displayed publicly)').fill('browser-review@example.test');
		await page.getByLabel('Rating').selectOption('5');
		await page.getByLabel('Your review').fill('The tour details and booking process were clear.');

		await page.getByRole('button', { name: 'Submit review' }).click();

		await expect(page.getByRole('status')).toContainText('awaiting moderation');
	});
});
