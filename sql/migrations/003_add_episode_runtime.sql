-- Add TMDB per-episode runtime in minutes (for watch-time statistics).
-- Portable across MySQL and MariaDB (plain ADD COLUMN; the runner applies it once).
ALTER TABLE episodes
	ADD COLUMN runtime SMALLINT UNSIGNED NULL DEFAULT NULL AFTER air_date;
