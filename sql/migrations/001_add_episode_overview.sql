-- Add TMDB episode descriptions (overview), mirroring series_translations.overview.
-- Portable across MySQL and MariaDB (plain ADD COLUMN; the runner applies it once).
ALTER TABLE episode_translations
	ADD COLUMN overview TEXT NULL DEFAULT NULL AFTER name;
