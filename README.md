<div align="center">

# 📺 Catenvis

**Keep track of your TV series — and never miss a new episode.**

A small, self-hosted web app to follow shows, mark episodes and seasons as
watched, and see at a glance which series have new episodes waiting.

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![Data: TMDB](https://img.shields.io/badge/Data-TMDB-01B4E4.svg)](https://www.themoviedb.org/)
[![Self-hosted](https://img.shields.io/badge/Self--hosted-✔-success.svg)](#installation)

</div>

<div align="center">
  <img src="docs/screenshot-dashboard.png" alt="Catenvis &ndash; the &quot;My series&quot; dashboard" width="800">
</div>

Series and episode data comes from [TMDB](https://www.themoviedb.org/).

<p align="center">
  <a href="https://www.themoviedb.org/"><img src="docs/tmdb.svg" alt="The Movie Database (TMDB)" height="24"></a>
</p>

> This product uses the TMDB API but is not endorsed or certified by TMDB.

## Features

- Follow series and track watched episodes per user
- Dashboard sorted by progress, with a badge for unseen aired episodes
- Mark single episodes, whole seasons, or a series as watched — including a
  one-click "watched" action right on the dashboard card
- Defer series you want to keep but watch later ("on hold")
- Add series via TMDB search; bulk import from an IMDb CSV export
- Multilingual content **and** interface (English, German, French, Spanish,
  Italian) with per-user language; the UI language is easy to extend via flat
  JSON catalogs in `lang/`
- Admin-managed accounts with forced first-login password change and
  brute-force protection on the login
- Daily background refresh of new episodes via cron

## Requirements

- PHP 8.3+ (extensions: `pdo_mysql`, `curl`)
- MySQL / MariaDB
- Apache with `mod_rewrite`
- A TMDB API key (v4 read access token or v3 API key)

## Installation

1. **Database:** create a database and user and load the schema — the exact
   statements are in the header of [`sql/schema.sql`](sql/schema.sql).
2. **Configuration:** copy `config/config.sample.php` to `config/config.php`
   (outside the web root, git-ignored) and fill in the database credentials,
   the TMDB key, and `base_url`.
3. **Dependencies:** run `composer install`.
4. **Web server & cron:** point the web root at `html/` and set up the daily
   update cron. A full walkthrough is in [`deploy/SETUP.md`](deploy/SETUP.md).
5. **First user:** create the initial admin with
   `php bin/create_user.php <username> <password> --admin`.

## Translations

UI translations are flat JSON files in `lang/` (English is the source
language and needs no file). To add a language, copy `lang/de.json` to
`lang/<code>.json`, set its `"__language__"` label, and translate the values;
it appears in the settings automatically. Check completeness with
`php bin/check_translations.php`. See [`lang/README.md`](lang/README.md).

## License

Catenvis is free software, licensed under the
[GNU General Public License v3.0](LICENSE).
</content>
</invoke>
