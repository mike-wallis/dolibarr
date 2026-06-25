/**
 * Pay Run History Tests
 *
 * Verifies the history list and detail view using the existing pay run
 * (period_end 2026-06-28, pay_date 2026-06-29, 3 employees).
 * Read-only — no DB writes.
 */

import { test, expect } from '@playwright/test';

// The one pay run that exists in the DB (from the initial test run)
const KNOWN_RUN = {
  periodEnd:   '28 Jun 2026',
  payDate:     '29 Jun 2026',
  empCount:    3,
  totalGross:  '1,100.00',
  totalNet:    '962.00',
};

test.describe('Pay Run History', () => {
  test('list page loads and shows existing run', async ({ page }) => {
    await page.goto('/custom/payroll/payruns.php?mainmenu=billing&leftmenu=payroll_history');
    await expect(page.locator('h1')).toContainText('Pay Run History');

    // The known run should appear — check by period end date
    await expect(page.getByText(KNOWN_RUN.periodEnd)).toBeVisible();

    // Employee count column should show 3
    const row = page.locator('tr').filter({ hasText: KNOWN_RUN.periodEnd }).first();
    await expect(row).toContainText(String(KNOWN_RUN.empCount));

    // Net pay column should match
    await expect(row).toContainText(KNOWN_RUN.totalNet);
  });

  test('list page has "New pay run" button', async ({ page }) => {
    await page.goto('/custom/payroll/payruns.php?mainmenu=billing&leftmenu=payroll_history');
    await expect(page.locator('a.button').filter({ hasText: 'New pay run' })).toBeVisible();
  });

  test('FY filter shows existing run under 2025-26', async ({ page }) => {
    await page.goto('/custom/payroll/payruns.php?fy=2025-26&mainmenu=billing&leftmenu=payroll_history');
    await expect(page.getByText(KNOWN_RUN.periodEnd)).toBeVisible();
  });

  test('detail view shows employees, totals, and super section', async ({ page }) => {
    // Navigate to detail view — uses query params start/end/pay
    await page.goto(
      '/custom/payroll/payruns.php' +
      '?start=2026-06-22&end=2026-06-28&pay=2026-06-29' +
      '&mainmenu=billing&leftmenu=payroll_history'
    );

    // Green header box with PR reference
    const header = page.locator('div').filter({ hasText: /PR\d{6}/ }).first();
    await expect(header).toBeVisible();
    await expect(header).toContainText('28 Jun 2026');
    await expect(header).toContainText('29 Jun 2026');

    // "Print all payslips" and "Email all payslips" buttons
    await expect(page.getByRole('button', { name: 'Print all payslips' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Email all payslips' })).toBeVisible();

    // Detail table should have 3 employee rows + totals footer
    // Use href to scope to payslip VIEW links — page may also have other "View" links
    const viewButtons = page.locator('a.button[href*="payslip.php"]');
    await expect(viewButtons).toHaveCount(KNOWN_RUN.empCount);

    // Totals row — first() because the page has two tfoot elements
    // (employee totals + super payments due table)
    const tfoot = page.locator('tfoot').first();
    await expect(tfoot).toContainText(KNOWN_RUN.totalGross);
    await expect(tfoot).toContainText(KNOWN_RUN.totalNet);

    // michael wallis is a salary employee — his gross should be $1,000.00
    // Use exact name string (lowercase) — avoid OR regex which matches Daniel/Eloise Wallis too
    const michaelRow = page.locator('tr').filter({ hasText: 'michael wallis' }).first();
    await expect(michaelRow).toContainText('1,000.00');
  });

  test('detail view shows super payments due section', async ({ page }) => {
    await page.goto(
      '/custom/payroll/payruns.php' +
      '?start=2026-06-22&end=2026-06-28&pay=2026-06-29' +
      '&mainmenu=billing&leftmenu=payroll_history'
    );

    await expect(page.locator('h3').filter({ hasText: 'Super payments due' })).toBeVisible();

    // Table should have columns: Employee, Super fund, USI, Member no., Amount, SGC due
    await expect(page.locator('th').filter({ hasText: 'SGC due' })).toBeVisible();
    await expect(page.locator('th').filter({ hasText: 'Member no.' })).toBeVisible();

    // "Print SBSCH list" button
    await expect(page.getByRole('button', { name: 'Print SBSCH list' })).toBeVisible();
  });

  test('← All runs link returns to list', async ({ page }) => {
    await page.goto(
      '/custom/payroll/payruns.php' +
      '?start=2026-06-22&end=2026-06-28&pay=2026-06-29' +
      '&mainmenu=billing&leftmenu=payroll_history'
    );

    await page.locator('a.button').filter({ hasText: '← All runs' }).click();
    await expect(page).toHaveURL(/payruns\.php/);
    await expect(page.locator('h1')).toContainText('Pay Run History');
    // Should be back on list (no start/end/pay params → no detail view)
    await expect(page.getByText(KNOWN_RUN.periodEnd)).toBeVisible();
  });
});
