-- Wordle database schema (MySQL 8 / MariaDB 10.4+).
-- Run:  mysql -u root -p < database/schema.sql
CREATE DATABASE IF NOT EXISTS wordle CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE wordle;

CREATE TABLE IF NOT EXISTS users (
    user_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(20)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB;

-- Five-letter words. is_answer = 1 -> may be chosen as a target; every row is an allowed guess.
CREATE TABLE IF NOT EXISTS words (
    word      CHAR(5) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_answer TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (word),
    CONSTRAINT chk_words_format CHECK (word REGEXP '^[a-z]{5}$')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS games (
    game_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id          INT UNSIGNED NOT NULL,
    started_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at     DATETIME NULL,
    status           ENUM('in_progress','won','lost','abandoned') NOT NULL DEFAULT 'in_progress',
    attempts_used    TINYINT UNSIGNED NULL,
    score            INT UNSIGNED NOT NULL DEFAULT 0,
    duration_seconds INT UNSIGNED NULL,
    PRIMARY KEY (game_id),
    KEY idx_games_user_status (user_id, status),
    CONSTRAINT fk_games_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE,
    CONSTRAINT chk_games_attempts CHECK (attempts_used IS NULL OR attempts_used BETWEEN 1 AND 6),
    CONSTRAINT chk_games_score CHECK (score IN (0, 32, 64, 128, 256, 512, 1024))
) ENGINE=InnoDB;
