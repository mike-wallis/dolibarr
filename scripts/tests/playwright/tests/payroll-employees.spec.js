/**
 * Payroll Employees Tests
 *
 * Verifies the employee list page and the payroll profile page
 * for a known salary employee (michael.wallis, rowid=5).
 * Read-only — no DB writes.
 */

import { test, expect } from '@playwright/test';

// Known employees from DB
const EMPLOYEES = [
  { name: /eloise\s+wallis/i,  period: 'Weekly',       rate: '25.00',    type: 'Casual'    },
  { name: /daniel\s+wallis/i,  period: 'Weekly',       rate: '25.00',    type: 'Casual'    },
  { name: /michael\s+wallis/i, period: 'Weekly',       rate: '1,000.00', type: 'Full Time' },
  { name: /test\s+dummy/i,     period: 'Fortnightly',  rate: '35.00',    type: 'Full Time' },
];

const MICHAEL_ID = 5;

test.describe('Payroll Employees List', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/custom/payroll/employees.php?mainmenu=billing&leftmenu=payroll_employees');
    await expect(page.locator('h1')).toContainText('Payroll Employees');
  });

  test('shows all four employees', async ({ page }) => {
    for (const emp of EMPLOYEES) {
      await expect(page.locator('td').filter({ hasText: emp.name }).first()).toBeVisible();
    }
  });

  test('shows correct pay period for each employee', async ({ page }) => {
    for (const emp of EMPLOYEES) {
      const row = page.locator('tr').filter({ has: page.locator('td').filter({ hasText: emp.name }) });
      await expect(row.first()).toContainText(emp.period);
    }
  });

  test('shows correct rate for each employee', async ({ page }) => {
    for (const emp of EMPLOYEES) {
      const row = page.locator('tr').filter({ has: page.locator('td').filter({ hasText: emp.name }) });
      await expect(row.first()).toContainText(emp.rate);
    }
  });

  test('all employees have Edit/Set up payroll profile button', async ({ page }) => {
    const buttons = page.locator('a.button').filter({ hasText: /payroll profile/ });
    await expect(buttons).toHaveCount(EMPLOYEES.length);
  });

  test('"Add New Employee" panel toggles', async ({ page }) => {
    const panel = page.locator('#new-emp-panel');
    await expect(panel).not.toBeVisible();
    await page.click('#btn-add-emp');
    await expect(panel).toBeVisible();
    await page.click('button[onclick="toggleNewEmp()"]');
    await expect(panel).not.toBeVisible();
  });

  test('"Go to Pay Run" and "Payroll Setup" quick links are present', async ({ page }) => {
    await expect(page.locator('a.button').filter({ hasText: 'Go to Pay Run' })).toBeVisible();
    await expect(page.locator('a.button').filter({ hasText: 'Payroll Setup' })).toBeVisible();
  });
});

test.describe('Payroll Profile — michael.wallis (salary employee)', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(
      `/custom/payroll/employee_payroll.php?userid=${MICHAEL_ID}&mainmenu=billing&leftmenu=payroll_employees`
    );
  });

  test('page loads with correct employee heading', async ({ page }) => {
    // Page should mention the employee's name
    await expect(page.locator('h1, h2, h3').filter({ hasText: /michael|wallis/i }).first()).toBeVisible();
  });

  test('pay rate is 1000 and period is weekly', async ({ page }) => {
    // DB stores rate as decimal (1000.0000) — match numerically
    const rateInput = page.locator('input[name="pay_rate"]');
    await expect(rateInput).toHaveValue(/^1000/);

    const periodSelect = page.locator('select[name="pay_period"]');
    await expect(periodSelect).toHaveValue('weekly');
  });

  test('pay rate type is salary', async ({ page }) => {
    const typeSelect = page.locator('select[name="pay_rate_type"]');
    await expect(typeSelect).toHaveValue('salary');
  });

  test('tax scale is scale2', async ({ page }) => {
    const scaleSelect = page.locator('select[name="tax_scale"]');
    await expect(scaleSelect).toHaveValue('scale2');
  });

  test('superannuation section is visible', async ({ page }) => {
    await expect(page.locator('h2, h3').filter({ hasText: /super/i }).first()).toBeVisible();
    await expect(page.locator('input[name="super_fund_name"]')).toBeVisible();
    await expect(page.locator('input[name="super_fund_usi"]')).toBeVisible();
    await expect(page.locator('input[name="super_member_number"]')).toBeVisible();
  });

  test('bank account section is visible', async ({ page }) => {
    await expect(page.locator('h2, h3').filter({ hasText: /bank/i }).first()).toBeVisible();
    await expect(page.locator('input[name="pay_bsb"]')).toBeVisible();
    await expect(page.locator('input[name="pay_account"]')).toBeVisible();
  });

  test('TFN section is visible with Set/Not set status', async ({ page }) => {
    // Jump to TFN section if there's an anchor
    await page.evaluate(() => {
      const el = document.getElementById('tfn-section');
      if (el) el.scrollIntoView();
    });
    await expect(page.locator('#tfn-section, h2, h3').filter({ hasText: /TFN|Tax File/i }).first()).toBeVisible();
    // Status should say ✓ Set or ⚠ Not set
    await expect(page.locator('text=/✓ Set|⚠ Not set|not set/i').first()).toBeVisible();
  });

  test('← back link navigates to employee list', async ({ page }) => {
    await page.locator('a').filter({ hasText: /← |Payroll Employees/i }).first().click();
    await expect(page.locator('h1')).toContainText('Payroll Employees');
  });
});
