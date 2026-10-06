-- OBSOLETE: historical MariaDB schema; never load into PostgreSQL.
-- LGU Project Tracker schema only. No user accounts or project data.
CREATE DATABASE IF NOT EXISTS `lgu_project_tracker` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `lgu_project_tracker`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(80) NOT NULL,
  `full_name` varchar(160) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','staff') NOT NULL DEFAULT 'staff',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `can_create` tinyint(1) NOT NULL DEFAULT 0,
  `can_edit` tinyint(1) NOT NULL DEFAULT 0,
  `can_import` tinyint(1) NOT NULL DEFAULT 0,
  `can_export` tinyint(1) NOT NULL DEFAULT 0,
  `can_delete` tinyint(1) NOT NULL DEFAULT 0,
  `can_delete_all` tinyint(1) NOT NULL DEFAULT 0,
  `can_resolve_alerts` tinyint(1) NOT NULL DEFAULT 0,
  `can_archive` tinyint(1) NOT NULL DEFAULT 0,
  `can_edit_alerts` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `profile_photo` varchar(255) DEFAULT NULL,
  `profile_photo_updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `attempt_key` char(64) NOT NULL,
  `failed_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `first_failed_at` datetime NOT NULL,
  `locked_until` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`attempt_key`),
  KEY `login_attempts_updated_idx` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `projects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  `project_no` varchar(60) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `project_status` varchar(100) DEFAULT NULL,
  `bidding_date` date DEFAULT NULL,
  `completion_date` date DEFAULT NULL,
  `noa_date` date DEFAULT NULL,
  `ntp_date` date DEFAULT NULL,
  `winning_bidder` varchar(255) DEFAULT NULL,
  `source_of_fund` varchar(160) DEFAULT NULL,
  `pr_number` varchar(120) DEFAULT NULL,
  `approved_budget` decimal(15,2) DEFAULT NULL,
  `bid_amount` decimal(15,2) DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `projects_project_no_unique` (`project_no`),
  KEY `projects_status_idx` (`project_status`),
  KEY `projects_bidding_date_idx` (`bidding_date`),
  KEY `projects_completion_date_idx` (`completion_date`),
  KEY `projects_project_no_idx` (`project_no`),
  KEY `projects_updated_by_fk` (`updated_by`),
  KEY `projects_project_status_idx` (`project_status`),
  KEY `projects_status_bidding_idx` (`project_status`,`bidding_date`),
  KEY `projects_archived_at_idx` (`archived_at`),
  CONSTRAINT `projects_updated_by_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `project_id` bigint(20) unsigned DEFAULT NULL,
  `event_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `history_updated_by_fk` (`updated_by`),
  KEY `history_project_event_idx` (`project_id`,`event_at`,`id`),
  CONSTRAINT `history_updated_by_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `application_settings` (
  `setting_key` varchar(80) NOT NULL,
  `setting_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`setting_value`)),
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`),
  KEY `application_settings_updated_by_fk` (`updated_by`),
  CONSTRAINT `application_settings_updated_by_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_id` int(10) unsigned DEFAULT NULL,
  `actor_username` varchar(80) NOT NULL,
  `action` varchar(80) NOT NULL,
  `entity_type` varchar(40) NOT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `summary` varchar(500) NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `audit_log_created_at_idx` (`created_at`),
  KEY `audit_log_entity_idx` (`entity_type`,`entity_id`),
  KEY `audit_log_actor_fk` (`actor_id`),
  CONSTRAINT `audit_log_actor_fk` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_number_sequences` (
  `sequence_name` varchar(40) NOT NULL,
  `next_number` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`sequence_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `import_jobs` (
  `batch_id` varchar(64) NOT NULL,
  `actor_id` int(10) unsigned DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'running',
  `processed_count` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `rolled_back_at` datetime DEFAULT NULL,
  PRIMARY KEY (`batch_id`),
  KEY `import_jobs_actor_idx` (`actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `import_job_changes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` varchar(64) NOT NULL,
  `project_id` bigint(20) unsigned NOT NULL,
  `change_type` enum('created','updated') NOT NULL,
  `previous_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`previous_data`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `import_changes_batch_idx` (`batch_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `project_number_sequences` (`sequence_name`, `next_number`) VALUES ('projects', 1);
