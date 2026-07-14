# Database migrations

Delta files that bring an **existing** installation up to the current schema.
Fresh installs don't need them: [`sql/schema.sql`](../schema.sql) always
contains the full current schema (including markers for the migrations
already folded in).

## Running

```bash
php bin/migrate.php --status   # read-only: show applied/pending state
php bin/migrate.php            # apply all pending migrations
```

Applied migrations are tracked in the `schema_migrations` table, so the
command is idempotent and safe to re-run. Run it after every update that
ships new files in this directory. When one database is shared by several
instances, deploy the new code everywhere first, then migrate once.

## Naming

`NNN_short_description.sql` — zero-padded sequence number, next = highest + 1
(e.g. `001_add_episode_overview.sql`). Files are applied in byte-sorted
filename order.

## Rules

- One logical change per file.
- Plain SQL only: statements separated by `;`; `--`, `#` and `/* ... */`
  comments are fine. No `DELIMITER` switching (so no stored routines or
  triggers with compound bodies).
- **Never edit or rename a migration once it may have been applied
  somewhere** — the filename is its identity in `schema_migrations`.
- MySQL DDL is not transactional: if a statement fails mid-file, earlier
  statements of that file stay applied and the migration stays unrecorded.
  Prefer re-runnable statements (e.g. `ADD COLUMN IF NOT EXISTS` on
  MariaDB/MySQL 8+) so a fixed migration can simply be retried.
- **Every migration must also be folded into `sql/schema.sql`**: apply the
  same change to the table definitions there and add the marker line
  `INSERT INTO schema_migrations (migration) VALUES ('NNN_....sql');` so
  fresh installs don't re-apply it.
