# Axiom Maintenance

Maintenance scheduling and tenant/technician texting for Axiom Maintenance. Rent Manager stays the system of record; this app reads new requests, books them automatically, texts tenants from one Axiom number, plans each tech's day, suggests parts, and writes results back to Rent Manager.

## How a request flows

1. A request arrives from Rent Manager (pulled every 2 minutes) or from the axiom-properties.com form (`POST /api/intake/requests`).
2. Triage sets trade, urgency, time on site and likely parts. Claude does this when `AI_TRIAGE_ENABLED=true`; keyword rules are the fallback, and an emergency keyword always wins.
3. Emergencies go straight to the on-call tech, with safety steps texted to the tenant and an alert to the office.
4. Routine jobs with permission to enter are booked automatically. Otherwise the tenant gets three time options and books by replying 1, 2 or 3.
5. Reminders go out the day before; "On my way" sends an ETA; completion asks for a 1-5 rating.
6. A job closes a day after completion. Its photos are copied from R2 into the Rent Manager work order, then deleted from R2 after `PHOTO_PURGE_DAYS`.

Anything the system can't settle lands in the **Queue** with a plain reason: low-confidence triage, no tenant reply, no opening, a tenant question, a low rating, a return visit, or an unmatched tenant.

## Roles

| Role | Sees |
| --- | --- |
| Admin | Dashboard, people and skills, locations on/off, everything below |
| Scheduler | Exception queue, day board, work order detail, booking, texting |
| Tech | Today's route, job detail, navigate, "On my way", photos, parts, complete |
| Tenant | Texts plus a no-login status link |

## Rent Manager locations

Seeded as Idaho (1, active), Arizona (2) and Utah (9). Turn locations on or off from the admin dashboard.

The Rent Manager endpoint names in `config/axiom.php` follow RM12 conventions and can be changed through `.env` (`RM_ENDPOINT_*`) once confirmed against `https://axiom.api.rentmanager.com/Help`. Run `php artisan rm:test` to check the login for every location.

## Deploying on Bluehost (shared hosting, cPanel)

1. **Subdomain or domain**: point `axiom-maintenance.com` (or a subdomain) at a folder such as `~/axiom-maintenance/public`. Only `public/` may be web-visible.
2. **PHP**: in cPanel > MultiPHP Manager, set PHP 8.3 or newer for that domain.
3. **Database**: cPanel > MySQL Databases: create a database and user, then put them in `.env` (`DB_CONNECTION=mysql`, `DB_HOST=localhost`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).
4. **Code**: over SSH, `git clone` this repo into `~/axiom-maintenance`, then:
   ```sh
   composer install --no-dev --optimize-autoloader
   cp .env.example .env   # then fill it in with the cPanel file editor
   php artisan key:generate
   php artisan migrate --force
   php artisan db:seed --force
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
5. **Cron** (cPanel > Cron Jobs, every minute):
   ```
   * * * * * cd ~/axiom-maintenance && php artisan schedule:run >> /dev/null 2>&1
   ```
   This one line runs Rent Manager sync, the job queue, reminders, route planning, closing and photo cleanup. No background daemons are needed.
6. **Twilio**: set the Axiom number's messaging webhook to `https://<domain>/webhooks/sms` and voice webhook to `https://<domain>/webhooks/voice` (HTTP POST). Set `SMS_DRIVER=twilio` once 10DLC is approved.
7. **Cloudflare R2**: create a bucket, an API token with read/write on it, and a CORS rule allowing `PUT` and `GET` from the app's domain.

Updating later: `git pull && composer install --no-dev -o && php artisan migrate --force && php artisan optimize`.

## Local development

```sh
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed --seeder=DemoSeeder   # demo techs, properties, tenants (password: demo-password)
php artisan serve
php artisan test
```

## Not done yet

- Confirm Rent Manager field and endpoint names against the live `/Help` pages (needs the API login).
- Google route optimization and geocoding (straight-line distances are used for now).
- Truck restock list and monthly parts forecast screens (the data is already recorded).
- Spanish text templates, if tenants need them.
