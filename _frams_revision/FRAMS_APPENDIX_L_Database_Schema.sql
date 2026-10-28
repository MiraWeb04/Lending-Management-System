
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `borrower_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `borrower_applications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(30) DEFAULT NULL,
  `civil_status` varchar(30) DEFAULT NULL,
  `nationality` varchar(100) DEFAULT NULL,
  `mobile_number` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `complete_address` text DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `government_id_type` varchar(80) DEFAULT NULL,
  `government_id_number` varchar(100) DEFAULT NULL,
  `consent_accepted` tinyint(1) DEFAULT 0,
  `status` varchar(50) DEFAULT 'Pending Approval',
  `verification_status` varchar(50) DEFAULT 'Pending',
  `rejection_reason` text DEFAULT NULL,
  `approved_by` varchar(150) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clients` (
  `client_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `address` text DEFAULT NULL,
  `contact` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `collector_id` int(11) DEFAULT NULL,
  `date_registered` date DEFAULT NULL,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_inquiries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_inquiries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sender_name` varchar(150) NOT NULL,
  `sender_email` varchar(254) NOT NULL,
  `message` text NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'new',
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `contact_inquiry_status` (`status`),
  KEY `contact_inquiry_created` (`created_at`),
  KEY `contact_inquiry_email` (`sender_email`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_inquiry_replies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_inquiry_replies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `inquiry_id` int(10) unsigned NOT NULL,
  `admin_user_id` int(10) unsigned NOT NULL,
  `reply_body` text NOT NULL,
  `email_sent` tinyint(1) NOT NULL DEFAULT 0,
  `email_error` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `contact_reply_inquiry` (`inquiry_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `recipient` varchar(254) NOT NULL,
  `notification_type` varchar(80) NOT NULL,
  `reference_id` varchar(150) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_notification_event` (`notification_type`,`reference_id`),
  KEY `email_notification_recipient` (`recipient`),
  KEY `email_notification_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=102 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expenses` (
  `expense_id` int(11) NOT NULL AUTO_INCREMENT,
  `description` varchar(255) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `date` date NOT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'Miscellaneous',
  PRIMARY KEY (`expense_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_agreements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loan_agreements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(10) unsigned NOT NULL,
  `borrower_user_id` int(11) DEFAULT NULL,
  `agreement_status` varchar(50) DEFAULT 'Pending',
  `agreement_text` text DEFAULT NULL,
  `signed_by` varchar(150) DEFAULT NULL,
  `signed_at` datetime DEFAULT NULL,
  `lender_agreement_status` varchar(50) DEFAULT 'Pending',
  `lender_signed_by` varchar(150) DEFAULT NULL,
  `lender_signed_at` datetime DEFAULT NULL,
  `decline_reason` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_application_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loan_application_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(10) unsigned NOT NULL,
  `document_name` varchar(150) NOT NULL,
  `document_path` varchar(255) DEFAULT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp(),
  `verification_status` varchar(50) DEFAULT 'Pending Review',
  `rejection_reason` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=176 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_application_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loan_application_reviews` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(10) unsigned NOT NULL,
  `decision` varchar(50) NOT NULL,
  `review_note` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `created_by` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loan_applications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `borrower_name` varchar(200) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(30) DEFAULT NULL,
  `civil_status` varchar(30) DEFAULT NULL,
  `nationality` varchar(100) DEFAULT NULL,
  `complete_address` text DEFAULT NULL,
  `mobile_number` varchar(30) DEFAULT NULL,
  `email_address` varchar(150) DEFAULT NULL,
  `employment_status` varchar(80) DEFAULT NULL,
  `employer_name` varchar(150) DEFAULT NULL,
  `occupation` varchar(150) DEFAULT NULL,
  `monthly_income` decimal(12,2) DEFAULT NULL,
  `monthly_gross_income` decimal(12,2) DEFAULT NULL,
  `monthly_net_income` decimal(12,2) DEFAULT NULL,
  `monthly_debt_payments` decimal(12,2) DEFAULT NULL,
  `other_monthly_obligations` decimal(12,2) DEFAULT NULL,
  `number_of_dependents` int(10) unsigned DEFAULT NULL,
  `credit_history_note` varchar(255) DEFAULT NULL,
  `collateral_note` varchar(255) DEFAULT NULL,
  `eligibility_status` varchar(40) DEFAULT NULL,
  `eligibility_assessment_json` longtext DEFAULT NULL,
  `eligibility_assessed_at` datetime DEFAULT NULL,
  `employment_length` varchar(80) DEFAULT NULL,
  `employer_address` text DEFAULT NULL,
  `employer_contact` varchar(30) DEFAULT NULL,
  `agency_office` varchar(150) DEFAULT NULL,
  `position` varchar(150) DEFAULT NULL,
  `employment_type` varchar(100) DEFAULT NULL,
  `years_in_service` varchar(80) DEFAULT NULL,
  `business_name` varchar(150) DEFAULT NULL,
  `business_address` text DEFAULT NULL,
  `nature_of_business` varchar(150) DEFAULT NULL,
  `years_in_business` varchar(80) DEFAULT NULL,
  `business_contact` varchar(30) DEFAULT NULL,
  `profession` varchar(150) DEFAULT NULL,
  `primary_client` varchar(150) DEFAULT NULL,
  `years_experience` varchar(80) DEFAULT NULL,
  `ofw_country` varchar(100) DEFAULT NULL,
  `years_abroad` varchar(80) DEFAULT NULL,
  `employer_contact_ofw` varchar(30) DEFAULT NULL,
  `pension_source` varchar(150) DEFAULT NULL,
  `monthly_pension` decimal(12,2) DEFAULT NULL,
  `pension_contact` varchar(30) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `payment_frequency` varchar(50) DEFAULT 'Monthly',
  `number_of_payments` int(11) DEFAULT NULL,
  `estimated_payment` decimal(12,2) DEFAULT NULL,
  `estimated_due_date` date DEFAULT NULL,
  `monthly_interest_rate` decimal(5,4) DEFAULT NULL,
  `estimated_interest` decimal(12,2) DEFAULT NULL,
  `estimated_monthly_payment` decimal(12,2) DEFAULT NULL,
  `loan_type` varchar(80) DEFAULT NULL,
  `loan_amount` decimal(12,2) DEFAULT NULL,
  `loan_term` varchar(50) DEFAULT NULL,
  `loan_purpose` text DEFAULT NULL,
  `government_id_type` varchar(100) DEFAULT NULL,
  `government_id_number` varchar(100) DEFAULT NULL,
  `approved_loan_amount` decimal(12,2) DEFAULT NULL,
  `approved_interest_rate` decimal(5,2) DEFAULT NULL,
  `approved_monthly_payment` decimal(12,2) DEFAULT NULL,
  `approved_loan_term` varchar(50) DEFAULT NULL,
  `approved_first_payment_date` date DEFAULT NULL,
  `approved_due_date` date DEFAULT NULL,
  `approval_conditions` text DEFAULT NULL,
  `credit_evaluation` text DEFAULT NULL,
  `risk_assessment` text DEFAULT NULL,
  `internal_notes` text DEFAULT NULL,
  `reviewed_by` varchar(150) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `agreement_status` varchar(50) DEFAULT 'Pending',
  `status` varchar(50) DEFAULT 'Pending Review',
  `submitted_at` datetime DEFAULT current_timestamp(),
  `office_address` text DEFAULT NULL,
  `office_contact` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_payment_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loan_payment_schedules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `release_id` int(10) unsigned NOT NULL,
  `installment_number` int(11) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `principal_amount` decimal(12,2) DEFAULT NULL,
  `interest_amount` decimal(12,2) DEFAULT NULL,
  `total_amount_due` decimal(12,2) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Upcoming',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=483 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_release_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loan_release_notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `message` text DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loan_releases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loan_releases` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(10) unsigned NOT NULL,
  `borrower_user_id` int(11) DEFAULT NULL,
  `collector_user_id` int(11) DEFAULT NULL,
  `loan_number` varchar(100) DEFAULT NULL,
  `loan_amount` decimal(12,2) DEFAULT NULL,
  `interest_rate` decimal(5,2) DEFAULT NULL,
  `loan_term` varchar(50) DEFAULT NULL,
  `payment_frequency` varchar(50) DEFAULT 'Monthly',
  `release_status` varchar(50) DEFAULT 'Pending',
  `released_at` datetime DEFAULT NULL,
  `released_by` varchar(150) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loans` (
  `loan_id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `loan_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `interest` decimal(8,2) NOT NULL DEFAULT 0.00,
  `total_payable` decimal(12,2) NOT NULL DEFAULT 0.00,
  `daily_payment` decimal(12,2) NOT NULL DEFAULT 0.00,
  `date_released` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Active',
  `collector_id` int(11) DEFAULT NULL,
  `loan_number` varchar(100) DEFAULT NULL,
  `release_id` int(11) DEFAULT NULL,
  `payment_frequency` varchar(50) DEFAULT 'Monthly',
  `loan_term` varchar(50) DEFAULT NULL,
  `agreement_status` varchar(50) DEFAULT 'Pending',
  `released_by` varchar(150) DEFAULT NULL,
  `loan_type` varchar(80) DEFAULT NULL,
  `loan_purpose` text DEFAULT NULL,
  PRIMARY KEY (`loan_id`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `message` text DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=498 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_resets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(128) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `attempts` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `token` (`token`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `receipt_number` varchar(50) DEFAULT NULL,
  `collector_id` int(11) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `receipt_generated_at` datetime DEFAULT NULL,
  `payment_method` varchar(50) NOT NULL DEFAULT 'Cash',
  `reference_number` varchar(255) DEFAULT NULL,
  `paymongo_qrph_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `loan_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `collector_name` varchar(150) DEFAULT NULL,
  `interest_paid` decimal(12,2) DEFAULT 0.00,
  `penalty_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `principal_paid` decimal(12,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`payment_id`),
  UNIQUE KEY `receipt_number_unique` (`receipt_number`),
  UNIQUE KEY `uq_payments_paymongo_qrph_transaction_id` (`paymongo_qrph_transaction_id`)
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `paymongo_qrph_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paymongo_qrph_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `internal_reference` varchar(40) NOT NULL,
  `payment_intent_id` varchar(100) DEFAULT NULL,
  `payment_method_id` varchar(100) DEFAULT NULL,
  `provider_payment_id` varchar(100) DEFAULT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `loan_id` int(11) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `borrower_user_id` int(11) NOT NULL,
  `initiated_by_user_id` int(11) NOT NULL,
  `collector_id` int(11) DEFAULT NULL,
  `selected_method` varchar(30) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `penalty_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) NOT NULL DEFAULT 'PHP',
  `status` enum('pending','failed','expired','succeeded','review_required') NOT NULL DEFAULT 'pending',
  `provider_status` varchar(60) NOT NULL DEFAULT 'initializing',
  `qr_image_url` longtext DEFAULT NULL,
  `failure_message` text DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `paymongo_qrph_internal_reference_unique` (`internal_reference`),
  UNIQUE KEY `paymongo_qrph_payment_intent_unique` (`payment_intent_id`),
  UNIQUE KEY `uq_paymongo_qrph_provider_payment_id` (`provider_payment_id`),
  UNIQUE KEY `uq_paymongo_qrph_payment_id` (`payment_id`),
  KEY `paymongo_qrph_loan_status` (`loan_id`,`status`),
  KEY `paymongo_qrph_borrower_created` (`borrower_user_id`,`created_at`),
  KEY `paymongo_qrph_collector_created` (`collector_id`,`created_at`),
  KEY `paymongo_qrph_expires_at` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `paymongo_qrph_webhook_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paymongo_qrph_webhook_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` varchar(100) NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `transaction_id` bigint(20) unsigned DEFAULT NULL,
  `provider_payment_id` varchar(100) DEFAULT NULL,
  `payment_intent_id` varchar(100) DEFAULT NULL,
  `amount_centavos` bigint(20) DEFAULT NULL,
  `currency` varchar(10) DEFAULT NULL,
  `provider_status` varchar(50) DEFAULT NULL,
  `livemode` tinyint(1) DEFAULT NULL,
  `payload_sha256` char(64) NOT NULL,
  `signature_verified` tinyint(1) NOT NULL DEFAULT 1,
  `processing_status` enum('processed','ignored','review_required') NOT NULL,
  `details` varchar(1000) DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_paymongo_qrph_webhook_event_id` (`event_id`),
  KEY `idx_paymongo_qrph_webhook_transaction` (`transaction_id`),
  KEY `idx_paymongo_qrph_webhook_received` (`received_at`),
  CONSTRAINT `fk_paymongo_qrph_webhook_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `paymongo_qrph_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `role` enum('Admin','Collector','Borrower') NOT NULL DEFAULT 'Borrower',
  `status` varchar(50) NOT NULL DEFAULT 'Active',
  `date_created` datetime NOT NULL DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `employee_id` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=45 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

