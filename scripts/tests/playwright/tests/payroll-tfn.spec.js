/**
 * TFN Manager Tests
 *
 * Verifies the TFN Manager page loads correctly, shows all employees,
 * and the encrypt utility works. No real TFN values used — test uses
 * a synthetic 9-digit string (123456782 passes the Luhn-like ATO check
 * digit algorithm but is not a real TFN).
 *
 * Read-only with respect to DB — the encrypt utility submits a form
 * that only returns an encrypted blob in the response; no DB write.
 */

import { test, expect } from '@playwright/test';

const EXPECTED_EMPLOYEES = ['Eloise Wallis', 'Daniel Wallis', 'michael wallis', 'test dummy'];
const TEST_TFN = '123456782';

test.describe('TFN Manager', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/custom/payroll/tfn.php?mainmenu=billing&leftmenu=payroll_tfn');
  });

  test('page loads with correct heading', async ({ page }) => {
    await expect(page.locator('h1')).toContainText('TFN Manager');
    await expect(page.locator('h1')).toContainText('Admin only');
  });

  test('no TFN_KEY error is shown', async ({ page }) => {
    // If TFN_KEY is missing, an alert-danger box appears
    await expect(page.locator('.alert-danger')).toHaveCount(0);
  });

  test('employee table shows all employees with payroll profiles', async ({ page }) => {
    for (const name of EXPECTED_EMPLOYEES) {
      await expect(page.locator('td').filter({ hasText: new RegExp(name, 'i') }).first()).toBeVisible();
    }
  });

  test('each employee row has an Edit profile button', async ({ page }) => {
    const editButtons = page.locator('a.button').filter({ hasText: 'Edit profile' });
    // Should have at least as many as we have employees
    await expect(editButtons).toHaveCount(EXPECTED_EMPLOYEES.length);
  });

  test('Edit profile button links to correct page', async ({ page }) => {
    // Click the first Edit profile button and check it goes to employee_payroll.php
    const firstEdit = page.locator('a.button').filter({ hasText: 'Edit profile' }).first();
    const href = await firstEdit.getAttribute('href');
    expect(href).toMatch(/employee_payroll\.php\?userid=\d+/);
  });

  test('Encrypt utility renders and accepts a TFN', async ({ page }) => {
    await expect(page.locator('h2').filter({ hasText: 'Encrypt' })).toBeVisible();

    const plainInput = page.locator('input[name="plain"]');
    await expect(plainInput).toBeVisible();

    await plainInput.fill(TEST_TFN);
    await page.locator('input[type="submit"][value="Encrypt"]').first().click();

    // After submission, the encrypted value field should appear on the page
    const encField = page.locator('input[readonly]');
    await expect(encField).toBeVisible({ timeout: 10_000 });
    const encValue = await encField.inputValue();
    // Encrypted blob is base64 — should be non-empty and look like base64
    expect(encValue.length).toBeGreaterThan(20);
    expect(encValue).toMatch(/^[A-Za-z0-9+/]+=*$/);
  });

  test('Decrypt utility renders', async ({ page }) => {
    await expect(page.locator('h2').filter({ hasText: 'Decrypt' })).toBeVisible();
    await expect(page.locator('input[name="blob"]')).toBeVisible();
  });

  test('security reminders section is visible', async ({ page }) => {
    await expect(page.locator('.alert-warning')).toBeVisible();
    await expect(page.locator('.alert-warning')).toContainText('TFN_KEY');
  });

  test('← Payroll Employees back link navigates correctly', async ({ page }) => {
    await page.locator('a').filter({ hasText: /← Payroll Employees/i }).first().click();
    await expect(page.locator('h1')).toContainText('Payroll Employees');
  });
});
