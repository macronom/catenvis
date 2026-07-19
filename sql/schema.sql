-- Catenvis – database schema (MariaDB / MySQL, utf8mb4)
--
-- Step 1: create database and user (run manually, replace <PASSWORD>).
--         On a remote server, replace '%' with the specific client address if needed.
--
--   CREATE DATABASE catenvis CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   CREATE USER 'catenvis'@'%' IDENTIFIED BY '<PASSWORD>';
--   GRANT ALL PRIVILEGES ON catenvis.* TO 'catenvis'@'%';
--   FLUSH PRIVILEGES;
--
-- Step 2: load this schema into the catenvis database, e.g.:
--   mysql -h <db-host> -u catenvis -p catenvis < sql/schema.sql
--
-- This file always reflects the CURRENT full schema - a fresh install needs
-- nothing else. Schema changes additionally ship as delta files in
-- sql/migrations/ so existing installations can catch up with
-- "php bin/migrate.php" (see sql/migrations/README.md).

SET NAMES utf8mb4;

-- User accounts. New users are created by the admin with a default password
-- (must_change_password = 1) and must change it on first login.
CREATE TABLE IF NOT EXISTS users (
	id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
	username             VARCHAR(64)  NOT NULL,
	password_hash        VARCHAR(255) NOT NULL,
	is_admin             TINYINT(1)   NOT NULL DEFAULT 0,
	must_change_password TINYINT(1)   NOT NULL DEFAULT 1,
	pref_sort            VARCHAR(16)  NOT NULL DEFAULT 'default',
	pref_view            VARCHAR(16)  NOT NULL DEFAULT 'grid',
	pref_lang            VARCHAR(8)   NOT NULL DEFAULT 'en',
	pref_titlelang       VARCHAR(16)  NOT NULL DEFAULT 'own',
	created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	last_login_at        DATETIME     NULL DEFAULT NULL,
	PRIMARY KEY (id),
	UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Global TMDB series cache. id = TMDB series id.
-- Translated titles/overviews live in series_translations.
CREATE TABLE IF NOT EXISTS series (
	id                  INT UNSIGNED NOT NULL,
	original_name       VARCHAR(255) NOT NULL DEFAULT '',
	original_language   VARCHAR(12)  NULL DEFAULT NULL,
	imdb_id             VARCHAR(20)  NULL DEFAULT NULL,
	first_air_year      SMALLINT UNSIGNED NULL DEFAULT NULL,
	poster_path         VARCHAR(255) NULL DEFAULT NULL,
	status              VARCHAR(64)  NULL DEFAULT NULL,
	networks            TEXT         NULL DEFAULT NULL,
	number_of_seasons   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
	number_of_episodes  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
	last_air_date       DATE         NULL DEFAULT NULL,
	next_air_date       DATE         NULL DEFAULT NULL,
	synced_at           DATETIME     NULL DEFAULT NULL,
	sync_error          VARCHAR(255) NULL DEFAULT NULL,
	sync_failed_at      DATETIME     NULL DEFAULT NULL,
	PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Translated series titles/overviews (all active user languages).
-- name = '' marks "no translation available at TMDB" (anti-loop marker).
CREATE TABLE IF NOT EXISTS series_translations (
	series_id INT UNSIGNED NOT NULL,
	lang      VARCHAR(8)   NOT NULL,
	name      VARCHAR(255) NOT NULL,
	overview  TEXT         NULL DEFAULT NULL,
	PRIMARY KEY (series_id, lang),
	CONSTRAINT fk_series_translations_series FOREIGN KEY (series_id) REFERENCES series (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Global episode cache. id = TMDB episode id. Titles live in episode_translations.
CREATE TABLE IF NOT EXISTS episodes (
	id             INT UNSIGNED NOT NULL,
	series_id      INT UNSIGNED NOT NULL,
	season_number  SMALLINT UNSIGNED NOT NULL,
	episode_number SMALLINT UNSIGNED NOT NULL,
	air_date       DATE         NULL DEFAULT NULL,
	runtime        SMALLINT UNSIGNED NULL DEFAULT NULL,
	PRIMARY KEY (id),
	UNIQUE KEY uq_episodes_number (series_id, season_number, episode_number),
	KEY idx_episodes_series (series_id),
	CONSTRAINT fk_episodes_series FOREIGN KEY (series_id) REFERENCES series (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Translated episode titles and descriptions. Base rows ('de') come from the
-- de-DE season fetch; every additional language costs one extra season fetch.
-- name = '' marks "no translation available at TMDB" (anti-loop marker).
CREATE TABLE IF NOT EXISTS episode_translations (
	episode_id INT UNSIGNED NOT NULL,
	lang       VARCHAR(8)   NOT NULL,
	name       VARCHAR(255) NOT NULL,
	overview   TEXT         NULL DEFAULT NULL,
	PRIMARY KEY (episode_id, lang),
	CONSTRAINT fk_episode_translations_episode FOREIGN KEY (episode_id) REFERENCES episodes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Follow status per user and series.
CREATE TABLE IF NOT EXISTS user_series (
	user_id    INT UNSIGNED NOT NULL,
	series_id  INT UNSIGNED NOT NULL,
	status     ENUM('following','stopped','deferred') NOT NULL DEFAULT 'following',
	added_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (user_id, series_id),
	KEY idx_user_series_series (series_id),
	CONSTRAINT fk_user_series_user   FOREIGN KEY (user_id)   REFERENCES users (id)  ON DELETE CASCADE,
	CONSTRAINT fk_user_series_series FOREIGN KEY (series_id) REFERENCES series (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Queue for the IMDb import (processed in the background).
CREATE TABLE IF NOT EXISTS import_queue (
	id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
	user_id      INT UNSIGNED NOT NULL,
	imdb_id      VARCHAR(20)  NOT NULL,
	title        VARCHAR(255) NULL DEFAULT NULL,
	mark_seen    TINYINT(1)   NOT NULL DEFAULT 0,
	status       ENUM('pending','processing','done','skipped','notfound','failed') NOT NULL DEFAULT 'pending',
	message      VARCHAR(255) NULL DEFAULT NULL,
	created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	processed_at DATETIME     NULL DEFAULT NULL,
	PRIMARY KEY (id),
	KEY idx_import_status (status),
	KEY idx_import_user (user_id),
	CONSTRAINT fk_import_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Watched episodes per user.
CREATE TABLE IF NOT EXISTS user_watched (
	user_id    INT UNSIGNED NOT NULL,
	episode_id INT UNSIGNED NOT NULL,
	watched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (user_id, episode_id),
	KEY idx_user_watched_episode (episode_id),
	CONSTRAINT fk_user_watched_user    FOREIGN KEY (user_id)    REFERENCES users (id)    ON DELETE CASCADE,
	CONSTRAINT fk_user_watched_episode FOREIGN KEY (episode_id) REFERENCES episodes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed login attempts (brute-force protection). Rows are pruned once they
-- age out of the lockout window; successful logins clear a username's rows.
CREATE TABLE IF NOT EXISTS login_attempts (
	id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
	ip           VARCHAR(45)  NOT NULL,
	username     VARCHAR(64)  NOT NULL,
	attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	KEY idx_login_attempts_ip (ip, attempted_at),
	KEY idx_login_attempts_username (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Applied schema migrations (see sql/migrations/README.md). bin/migrate.php
-- creates this table on existing installations if it is missing.
CREATE TABLE IF NOT EXISTS schema_migrations (
	migration  VARCHAR(255) NOT NULL,
	applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrations already folded into this file count as applied on a fresh
-- install. When folding in a migration, also add its marker here:
--   INSERT INTO schema_migrations (migration) VALUES ('NNN_description.sql');
INSERT INTO schema_migrations (migration) VALUES ('001_add_episode_overview.sql');
INSERT INTO schema_migrations (migration) VALUES ('002_mark_unavailable_series.sql');
INSERT INTO schema_migrations (migration) VALUES ('003_add_episode_runtime.sql');
