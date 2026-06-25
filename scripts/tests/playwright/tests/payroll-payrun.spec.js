/**
 * Pay Run Tests
 *
 * 3a. Form loads correctly (read-only)
 * 3b. Duplicate prevention — trying to re-run period_end 2026-06-28 is blocked
 * 3c. End-to-end run — one test that processes, checks results, history, payslip, then cleans up
 *
 * Employee IDs from DB:
 *   rowid 2 = Eloise Wallis  — weekly casual  $25/hr
 *   rowid 3 = Daniel Wallis  — weekly casual  $25/hr
 *   rowid 5 = michael wallis — weekly FT      $1000/period salary
 *   rowid 6 = test dummy     — fortnightly FT $35/hr
 */

import { test, expect } from '@playwright/test';
import { deleteTestPayrun, query } from '../helpers/db.js';

// Period used for the end-to-end test — must not exist in DB before the test runs
const TEST_PERIOD = {
  start:   '2026-07-14',
  end:     '2026-07-20',
  pay:     '2026-07-21',
  endFmt:  '20 Jul 2026',
  payFmt:  '21 Jul 2026',
};

// Period already in DB (duplicate test)
const EXISTING_PERIOD_END = '2026-06-28';

test.describe('Pay Run Form (3a — read-only)', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/custom/payroll/payrun.php?mainmenu=billing&leftmenu=payroll_run');
    await expect(page.locator('h1')).toContainText('Pay Run');
  });

  test('bank account select is present and contains Macquarie', async ({ page }) => {
    const bankSelect = page.locator('select[name="accountid"]');
    await expect(bankSelect).toBeVisible();
    await expect(bankSelect.locator('option').filter({ hasText: /Macquarie/ })).toHaveCount(1);
  });

  test('financial year select is present', async ({ page }) => {
    await expect(page.locator('select[name="fy"]')).toBeVisible();
  });

  test('weekly period date inputs are present', async ({ page }) => {
    await expect(page.locator('input[name="datesp_weekly"]')).toBeVisible();
    await expect(page.locator('input[name="dateep_weekly"]')).toBeVisible();
    await expect(page.locator('input[name="datep_weekly"]')).toBeVisible();
  });

  test('fortnightly period date inputs are present', async ({ page }) => {
    await expect(page.locator('input[name="datesp_fortnightly"]')).toBeVisible();
    await expect(page.locator('input[name="dateep_fortnightly"]')).toBeVisible();
    await expect(page.locator('input[name="datep_fortnightly"]')).toBeVisible();
  });

  test('includes employee checkbox and hours input for Eloise Wallis (rowid 2)', async ({ page }) => {
    await expect(page.locator('input[name="incl_2"]')).toBeVisible();
    await expect(page.locator('input[name="ord_hrs_2"]')).toBeVisible();
    await expect(page.locator('input[name="rate_2"]')).toBeVisible();
  });

  test('includes salary input for michael.wallis (rowid 5)', async ({ page }) => {
    await expect(page.locator('input[name="incl_5"]')).toBeVisible();
    await expect(page.locator('input[name="salary_5"]')).toBeVisible();
  });

  test('"Process Weekly →" button is present', async ({ page }) => {
    await expect(page.locator('button[name="process_pt"][value="weekly"]')).toBeVisible();
  });

  test('auto-suggested dates are pre-filled (week after last run)', async ({ page }) => {
    // Last weekly run ended 2026-06-28 → next period should start 2026-06-29
    const startInput = page.locator('input[name="datesp_weekly"]');
    const endInput   = page.locator('input[name="dateep_weekly"]');
    await expect(startInput).toHaveValue('2026-06-29');
    await expect(endInput).toHaveValue('2026-07-05');
  });
});

test.describe('Duplicate Prevention (3b)', () => {
  test('blocks re-processing a period that is already in DB', async ({ page }) => {
    await page.goto('/custom/payroll/payrun.php?mainmenu=billing&leftmenu=payroll_run');

    // Set period dates to the existing run's period
    await page.fill('input[name="datesp_weekly"]', '2026-06-22');
    await page.fill('input[name="dateep_weekly"]', EXISTING_PERIOD_END);
    await page.fill('input[name="datep_weekly"]',  '2026-06-29');

    // Include Eloise — make sure her checkbox is checked (may already be)
    await page.check('input[name="incl_2"]');
    await page.fill('input[name="ord_hrs_2"]', '8');

    // Submit weekly section
    await page.click('button[name="process_pt"][value="weekly"]');

    // Should stay on the pay run page and show an error
    await expect(page.locator('.alert-danger')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('.alert-danger')).toContainText(/already has a pay run|period ending/i);
  });
});

test.describe('End-to-End Pay Run (3c)', () => {
  // Single combined test — avoids cross-test state issues with DB cleanup timing.
  // Uses test.step() for clear sub-assertion labels.
  test('full pay run flow: process → results → history → payslip → cleanup', async ({ page }) => {
    // Pre-cleanup: remove any leftover test data from a previous run
    deleteTestPayrun(TEST_PERIOD.end);

    try {
      // ── Step 1: Fill and submit the pay run form ──────────────────────────
      await page.goto('/custom/payroll/payrun.php?mainmenu=billing&leftmenu=payroll_run');

      await test.step('fill weekly period dates', async () => {
        await page.fill('input[name="datesp_weekly"]', TEST_PERIOD.start);
        await page.fill('input[name="dateep_weekly"]', TEST_PERIOD.end);
        await page.fill('input[name="datep_weekly"]',  TEST_PERIOD.pay);
      });

      await test.step('include only Eloise and michael (uncheck Daniel)', async () => {
        // Uncheck Daniel — the form pre-checks all employees
        await page.uncheck('input[name="incl_3"]');
        // Ensure Eloise and michael are checked
        await page.check('input[name="incl_2"]');
        await page.check('input[name="incl_5"]');
        // Eloise: 10 hours × $25 = $250 gross
        await page.fill('input[name="ord_hrs_2"]', '10');
        // michael: salary (pre-filled with 1000)
        await page.fill('input[name="salary_5"]', '1000');
      });

      await page.click('button[name="process_pt"][value="weekly"]');

      // ── Step 2: Verify results page ───────────────────────────────────────
      await test.step('results page shows pay run complete', async () => {
        const successBox = page.locator('div').filter({ hasText: /Pay run complete/ }).first();
        await expect(successBox).toBeVisible({ timeout: 15_000 });
        await expect(successBox).toContainText(TEST_PERIOD.endFmt);
        await expect(successBox).toContainText(TEST_PERIOD.payFmt);
        await expect(successBox).toContainText(/PR\d{6}/);
      });

      await test.step('results table has exactly 2 employee rows', async () => {
        // Use href to scope to payslip VIEW links only — page also has "View all salaries" links
        const viewButtons = page.locator('a.button[href*="payslip.php"]');
        await expect(viewButtons).toHaveCount(2);
      });

      await test.step('Eloise gross is $250', async () => {
        const eloisRow = page.locator('tr').filter({ hasText: 'Eloise Wallis' }).first();
        await expect(eloisRow).toContainText('250.00');
      });

      await test.step('michael gross is $1,000', async () => {
        const michaelRow = page.locator('tr').filter({ hasText: 'michael wallis' }).first();
        await expect(michaelRow).toContainText('1,000.00');
      });

      await test.step('journal entries section is present', async () => {
        await expect(page.locator('h3').filter({ hasText: 'Journal entries' })).toBeVisible();
      });

      // ── Step 3: Verify DB records ─────────────────────────────────────────
      await test.step('DB has 2 payrun_line rows for the test period', async () => {
        const dbRows = query(
          `SELECT COUNT(*) AS cnt FROM llx_payroll_payrun_line WHERE pay_period_end = '${TEST_PERIOD.end}'`
        );
        expect(parseInt(dbRows[0].cnt)).toBe(2);
      });

      await test.step("Eloise's gross stored correctly in DB", async () => {
        const eloisDb = query(
          `SELECT gross FROM llx_payroll_payrun_line WHERE fk_user = 2 AND pay_period_end = '${TEST_PERIOD.end}'`
        );
        expect(eloisDb.length).toBe(1);
        expect(parseFloat(eloisDb[0].gross)).toBe(250.00);
      });

      // ── Step 4: History page shows the new run ────────────────────────────
      await test.step('new run appears in Pay Run History', async () => {
        await page.goto('/custom/payroll/payruns.php?mainmenu=billing&leftmenu=payroll_history');
        await expect(page.getByText(TEST_PERIOD.endFmt)).toBeVisible({ timeout: 10_000 });
      });

      // ── Step 5: Payslip page loads ────────────────────────────────────────
      await test.step("Eloise's payslip page loads", async () => {
        const rows = query(
          `SELECT rowid FROM llx_payroll_payrun_line WHERE fk_user = 2 AND pay_period_end = '${TEST_PERIOD.end}'`
        );
        expect(rows.length).toBe(1);
        const id = rows[0].rowid;
        await page.goto(`/custom/payroll/payslip.php?id=${id}&mainmenu=billing`);
        await expect(page.getByText(/eloise/i).first()).toBeVisible();
      });

    } finally {
      // ── Cleanup: always remove test pay run records ───────────────────────
      deleteTestPayrun(TEST_PERIOD.end);
    }
  });
});
