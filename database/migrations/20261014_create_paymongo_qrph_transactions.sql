-- PayMongo QR Ph transactions (MariaDB 10.4.32)
--
-- Safe to rerun: CREATE TABLE IF NOT EXISTS does not change an existing table
-- and does not touch payments, loans, clients, or users.
--
-- A table created by an older copy of this file is missing provider_payment_id,
-- payment_id, paid_at, and the succeeded/review_required status values.
-- Apply 20261014_add_paymongo_qrph_webhooks.sql next; that file adds only the
-- missing columns and indexes.
--
-- Select the target database first (lending_management). This file does not
-- issue USE, so it cannot switch away from the database you selected.

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
