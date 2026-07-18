-- Mark series that TMDB no longer serves (permanent 404/410 - removed or
-- merged) so the nightly sync can skip them and surface them to their
-- followers. Portable across MySQL and MariaDB (plain ADD COLUMN).
ALTER TABLE series
	ADD COLUMN sync_error     VARCHAR(255) NULL DEFAULT NULL,
	ADD COLUMN sync_failed_at DATETIME     NULL DEFAULT NULL;
