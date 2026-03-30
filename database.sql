-- ============================================================
--  HandyFix — MySQL Schema
--  Database: handyfix
--  Import via phpMyAdmin or: mysql -u root -p handyfix < database.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS `handyfix`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `handyfix`;

-- ─── Users (admin, client, contractor) ──────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`         VARCHAR(120)  NOT NULL,
  `email`        VARCHAR(180)  NOT NULL UNIQUE,
  `password`     VARCHAR(255)  NOT NULL,          -- bcrypt hash
  `role`         ENUM('admin','client','contractor') NOT NULL DEFAULT 'client',
  `phone`        VARCHAR(30)   DEFAULT NULL,
  `address`      VARCHAR(255)  DEFAULT NULL,
  `avatar`       VARCHAR(255)  DEFAULT NULL,
  `status`       ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── Contractors (extends users where role='contractor') ─────
CREATE TABLE IF NOT EXISTS `contractors` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`        INT UNSIGNED NOT NULL UNIQUE,
  `trade`          VARCHAR(80)  NOT NULL,
  `specialization` VARCHAR(255) DEFAULT NULL,
  `experience`     TINYINT UNSIGNED DEFAULT 0,    -- years
  `daily_rate`     DECIMAL(10,2) DEFAULT 0.00,
  `rating`         DECIMAL(3,2) DEFAULT 0.00,
  `total_reviews`  INT UNSIGNED DEFAULT 0,
  `jobs_completed` INT UNSIGNED DEFAULT 0,
  `availability`   ENUM('available','busy','off') NOT NULL DEFAULT 'available',
  `verified`       TINYINT(1) NOT NULL DEFAULT 0,
  `bio`            TEXT DEFAULT NULL,
  `skills`         VARCHAR(500) DEFAULT NULL,     -- comma-separated
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── Service Requests / Bookings ────────────────────────────
CREATE TABLE IF NOT EXISTS `bookings` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `booking_ref`   VARCHAR(20)  NOT NULL UNIQUE,   -- e.g. BK-001
  `client_id`     INT UNSIGNED NOT NULL,
  `contractor_id` INT UNSIGNED DEFAULT NULL,
  `service`       VARCHAR(120) NOT NULL,
  `description`   TEXT         DEFAULT NULL,
  `address`       VARCHAR(255) DEFAULT NULL,
  `scheduled_date` DATE        DEFAULT NULL,
  `scheduled_time` TIME        DEFAULT NULL,
  `amount`        DECIMAL(10,2) DEFAULT 0.00,
  `status`        ENUM('pending','scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  `progress`      TINYINT UNSIGNED DEFAULT 0,     -- 0-100
  `notes`         TEXT         DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`client_id`)     REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`contractor_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── Messages ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `messages` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sender_id`   INT UNSIGNED NOT NULL,
  `receiver_id` INT UNSIGNED NOT NULL,
  `booking_id`  INT UNSIGNED DEFAULT NULL,
  `body`        TEXT         NOT NULL,
  `is_read`     TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`sender_id`)   REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`receiver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`booking_id`)  REFERENCES `bookings`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── Reviews ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `reviews` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `booking_id`    INT UNSIGNED NOT NULL UNIQUE,
  `client_id`     INT UNSIGNED NOT NULL,
  `contractor_id` INT UNSIGNED NOT NULL,
  `rating`        TINYINT UNSIGNED NOT NULL CHECK (`rating` BETWEEN 1 AND 5),
  `comment`       TEXT DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`booking_id`)    REFERENCES `bookings`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`client_id`)     REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`contractor_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── Activity Log ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `action`     VARCHAR(120) NOT NULL,
  `detail`     VARCHAR(255) DEFAULT NULL,
  `icon`       VARCHAR(10)  DEFAULT '🔔',
  `color`      VARCHAR(30)  DEFAULT '#E6AF2E',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── Booking Reference Auto-increment Sequence ───────────────
CREATE TABLE IF NOT EXISTS `booking_sequence` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `dummy` TINYINT DEFAULT 1
) ENGINE=InnoDB;

-- ─── Seed: first admin account ───────────────────────────────
-- Default login → email: admin@handyfix.com  password: Admin@123
-- bcrypt hash of "Admin@123" (cost 12)
INSERT IGNORE INTO `users` (`name`,`email`,`password`,`role`,`status`) VALUES (
  'Admin',
  'admin@handyfix.com',
  '$2y$12$eImiTXuWVxfM37uY4JANjQ==PLACEHOLDER_REPLACE_ON_FIRST_LOGIN',
  'admin',
  'active'
);
-- NOTE: The real hash is generated by PHP at first-run setup.
-- Run setup.php once after import to write the real bcrypt hash.