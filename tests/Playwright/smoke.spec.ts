import { expect, test } from '@playwright/test';
import { mkdirSync } from 'node:fs';

const smokeArtifactDirectory = 'test-results/playwright/smoke';

test.beforeAll((): void => {
    mkdirSync(smokeArtifactDirectory, { recursive: true });
});

test('auth and operator pages render without browser errors', async ({ page }) => {
    const browserErrors: string[] = [];

    page.on('console', message => {
        if (message.type() === 'error') {
            browserErrors.push(`CONSOLE: ${message.text()}`);
        }
    });

    page.on('pageerror', error => {
        browserErrors.push(`PAGEERROR: ${error.message}`);
    });

    await page.goto('/login');
    await expect(page).toHaveTitle(/Sign in/i);
    await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible();

    await page.goto('/register');
    await expect(page).toHaveTitle(/Register/i);
    await expect(page.getByRole('button', { name: 'Create account' })).toBeVisible();

    await page.goto('/login');
    await page.getByLabel('Email').fill('demo@example.com');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();

    await page.waitForURL('**/dashboard');
    // Kanban board renders columns dynamically from /api/statuses — wait for at least one column heading
    await expect(page.locator('.kanban-column h3').first()).toBeVisible({ timeout: 15000 });
    // Verify at least 3 columns are rendered (default: Open, In Progress, Resolved)
    await expect(page.locator('.kanban-column')).toHaveCount(3, { timeout: 15000 });
    await page.screenshot({ path: `${smokeArtifactDirectory}/dashboard.png`, fullPage: true });

    await page.goto('/profile');
    await expect(page.getByRole('heading', { name: 'Profile', exact: true })).toBeVisible();
    await page.screenshot({ path: `${smokeArtifactDirectory}/profile.png`, fullPage: true });

    await page.goto('/horizon');
    await page.waitForURL('**/horizon/**');
    await expect(page).toHaveTitle(/Horizon/i);
    await page.screenshot({ path: `${smokeArtifactDirectory}/horizon.png`, fullPage: true });

    expect(browserErrors, `Browser errors detected:\n${browserErrors.join('\n')}`).toEqual([]);
});
