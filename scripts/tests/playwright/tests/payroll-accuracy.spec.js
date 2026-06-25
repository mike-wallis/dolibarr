/**
 * PAYG Calculation Accuracy Tests
 *
 * The Payroll Setup page runs PaygCalculator against known ATO sample values
 * and renders a pass/fail table. These tests confirm all checks are green.
 * Read-only — no DB writes.
 */

import { test, expect } from '@playwright/test';

test.describe('PAYG Calculation Accuracy', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/custom/payroll/setup.php?mainmenu=billing&leftmenu=payroll_setup');
    await expect(page.locator('h1, h2').filter({ hasText: 'Payroll Setup' })).toBeVisible();
  });

  test('2025-26 — all test cases pass', async ({ page }) => {
    // The 2025-26 section should show a green "All X tests passed" badge
    const badge = page.locator('span').filter({ hasText: /✓\s+All \d+ tests passed/ }).first();
    await expect(badge).toBeVisible({ timeout: 10_000 });

    // No failure rows anywhere in the page
    const failCells = page.locator('td').filter({ hasText: '✗' });
    await expect(failCells).toHaveCount(0);
  });

  test('2026-27 — all test cases pass', async ({ page }) => {
    // Scroll to 2026-27 section
    await page.locator('h2').filter({ hasText: '2026-27' }).scrollIntoViewIfNeeded();

    // Look for any "✓ All X tests passed" badge — should be at least one (2026-27)
    const badges = page.locator('span').filter({ hasText: /✓\s+All \d+ tests passed/ });
    await expect(badges).not.toHaveCount(0);

    // No failure rows
    const failCells = page.locator('td').filter({ hasText: '✗' });
    await expect(failCells).toHaveCount(0);
  });

  test('2026-27 Scale 2 weekly $865 → $94 PAYG', async ({ page }) => {
    // The 2026-27 section uses ATO-format labels ("S2 $865/weekly") imported from the ATO sample
    // data CSV, while the 2025-26 section uses built-in labels ("Scale 2 — $865/wk").
    // "S2 $865" is unique to the 2026-27 table — no table scoping needed.
    const row = page.locator('tr').filter({ hasText: 'S2 $865' }).first();
    await expect(row).toBeVisible();

    // Result column is td[4] (0-indexed): label, period, expected, actual, result, source
    const resultCell = row.locator('td').nth(4);
    await expect(resultCell).toContainText('✓');

    // Actual PAYG column (td[3]) should be $94
    const actualCell = row.locator('td').nth(3);
    await expect(actualCell).toContainText('94');
  });

  test('2026-27 Scale 6 weekly $931 → $98 PAYG', async ({ page }) => {
    // ATO-format label "S6 $931/weekly" is unique to the 2026-27 imported table
    const row = page.locator('tr').filter({ hasText: 'S6 $931' }).first();
    await expect(row).toBeVisible();
    await expect(row.locator('td').nth(4)).toContainText('✓');
  });

  test('2026-27 Scale 2 fortnightly $1,728 → $188 PAYG', async ({ page }) => {
    // ATO-format label "S2 $1728/fortnightly" — no comma in imported label
    const row = page.locator('tr').filter({ hasText: 'S2 $1728' }).first();
    await expect(row).toBeVisible();
    await expect(row.locator('td').nth(4)).toContainText('✓');
  });
});
