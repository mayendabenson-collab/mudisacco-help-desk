# mudisacco-help-desk

NZERU Help Desk & Ticketing System for Mudi SACCO.

Built with PHP / Symfony, Doctrine ORM, and Bootstrap/Twig.

## Features
- Member support ticket submission & lifecycle tracking
- Department & category routing with SLA rules
- Multi-channel notification support
- Role-based access control (Admins, Support Agents, Branch Staff)
- Ready for deployment on Railway (PostgreSQL + Nixpacks)

## Deployment on Railway

1. Create a **PostgreSQL** database service on Railway.
2. Connect this repository to a new Railway web service.
3. Configure the environment variables in Railway according to `.env.example`:
   - `DATABASE_URL`: `${{Postgres.DATABASE_URL}}`
   - `APP_ENV`: `prod`
   - `APP_SECRET`: *(generate a 32-character hex secret)*
   - `MESSENGER_TRANSPORT_DSN`: `sync://`
   - `MAILER_DSN`: *(your SMTP DSN or `null://null`)*
   - `MAILER_FROM_ADDRESS`: `noreply@your-domain.com`
   - `MAILER_FROM_NAME`: `NZERU Help Desk`
   - `ADMIN_EMAIL`: `admin@your-domain.com`
4. The application automatically runs database migrations on startup via `railway.json`.
5. Seed initial data via the Railway CLI or dashboard console:
   ```bash
   php bin/console app:seed-foundation --admin-email=admin@your-domain.com --admin-password=YourSecurePassword123!
   ```
