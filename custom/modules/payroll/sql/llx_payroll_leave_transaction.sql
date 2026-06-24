-- Full audit ledger for all leave movements (accruals, taken, adjustments, opening, payout).
-- Covers annual leave, sick/carer's leave, and bereavement/compassionate leave.
-- hours is always stored as a positive value; direction is implied by transaction_type.

CREATE TABLE IF NOT EXISTS llx_payroll_leave_transaction (
    rowid               INT              NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fk_user             INT              NOT NULL,
    entity              INT              NOT NULL DEFAULT 1,
    leave_type          ENUM('annual','sick','bereavement') NOT NULL,
    transaction_type    ENUM('opening','accrual','taken','adjustment','payout') NOT NULL,
    hours               DECIMAL(10,2)    NOT NULL,
    date_transaction    DATE             NOT NULL,
    fk_salary           INT              NULL,
    note                VARCHAR(255)     NULL,
    KEY idx_leave_tx_user (fk_user, entity),
    KEY idx_leave_tx_salary (fk_salary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
