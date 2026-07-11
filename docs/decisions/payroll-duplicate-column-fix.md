# Payroll Module: Duplicate Column Error on Fresh Install

## Problem (found 2026-07-11)

Activating the Payroll module on the **live** site (a genuinely fresh install — the payroll
tables didn't exist there before) failed with:

```
Duplicate column name 'is_super_applicable'
```

Dev never hit this because dev's payroll tables were created months ago, before this bug was
introduced — so the condition that triggers it (activating on a database where the tables don't
exist yet) never occurred there.

## Root cause

`custom/modules/payroll/core/modules/modPayroll.class.php`'s `init()` builds one combined list of
SQL statements to execute at the end via `_init($sql, $options)`:

1. `CREATE TABLE IF NOT EXISTS` for all 15 payroll tables (read from `custom/modules/payroll/sql/*.sql`)
2. A PHP-driven migration list of `ALTER TABLE ... ADD COLUMN` statements, for columns added to
   the schema *after* those tables were first designed — needed because MySQL 9.x doesn't support
   `ADD COLUMN IF NOT EXISTS`, so each migration first checks `information_schema.COLUMNS` and
   only queues the `ALTER` if the column doesn't already exist.

The bug: that existence check runs **synchronously, immediately**, before any of the queued `CREATE
TABLE` statements from step 1 have actually executed (they're just strings sitting in the `$sql[]`
array at that point, waiting for the single `_init($sql, $options)` call at the very end). On a
fresh database, the check for a column like `is_super_applicable` sees "table doesn't exist" →
column count 0 → queues `ALTER TABLE llx_payroll_deduction_type ADD COLUMN is_super_applicable ...`
— but `is_super_applicable` had, at some point in this module's development, been added *directly*
into `llx_payroll_deduction_type.sql`'s own `CREATE TABLE` statement. So when `_init()` finally
executes the combined list in order: `CREATE TABLE` (adds the column) runs fine, then the redundant
`ALTER TABLE ADD COLUMN` for the same column runs moments later and collides.

Same bug, same shape, found for **11 columns total** across 4 tables — all had been added directly
to their table's `CREATE TABLE` statement at some point after the PHP migration entry was written,
and the now-redundant migration entry was never removed:

| Table | Redundant migration column |
|---|---|
| `llx_payroll_deduction_type` | `is_super_applicable` |
| `llx_payroll_employee` | `employment_start_date`, `super_fund_name`, `super_fund_usi`, `super_fund_abn`, `super_member_number` |
| `llx_payroll_fy_config` | `start_date`, `end_date` |
| `llx_payroll_payrun_line` | `leave_note`, `super_fund_usi`, `super_member_number` |

Dolibarr's `_init()` stops at the first failing statement, so only the first (`is_super_applicable`,
first in both the array and alphabetically-first table in the loop) actually surfaced an error —
but the same collision would have hit every other redundant entry too, in sequence, once the first
was fixed.

## Consequence on live

Because `_init()` stopped at the first error, everything queued *after* that point in the combined
array never ran:
- 6 genuinely-still-needed columns on `llx_payroll_employee` (`has_medicare_adj`,
  `medicare_dependants`, `std_weekly_hours`, `tfn_encrypted`, `pay_bsb`, `pay_account`) were never added
- `llx_user_extrafields.super_usi` was never widened from `VARCHAR(30)` to `VARCHAR(50)`

All 15 `CREATE TABLE` statements (and their seed `INSERT IGNORE` rows) had already run by that
point and completed successfully — only the tail end of the migration list was affected. The
module was left marked enabled (`MAIN_MODULE_PAYROLL = 1`) with an incomplete schema.

## Fix

1. Removed the 11 redundant migration entries from `modPayroll.class.php` — only the 6 genuinely
   still-needed `llx_payroll_employee` columns remain in the migration list.
2. Updated the stale comment in `custom/modules/payroll/sql/llx_payroll_alter.sql` (which
   incorrectly claimed `is_super_applicable`'s `ALTER TABLE` was "handled in init() PHP code" —
   true once, no longer true since that column moved into the `CREATE TABLE` statement).
3. Applied the two still-missing pieces directly to the live database (additive-only, no data
   touched): the 6 missing columns via `ALTER TABLE ... ADD COLUMN`, and the `super_usi` width fix
   via `ALTER TABLE ... MODIFY COLUMN`. Verified all 7 afterward via `DESCRIBE`.

## Verified

Live: `DESCRIBE llx_payroll_employee` confirms all 6 columns now present;
`SHOW COLUMNS FROM llx_user_extrafields LIKE 'super_usi'` confirms `varchar(50)`.

Dev was never affected by the bug (tables predate it), and the fix only *removes* redundant
no-op migration entries, so dev needs no DB changes — the next time anyone disables/re-enables
Payroll anywhere (dev, live, or a future third install), `init()` will now run cleanly start to
finish.

## Upgrade risk

None — this only touches custom module code (`custom/modules/payroll/`), no core files.

## Still outstanding

The fixed code hasn't been deployed to the live server's file system yet (only the two live
database schema fixes above were applied directly). Live's copy of `modPayroll.class.php` still
has the bug — harmless unless the module is disabled and re-enabled again there, but worth
deploying properly next time live gets updated.
