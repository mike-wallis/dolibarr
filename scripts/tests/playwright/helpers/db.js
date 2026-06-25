import { spawnSync } from 'child_process';
import { loadEnv } from './load-env.js';

const MYSQL = 'C:\\wamp64\\bin\\mysql\\mysql9.1.0\\bin\\mysql.exe';

/**
 * Run a SQL query against the dev DB and return parsed rows.
 * Uses spawnSync with individual args to avoid Windows shell escaping issues.
 * SELECT: returns array of plain objects.
 * Non-SELECT (DELETE/UPDATE/INSERT): returns empty array.
 */
export function query(sql) {
  const env = loadEnv();
  // --batch outputs tab-separated rows; omit --silent so column headers are included on row 0
  const result = spawnSync(MYSQL, [
    '-u', env.DB_USER,
    `-p${env.DB_PASS}`,
    '--batch',
    env.DB_NAME,
    '-e', sql,
  ], { encoding: 'utf8' });

  if (result.error) {
    throw new Error(`DB spawn error: ${result.error.message}\nSQL: ${sql}`);
  }
  if (result.status !== 0) {
    const stderr = (result.stderr || '').replace(/mysql.*Warning[^\n]*/gi, '').trim();
    if (stderr) throw new Error(`DB query failed: ${stderr}\nSQL: ${sql}`);
  }

  const out = (result.stdout || '').trim();
  if (!out) return [];
  // Split on \n then strip \r to handle Windows CRLF output from MySQL
  const lines = out.split('\n').map(l => l.replace(/\r$/, ''));
  if (lines.length < 2) return [];
  const headers = lines[0].split('\t');
  return lines.slice(1).map(line => {
    const cells = line.split('\t');
    const row = {};
    headers.forEach((h, i) => { row[h] = cells[i] ?? null; });
    return row;
  });
}

/**
 * Execute a non-SELECT SQL statement (INSERT, UPDATE, DELETE).
 * Throws on error.
 */
export function execute(sql) {
  query(sql);
}

/**
 * Delete all records created by a test pay run, identified by period_end date.
 * Removes: payrun_line, leave_transaction (fk_salary), leave_balance changes,
 * salary, payment_salary, bank entries, bookkeeping entries (all doc_types).
 *
 * Safe to call even if the pay run doesn't exist (no-op).
 */
export function deleteTestPayrun(periodEnd) {
  // Build YYYYMMDD string for matching deduction doc_refs (e.g. SUPER-20260720-5)
  const yyyymmdd = periodEnd.replace(/-/g, '');

  // Find salary IDs from payrun_line rows for this period end
  const rows = query(
    `SELECT fk_salary FROM llx_payroll_payrun_line WHERE pay_period_end = '${periodEnd}'`
  );
  const salaryIds = rows.map(r => parseInt(r.fk_salary)).filter(id => id > 0);

  if (salaryIds.length > 0) {
    const idList = salaryIds.join(',');

    // Leave transactions linked to these salary records
    execute(`DELETE FROM llx_payroll_leave_transaction WHERE fk_salary IN (${idList})`);

    // Payment salary records, bank lines, and bookkeeping bank entries
    const psRows = query(
      `SELECT rowid, fk_bank FROM llx_payment_salary WHERE fk_salary IN (${idList})`
    );
    if (psRows.length > 0) {
      const psIds = psRows.map(r => r.rowid).join(',');
      const bankIds = psRows.map(r => parseInt(r.fk_bank)).filter(id => id > 0);

      // Remove bookkeeping entries for these bank lines (doc_type='bank', fk_doc=bank_line_id)
      if (bankIds.length > 0) {
        execute(
          `DELETE FROM llx_accounting_bookkeeping WHERE doc_type = 'bank' AND fk_doc IN (${bankIds.join(',')})`
        );
      }
      // Also remove any bank-type BK entries with fk_doc=0 for this period (legacy from bug where
      // bank_line_id was 0 — these are matched by doc_ref pattern)
      execute(
        `DELETE FROM llx_accounting_bookkeeping WHERE doc_type = 'bank' AND fk_doc = 0` +
        ` AND doc_ref LIKE 'Salary payment %'` +
        ` AND label_operation LIKE '% ${periodEnd.slice(0,4)}%'`
      );

      // fk_bank is stored directly on llx_payment_salary — no separate join table
      if (bankIds.length > 0) {
        execute(`DELETE FROM llx_bank WHERE rowid IN (${bankIds.join(',')})`);
      }
      execute(`DELETE FROM llx_payment_salary WHERE rowid IN (${psIds})`);
    }

    // Salary records
    execute(`DELETE FROM llx_salary WHERE rowid IN (${idList})`);
  }

  // Deduction/super bookkeeping entries (doc_type='various', fk_doc=0, doc_ref like CODE-YYYYMMDD-uid)
  execute(
    `DELETE FROM llx_accounting_bookkeeping WHERE doc_type = 'various' AND doc_ref LIKE '%-${yyyymmdd}-%'`
  );

  // Payrun lines
  execute(`DELETE FROM llx_payroll_payrun_line WHERE pay_period_end = '${periodEnd}'`);
}
