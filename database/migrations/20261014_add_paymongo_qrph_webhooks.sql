-- PayMongo QR Ph webhook upgrade (MariaDB 10.4.32)
--
-- Run after 20261014_create_paymongo_qrph_transactions.sql, or on its own.
-- Select lending_management first. This script does not issue USE.
--
-- MariaDB 10.4.32 accepts ADD COLUMN IF NOT EXISTS and ADD INDEX IF NOT EXISTS.
-- Those clauses are still not used here. A single ALTER that mixes MODIFY,
-- ADD COLUMN, and ADD INDEX does not check each object on its own, and
-- CREATE TABLE IF NOT EXISTS does not repair a table that already exists.
-- Each column, index, enum change, and foreign key below is applied only when
-- information_schema shows that it is missing. Plain ADD / MODIFY syntax is
-- what 10.4.32 executes.
--
-- The script must run in one database session. It never drops, truncates,
-- recreates, updates, or deletes existing tables or rows. Existing payment
-- rows gain a nullable paymongo_qrph_transaction_id only; cash payments stay
-- NULL. Loan balance, interest, and penalty definitions are not changed.
--
-- Nullable unique indexes allow many NULLs. Pending QR rows and existing cash
-- payments can share that empty value. A duplicate non-NULL key stops that
-- one statement and leaves the stored rows in place.

CREATE TABLE IF NOT EXISTS `paymongo_qrph_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `internal_reference` VARCHAR(40) NOT NULL,
  `payment_intent_id` VARCHAR(100) DEFAULT NULL,
  `payment_method_id` VARCHAR(100) DEFAULT NULL,
  `provider_payment_id` VARCHAR(100) DEFAULT NULL,
  `payment_id` INT DEFAULT NULL,
  `paid_at` DATETIME DEFAULT NULL,
  `loan_id` INT NOT NULL,
  `client_id` INT DEFAULT NULL,
  `borrower_user_id` INT NOT NULL,
  `initiated_by_user_id` INT NOT NULL,
  `collector_id` INT DEFAULT NULL,
  `selected_method` VARCHAR(30) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `penalty_paid` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `currency` CHAR(3) NOT NULL DEFAULT 'PHP',
  `status` ENUM('pending', 'failed', 'expired', 'succeeded', 'review_required') NOT NULL DEFAULT 'pending',
  `provider_status` VARCHAR(60) NOT NULL DEFAULT 'initializing',
  `qr_image_url` LONGTEXT DEFAULT NULL,
  `failure_message` TEXT DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `paymongo_qrph_internal_reference_unique` (`internal_reference`),
  UNIQUE KEY `paymongo_qrph_payment_intent_unique` (`payment_intent_id`),
  UNIQUE KEY `uq_paymongo_qrph_provider_payment_id` (`provider_payment_id`),
  UNIQUE KEY `uq_paymongo_qrph_payment_id` (`payment_id`),
  KEY `paymongo_qrph_loan_status` (`loan_id`, `status`),
  KEY `paymongo_qrph_borrower_created` (`borrower_user_id`, `created_at`),
  KEY `paymongo_qrph_collector_created` (`collector_id`, `created_at`),
  KEY `paymongo_qrph_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- internal_reference
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'internal_reference') > 0,
            'SET @paymongo_migration_skipped = 1',
            'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `internal_reference` VARCHAR(40) NOT NULL AFTER `id`'
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- payment_intent_id
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'payment_intent_id') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'internal_reference') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `payment_intent_id` VARCHAR(100) NULL DEFAULT NULL AFTER `internal_reference`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `payment_intent_id` VARCHAR(100) NULL DEFAULT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- payment_method_id
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'payment_method_id') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'payment_intent_id') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `payment_method_id` VARCHAR(100) NULL DEFAULT NULL AFTER `payment_intent_id`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `payment_method_id` VARCHAR(100) NULL DEFAULT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- provider_payment_id
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'provider_payment_id') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'payment_method_id') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `provider_payment_id` VARCHAR(100) NULL DEFAULT NULL AFTER `payment_method_id`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `provider_payment_id` VARCHAR(100) NULL DEFAULT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- payment_id (signed INT, same as payments.payment_id)
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'payment_id') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'provider_payment_id') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `payment_id` INT NULL DEFAULT NULL AFTER `provider_payment_id`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `payment_id` INT NULL DEFAULT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- paid_at
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'paid_at') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'payment_id') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `paid_at` DATETIME NULL DEFAULT NULL AFTER `payment_id`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `paid_at` DATETIME NULL DEFAULT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- loan_id
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'loan_id') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'paid_at') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `loan_id` INT NOT NULL AFTER `paid_at`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `loan_id` INT NOT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- client_id
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'client_id') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'loan_id') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `client_id` INT NULL DEFAULT NULL AFTER `loan_id`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `client_id` INT NULL DEFAULT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- borrower_user_id
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'borrower_user_id') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'client_id') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `borrower_user_id` INT NOT NULL AFTER `client_id`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `borrower_user_id` INT NOT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- initiated_by_user_id
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'initiated_by_user_id') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'borrower_user_id') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `initiated_by_user_id` INT NOT NULL AFTER `borrower_user_id`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `initiated_by_user_id` INT NOT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- collector_id
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'collector_id') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'initiated_by_user_id') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `collector_id` INT NULL DEFAULT NULL AFTER `initiated_by_user_id`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `collector_id` INT NULL DEFAULT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- selected_method
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'selected_method') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'collector_id') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `selected_method` VARCHAR(30) NOT NULL AFTER `collector_id`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `selected_method` VARCHAR(30) NOT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- amount
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'amount') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'selected_method') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `amount` DECIMAL(12,2) NOT NULL AFTER `selected_method`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `amount` DECIMAL(12,2) NOT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- penalty_paid
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'penalty_paid') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'amount') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `penalty_paid` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `amount`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `penalty_paid` DECIMAL(12,2) NOT NULL DEFAULT 0.00'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- currency
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'currency') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'penalty_paid') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT ''PHP'' AFTER `penalty_paid`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT ''PHP'''
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- status: add it when absent, otherwise widen the older three-value enum in place
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'status') = 0,
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'currency') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `status` ENUM(''pending'', ''failed'', ''expired'', ''succeeded'', ''review_required'') NOT NULL DEFAULT ''pending'' AFTER `currency`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `status` ENUM(''pending'', ''failed'', ''expired'', ''succeeded'', ''review_required'') NOT NULL DEFAULT ''pending'''
            ),
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'paymongo_qrph_transactions'
                   AND COLUMN_NAME = 'status'
                   AND LOWER(COLUMN_TYPE) = 'enum(''pending'',''failed'',''expired'',''succeeded'',''review_required'')') > 0,
                'SET @paymongo_migration_skipped = 1',
                'ALTER TABLE `paymongo_qrph_transactions` MODIFY COLUMN `status` ENUM(''pending'', ''failed'', ''expired'', ''succeeded'', ''review_required'') NOT NULL DEFAULT ''pending'''
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- provider_status
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'provider_status') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'status') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `provider_status` VARCHAR(60) NOT NULL DEFAULT ''initializing'' AFTER `status`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `provider_status` VARCHAR(60) NOT NULL DEFAULT ''initializing'''
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- qr_image_url
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'qr_image_url') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'provider_status') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `qr_image_url` LONGTEXT NULL DEFAULT NULL AFTER `provider_status`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `qr_image_url` LONGTEXT NULL DEFAULT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- failure_message
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'failure_message') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'qr_image_url') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `failure_message` TEXT NULL DEFAULT NULL AFTER `qr_image_url`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `failure_message` TEXT NULL DEFAULT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- expires_at
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'expires_at') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'failure_message') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `expires_at` DATETIME NOT NULL AFTER `failure_message`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `expires_at` DATETIME NOT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- created_at
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'created_at') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'expires_at') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `expires_at`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- updated_at
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'updated_at') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND COLUMN_NAME = 'created_at') > 0,
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`',
                'ALTER TABLE `paymongo_qrph_transactions` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- Unique and lookup indexes. Existing index names are left unchanged.
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND INDEX_NAME = 'paymongo_qrph_internal_reference_unique') > 0,
            'SET @paymongo_migration_skipped = 1',
            'ALTER TABLE `paymongo_qrph_transactions` ADD UNIQUE INDEX `paymongo_qrph_internal_reference_unique` (`internal_reference`)'
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND INDEX_NAME = 'paymongo_qrph_payment_intent_unique') > 0,
            'SET @paymongo_migration_skipped = 1',
            'ALTER TABLE `paymongo_qrph_transactions` ADD UNIQUE INDEX `paymongo_qrph_payment_intent_unique` (`payment_intent_id`)'
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND INDEX_NAME = 'uq_paymongo_qrph_provider_payment_id') > 0,
            'SET @paymongo_migration_skipped = 1',
            'ALTER TABLE `paymongo_qrph_transactions` ADD UNIQUE INDEX `uq_paymongo_qrph_provider_payment_id` (`provider_payment_id`)'
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND INDEX_NAME = 'uq_paymongo_qrph_payment_id') > 0,
            'SET @paymongo_migration_skipped = 1',
            'ALTER TABLE `paymongo_qrph_transactions` ADD UNIQUE INDEX `uq_paymongo_qrph_payment_id` (`payment_id`)'
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND INDEX_NAME = 'paymongo_qrph_loan_status') > 0,
            'SET @paymongo_migration_skipped = 1',
            'ALTER TABLE `paymongo_qrph_transactions` ADD INDEX `paymongo_qrph_loan_status` (`loan_id`, `status`)'
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND INDEX_NAME = 'paymongo_qrph_borrower_created') > 0,
            'SET @paymongo_migration_skipped = 1',
            'ALTER TABLE `paymongo_qrph_transactions` ADD INDEX `paymongo_qrph_borrower_created` (`borrower_user_id`, `created_at`)'
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND INDEX_NAME = 'paymongo_qrph_collector_created') > 0,
            'SET @paymongo_migration_skipped = 1',
            'ALTER TABLE `paymongo_qrph_transactions` ADD INDEX `paymongo_qrph_collector_created` (`collector_id`, `created_at`)'
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_transactions' AND INDEX_NAME = 'paymongo_qrph_expires_at') > 0,
            'SET @paymongo_migration_skipped = 1',
            'ALTER TABLE `paymongo_qrph_transactions` ADD INDEX `paymongo_qrph_expires_at` (`expires_at`)'
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- Link an existing payment to one QR transaction. Absent on the live payments
-- table. NULL on every current row, so cash payments are unchanged.
-- reference_number is present; the column is placed after it. If that anchor
-- is ever absent, the column is appended instead of failing the statement.
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'paymongo_qrph_transaction_id') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'reference_number') > 0,
                'ALTER TABLE `payments` ADD COLUMN `paymongo_qrph_transaction_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `reference_number`',
                'ALTER TABLE `payments` ADD COLUMN `paymongo_qrph_transaction_id` BIGINT UNSIGNED NULL DEFAULT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND INDEX_NAME = 'uq_payments_paymongo_qrph_transaction_id') > 0,
            'SET @paymongo_migration_skipped = 1',
            'ALTER TABLE `payments` ADD UNIQUE INDEX `uq_payments_paymongo_qrph_transaction_id` (`paymongo_qrph_transaction_id`)'
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

CREATE TABLE IF NOT EXISTS `paymongo_qrph_webhook_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` VARCHAR(100) NOT NULL,
  `event_type` VARCHAR(100) NOT NULL,
  `transaction_id` BIGINT UNSIGNED DEFAULT NULL,
  `provider_payment_id` VARCHAR(100) DEFAULT NULL,
  `payment_intent_id` VARCHAR(100) DEFAULT NULL,
  `amount_centavos` BIGINT DEFAULT NULL,
  `currency` VARCHAR(10) DEFAULT NULL,
  `provider_status` VARCHAR(50) DEFAULT NULL,
  `livemode` TINYINT(1) DEFAULT NULL,
  `payload_sha256` CHAR(64) NOT NULL,
  `signature_verified` TINYINT(1) NOT NULL DEFAULT 1,
  `processing_status` ENUM('processed', 'ignored', 'review_required') NOT NULL,
  `details` VARCHAR(1000) DEFAULT NULL,
  `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_paymongo_qrph_webhook_event_id` (`event_id`),
  KEY `idx_paymongo_qrph_webhook_transaction` (`transaction_id`),
  KEY `idx_paymongo_qrph_webhook_received` (`received_at`),
  CONSTRAINT `fk_paymongo_qrph_webhook_transaction`
    FOREIGN KEY (`transaction_id`) REFERENCES `paymongo_qrph_transactions` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- event_id
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'event_id') > 0,
            'SET @paymongo_migration_skipped = 1',
            'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `event_id` VARCHAR(100) NOT NULL AFTER `id`'
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- event_type
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'event_type') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'event_id') > 0,
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `event_type` VARCHAR(100) NOT NULL AFTER `event_id`',
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `event_type` VARCHAR(100) NOT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- transaction_id
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'transaction_id') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'event_type') > 0,
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `transaction_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `event_type`',
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `transaction_id` BIGINT UNSIGNED NULL DEFAULT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- provider_payment_id
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'provider_payment_id') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'transaction_id') > 0,
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `provider_payment_id` VARCHAR(100) NULL DEFAULT NULL AFTER `transaction_id`',
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `provider_payment_id` VARCHAR(100) NULL DEFAULT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- payment_intent_id
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'payment_intent_id') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'provider_payment_id') > 0,
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `payment_intent_id` VARCHAR(100) NULL DEFAULT NULL AFTER `provider_payment_id`',
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `payment_intent_id` VARCHAR(100) NULL DEFAULT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- amount_centavos
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'amount_centavos') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'payment_intent_id') > 0,
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `amount_centavos` BIGINT NULL DEFAULT NULL AFTER `payment_intent_id`',
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `amount_centavos` BIGINT NULL DEFAULT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- currency
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'currency') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'amount_centavos') > 0,
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `currency` VARCHAR(10) NULL DEFAULT NULL AFTER `amount_centavos`',
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `currency` VARCHAR(10) NULL DEFAULT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- provider_status
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'provider_status') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'currency') > 0,
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `provider_status` VARCHAR(50) NULL DEFAULT NULL AFTER `currency`',
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `provider_status` VARCHAR(50) NULL DEFAULT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- livemode
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'livemode') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'provider_status') > 0,
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `livemode` TINYINT(1) NULL DEFAULT NULL AFTER `provider_status`',
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `livemode` TINYINT(1) NULL DEFAULT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- payload_sha256
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'payload_sha256') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'livemode') > 0,
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `payload_sha256` CHAR(64) NOT NULL AFTER `livemode`',
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `payload_sha256` CHAR(64) NOT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- signature_verified
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'signature_verified') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'payload_sha256') > 0,
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `signature_verified` TINYINT(1) NOT NULL DEFAULT 1 AFTER `payload_sha256`',
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `signature_verified` TINYINT(1) NOT NULL DEFAULT 1'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- processing_status
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'processing_status') = 0,
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'signature_verified') > 0,
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `processing_status` ENUM(''processed'', ''ignored'', ''review_required'') NOT NULL AFTER `signature_verified`',
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `processing_status` ENUM(''processed'', ''ignored'', ''review_required'') NOT NULL'
            ),
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'paymongo_qrph_webhook_events'
                   AND COLUMN_NAME = 'processing_status'
                   AND LOWER(COLUMN_TYPE) = 'enum(''processed'',''ignored'',''review_required'')') > 0,
                'SET @paymongo_migration_skipped = 1',
                'ALTER TABLE `paymongo_qrph_webhook_events` MODIFY COLUMN `processing_status` ENUM(''processed'', ''ignored'', ''review_required'') NOT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- details
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'details') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'processing_status') > 0,
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `details` VARCHAR(1000) NULL DEFAULT NULL AFTER `processing_status`',
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `details` VARCHAR(1000) NULL DEFAULT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- received_at
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'received_at') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'details') > 0,
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `details`',
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- processed_at
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'processed_at') > 0,
            'SET @paymongo_migration_skipped = 1',
            IF(
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND COLUMN_NAME = 'received_at') > 0,
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `processed_at` DATETIME NULL DEFAULT NULL AFTER `received_at`',
                'ALTER TABLE `paymongo_qrph_webhook_events` ADD COLUMN `processed_at` DATETIME NULL DEFAULT NULL'
            )
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND INDEX_NAME = 'uq_paymongo_qrph_webhook_event_id') > 0,
            'SET @paymongo_migration_skipped = 1',
            'ALTER TABLE `paymongo_qrph_webhook_events` ADD UNIQUE INDEX `uq_paymongo_qrph_webhook_event_id` (`event_id`)'
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND INDEX_NAME = 'idx_paymongo_qrph_webhook_transaction') > 0,
            'SET @paymongo_migration_skipped = 1',
            'ALTER TABLE `paymongo_qrph_webhook_events` ADD INDEX `idx_paymongo_qrph_webhook_transaction` (`transaction_id`)'
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paymongo_qrph_webhook_events' AND INDEX_NAME = 'idx_paymongo_qrph_webhook_received') > 0,
            'SET @paymongo_migration_skipped = 1',
            'ALTER TABLE `paymongo_qrph_webhook_events` ADD INDEX `idx_paymongo_qrph_webhook_received` (`received_at`)'
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;

-- Relationship: webhook event -> QR transaction. ON DELETE SET NULL keeps the
-- audit row if a transaction row is removed later. It does not change payments.
SET @paymongo_ddl := (
    SELECT CAST(
        IF(
            (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'paymongo_qrph_webhook_events'
               AND CONSTRAINT_NAME = 'fk_paymongo_qrph_webhook_transaction'
               AND CONSTRAINT_TYPE = 'FOREIGN KEY') > 0,
            'SET @paymongo_migration_skipped = 1',
            'ALTER TABLE `paymongo_qrph_webhook_events` ADD CONSTRAINT `fk_paymongo_qrph_webhook_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `paymongo_qrph_transactions` (`id`) ON DELETE SET NULL'
        ) AS CHAR(4000)
    )
);
PREPARE paymongo_migration_stmt FROM @paymongo_ddl;
EXECUTE paymongo_migration_stmt;
DEALLOCATE PREPARE paymongo_migration_stmt;
