-- Ensure the Step 2 table exists so this migration is safe when deployed alone
-- or when the Step 2 migration has already been applied.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrade a transaction table created by the original Step 2 migration.
ALTER TABLE `paymongo_qrph_transactions`
    MODIFY COLUMN `status` ENUM('pending', 'failed', 'expired', 'succeeded', 'review_required') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS `provider_payment_id` VARCHAR(100) DEFAULT NULL AFTER `payment_method_id`,
    ADD COLUMN IF NOT EXISTS `payment_id` INT DEFAULT NULL AFTER `provider_payment_id`,
    ADD COLUMN IF NOT EXISTS `paid_at` DATETIME DEFAULT NULL AFTER `payment_id`,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_paymongo_qrph_provider_payment_id` (`provider_payment_id`),
    ADD UNIQUE INDEX IF NOT EXISTS `uq_paymongo_qrph_payment_id` (`payment_id`);

-- Existing payment rows receive NULL for this nullable column, so they remain intact.
ALTER TABLE `payments`
    ADD COLUMN IF NOT EXISTS `paymongo_qrph_transaction_id` BIGINT UNSIGNED DEFAULT NULL AFTER `reference_number`,
    ADD UNIQUE INDEX IF NOT EXISTS `uq_payments_paymongo_qrph_transaction_id` (`paymongo_qrph_transaction_id`);

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
