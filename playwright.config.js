const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
	testDir: './tests/e2e',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: 'list',
	timeout: 30000,
	expect: {
		timeout: 10000,
	},
	use: {
		baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080',
		browserName: 'chromium',
		headless: true,
		trace: 'retain-on-failure',
	},
});
