# Setup & Deployment Instructions

## 1. Requirements

- PHP 8.0+
- MySQL 5.7+ or MariaDB equivalent
- A local web server for development: XAMPP, Laragon, or `php -S` with a
  MySQL install

## 2. Local setup

1. Clone the repository into your server's web root
   (e.g. `htdocs/mulawin_fleetops` for XAMPP).
2. Copy `.env.example` to `.env`:
   ```
   cp .env.example .env
   ```
3. Edit `.env` with your local MySQL credentials and set:
   ```
   APP_BASE=/mulawin_fleetops
   ```
   (or whatever subfolder your local server serves the project from).
4. Create the database and import the schema:
   ```
   mysql -u root -p -e "CREATE DATABASE mulawin_fleetops"
   mysql -u root -p mulawin_fleetops < "db/Mulawin_DB-Phase 1"
   ```
5. Run any migrations in `db/` on top of the base schema:
   ```
   mysql -u root -p mulawin_fleetops < db/trip_costs_migration.sql
   mysql -u root -p mulawin_fleetops < db/vehicle_inspection_migration.sql
   ```
6. Seed initial user accounts:
   - Open `auth/seed_users.php` in a browser once (or run via CLI), then
     remove/protect it — it should not stay publicly reachable in production.
7. Visit `login.php` in your browser and sign in with a seeded account.

## 3. Deploying to Hostinger

Hostinger's shared/business hosting (via hPanel) supports PHP + MySQL
natively, so no code changes are needed beyond configuration.

1. **Create the database** — in hPanel, go to Databases → MySQL Databases,
   create a database and a database user, and note the host (usually
   `localhost`), database name, username, and password.
2. **Enable SSL** — in hPanel, go to SSL and enable the free SSL certificate
   for your domain. Do this before going live; the app's session cookies
   automatically become `secure` once traffic is served over HTTPS
   (see `includes/session.php`), so there's nothing else to flip manually.
3. **Set the PHP version** — in hPanel → Advanced → PHP Configuration,
   select PHP 8.x to match local development.
4. **Upload the code** — via Hostinger's File Manager, Git integration (if
   available on your plan), or FTP/SFTP. Upload everything except `.env`
   (create it directly on the server instead — never upload real credentials).
5. **Create `.env` on the server** with production values:
   ```
   DB_HOST=localhost
   DB_NAME=<your_hostinger_db_name>
   DB_USER=<your_hostinger_db_user>
   DB_PASS=<your_hostinger_db_password>
   DB_CHARSET=utf8mb4
   APP_BASE=/
   SESSION_NAME=mulawin_session
   SESSION_TIMEOUT=1800
   CSRF_TOKEN_NAME=csrf_token
   ```
   Set `APP_BASE=/` if the app is served from your domain root, or
   `/subfolder` if it's in a subfolder.
6. **Import the database** — use phpMyAdmin (available in hPanel) to import
   `db/Mulawin_DB-Phase 1` and the migration files, same as the local steps.
7. **Verify** — load the site over `https://` and confirm login works. Check
   that the session cookie is marked `Secure` in your browser's dev tools
   (Application → Cookies) to confirm HTTPS auto-detection is working.
8. **Lock down `auth/seed_users.php`** — remove it or restrict access before
   the site is public; it's a setup convenience, not something end users
   should be able to hit.

## 4. Notes

- Never commit a real `.env` file — it's gitignored on purpose.
- If DB errors show up in the browser instead of a generic message, check
  `error_log` location in your PHP config rather than displaying errors —
  `config/database.php` already logs failures server-side and returns a
  generic message to the client.