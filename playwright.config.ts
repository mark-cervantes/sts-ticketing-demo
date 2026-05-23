import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Playwright',
    timeout: 30_000,
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: 1,
    outputDir: 'test-results/playwright',
    reporter: 'list',
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost',
        headless: true,
        trace: 'on-first-retry',
        screenshot: {
            mode: 'only-on-failure',
            fullPage: true,
        },
        video: 'off',
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                browserName: 'chromium',
            },
        },
    ],
});
