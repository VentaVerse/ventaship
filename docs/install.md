# Installing Ventaship

This guide installs Ventaship on your own server. It takes about 20 minutes.

## What you need

- A Linux server or hosting panel (for example HestiaCP, cPanel, Forge, or a plain VPS) with:
  - **PHP 8.2 or newer** with the extensions `bcmath`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `curl`, `xml`, `fileinfo` and `tokenizer`
  - **MySQL 8 or MariaDB 10.6+**
  - **Composer**
  - **Node.js 20+** and npm (only to build the screens; not needed after)
- A domain or subdomain with **HTTPS**, for example `ship.yourshop.com`. Couriers send status updates to it, so it must be reachable from the internet.
- Your courier API keys (QuadX, SPX and/or LBC). You can add them later.

## 1. Get the code

```bash
git clone https://github.com/VentaVerse/ventaship.git
cd ventaship
```

## 2. Install and build

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

## 3. Create the settings file

```bash
cp .env.example .env
php artisan key:generate
```

Open `.env` and fill in:

| Setting | What to put |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | your address, e.g. `https://ship.yourshop.com` |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | the MySQL database you created for Ventaship |
| `VENTASHIP_VAULT_KEY` | a new random key (see below) |
| `TRUSTED_PROXIES` | the address of your reverse proxy if there is one, e.g. `127.0.0.1` when nginx runs on the same server; leave empty otherwise |

Create the vault key with:

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

**Keep a copy of `VENTASHIP_VAULT_KEY` somewhere safe, apart from your backups** (a password manager is ideal). It locks your courier keys and API keys inside the database. Without it, a restored backup cannot use them.

## 4. Create the tables

```bash
php artisan migrate --force
```

## 5. Create your admin account

```bash
php artisan ventaship:admin you@yourshop.com --username=admin
```

It asks for a password twice (at least 12 characters). There is no default login.

Forgot it later? Run the same command with `--reset`.

## 6. Point the website at Ventaship

Set your domain's document root to the `public` folder inside `ventaship`, and turn on HTTPS.

Then speed things up:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Run these three again whenever you change `.env` or update Ventaship.

## 7. Add the cron line

Ventaship has background work: sending updates to your store, checking courier statuses, and retrying. One cron line runs all of it. Sign in, go to **Settings → Scheduled jobs**, copy the **Cron line**, and add it to your server's cron (in a hosting panel: *Cron Jobs*, every minute).

It looks like this:

```
* * * * * cd /path/to/ventaship && php artisan schedule:run >> /dev/null 2>&1
```

After a minute, **Last run** on the same page turns green.

## 8. Set up your couriers

Go to **Courier accounts**, open a courier, choose **Sandbox** or **Production**, paste its keys and press **Save and test**.

Each courier page shows a **webhook address**. Give it to the courier so they can send status updates. For SPX it is `https://ship.yourshop.com/webhooks/spx`.

## 9. Connect your store (optional)

Go to **API**, press **Create key**, and enter its key, the **Base URL** and your store's webhook URL in your store. See [docs/api.md](api.md) for what the API offers.

## Updating

```bash
cd ventaship
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## Moving to another server

Copy the database **and** the same `VENTASHIP_VAULT_KEY`. Everything else can be set up fresh.
