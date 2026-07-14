# Catenvis - server setup

One-time setup of the server. After that, every update is a single local
call to `deploy/deploy.sh`.

The app will be served under **http://<your-server>/catenvis/** and connects
to the MySQL database configured in `config/config.php`.

## 1. Create the database

Create a MySQL/MariaDB database and user and load the schema. The exact
statements and load command are documented in the header of
[`sql/schema.sql`](../sql/schema.sql) - it always contains the full current
schema, so a fresh install needs nothing else. Later updates apply schema
changes as deltas via `php bin/migrate.php` (see "Ongoing operation").

## 2. Prepare the server (via `ssh <your-server>`)

```bash
# Install Apache + PHP modules (PHP 8.3 required)
sudo apt update
sudo apt install apache2 libapache2-mod-php8.3 php8.3-mysql php8.3-curl

# Enable the rewrite module for the front controller
sudo a2enmod rewrite

# Create the target directory, owned by your deploy user so rsync needs no sudo
sudo mkdir -p /var/www/catenvis
sudo chown "$USER":"$USER" /var/www/catenvis

# Check required extensions
php -m | grep -E 'pdo_mysql|curl'
```

## 3. First deployment (from the development machine)

```bash
# Sync files (incl. vendor/, excluding config.php and logs)
deploy/deploy.sh

# Copy the configuration once (deploy.sh never overwrites it)
scp config/config.php <your-server>:/var/www/catenvis/config/
```

The config must have `'base_url' => '/catenvis'` and valid database
credentials (see `config/config.sample.php`).

## 4. Enable the Apache config (on the server)

```bash
sudo cp /var/www/catenvis/deploy/catenvis-server.conf /etc/apache2/conf-available/catenvis.conf
sudo a2enconf catenvis
sudo systemctl reload apache2
```

Browser test: http://<your-server>/catenvis/ → login page.

## 5. Set up the update cron (on the server)

```bash
sudo cp /var/www/catenvis/deploy/catenvis.cron /etc/cron.d/catenvis
sudo chown root:root /etc/cron.d/catenvis
sudo chmod 644 /etc/cron.d/catenvis

# Smoke test: run once by hand (checks DB + TMDB access); output goes to the terminal
php /var/www/catenvis/bin/update_followed.php
```

`catenvis.log` is created by the cron's output redirection, i.e. on the first
scheduled run. After that you can watch it with
`tail /var/www/catenvis/catenvis.log`.

## HTTPS (recommended for production)

The sample vhosts (`catenvis-server.conf`, `catenvis-localhost.conf`) serve
**HTTP only** - fine for a trusted LAN or local development, but a public
deployment should run behind TLS, because login credentials and the session
cookie would otherwise travel unencrypted.

The usual route on Debian/Ubuntu is a free Let's Encrypt certificate:

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache        # obtains a cert and adds the HTTPS vhost + HTTP->HTTPS redirect
```

Catenvis adapts to HTTPS on its own: it sends a `Strict-Transport-Security`
(HSTS) header and marks the session cookie `Secure` as soon as the request
arrives over HTTPS - no configuration needed. The other security headers (CSP,
`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`) are always
sent.

## Ongoing operation

After changing project files, a local call is enough:

```bash
deploy/deploy.sh
```

- `config/config.php` and `catenvis.log` on the server are left untouched.
- Database migrations are not applied automatically. After deploying a
  version that ships new files in `sql/migrations/`, run on the server:

  ```bash
  php /var/www/catenvis/bin/migrate.php --status   # read-only preview
  php /var/www/catenvis/bin/migrate.php            # apply pending migrations
  ```

  The command is idempotent and safe to re-run. If several instances share
  one database, deploy the new code everywhere first, then migrate once.
