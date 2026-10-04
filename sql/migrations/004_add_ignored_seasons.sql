-- Seasons a user chose to ignore: they no longer count as open episodes
-- (dashboard badges, backlog, progress). Rows hang off user_series, so
-- removing a series for a user cleans them up via the cascade.
CREATE TABLE user_ignored_seasons (
	user_id       INT UNSIGNED      NOT NULL,
	series_id     INT UNSIGNED      NOT NULL,
	season_number SMALLINT UNSIGNED NOT NULL,
	PRIMARY KEY (user_id, series_id, season_number),
	CONSTRAINT fk_user_ignored_seasons_follow FOREIGN KEY (user_id, series_id)
		REFERENCES user_series (user_id, series_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
