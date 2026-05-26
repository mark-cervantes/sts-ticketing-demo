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

test('kanban header edit-mode toggle is compact icon-only button', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('demo@example.com');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL('**/dashboard');
    await expect(page.locator('.kanban-column h3').first()).toBeVisible({ timeout: 15000 });

    // The edit-mode toggle should exist as an icon button (aria-label, no visible text label)
    const editBtn = page.getByRole('button', { name: /edit columns/i });
    await expect(editBtn).toBeVisible();

    // Clicking it should not show any "Edit Mode" badge — just change the icon/aria state
    await editBtn.click();
    await expect(page.getByRole('button', { name: /lock columns/i })).toBeVisible();
    // The "Edit Mode" badge text must NOT appear on screen
    await expect(page.getByText('Edit Mode', { exact: true })).toHaveCount(0);

    await page.screenshot({ path: `${smokeArtifactDirectory}/kanban-edit-mode-compact.png`, fullPage: true });

    // Toggle back to locked state
    const lockBtn = page.getByRole('button', { name: /lock columns/i });
    await lockBtn.click();
    await expect(page.getByRole('button', { name: /edit columns/i })).toBeVisible();

    await page.screenshot({ path: `${smokeArtifactDirectory}/kanban-locked-compact.png`, fullPage: true });
});

test('follow-up ticket card is a clickable button affordance', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('demo@example.com');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL('**/dashboard');
    await expect(page.locator('.kanban-column h3').first()).toBeVisible({ timeout: 15000 });

    // Open the first issue card if any exist
    const firstCard = page.locator('.kanban-column [data-issue-id]').first();
    const cardCount = await firstCard.count();
    if (cardCount === 0) {
        // No issues on this board — skip the interaction assertion
        test.skip();
        return;
    }

    await firstCard.click();

    // Wait for the sheet to open
    await expect(page.locator('[role="dialog"]')).toBeVisible({ timeout: 10000 });
    await page.screenshot({ path: `${smokeArtifactDirectory}/issue-detail-sheet.png`, fullPage: true });

    // If a follow-up ticket suggestion is present, verify it renders as a button
    const followUpCard = page.getByTitle(/create this follow-up ticket/i);
    const followUpCount = await followUpCard.count();
    if (followUpCount > 0) {
        await expect(followUpCard).toBeVisible();
        // It must be a <button> element (not a plain <div>)
        const tagName = await followUpCard.evaluate(el => el.tagName.toLowerCase());
        expect(tagName).toBe('button');
        await page.screenshot({ path: `${smokeArtifactDirectory}/follow-up-ticket-card.png`, fullPage: true });
    }
});
