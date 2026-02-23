Quiz Backend (Laravel)

This repository contains the backend API for the quiz application. It is built with Laravel and provides authentication, quiz/example management, exam workflows, scoring, and mail notifications.

Tech stack

- PHP (Laravel)
- MySQL or other relational database
- Composer for PHP dependencies
- Node.js + npm for building frontend assets (optional)

Quick start

Prerequisites: PHP 8+, Composer, a database (MySQL/Postgres), and optionally Node.js for assets.

Install dependencies and configure environment:

```bash
composer install
cp .env.example .env
php artisan key:generate
# Update DB_ settings in .env
php artisan migrate --seed
```

Optional asset build:

```bash
npm install
npm run dev
```

Run locally:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Run tests:

```bash
php artisan test
```

APIs and configuration

- API routes are in `routes/api.php` and web routes in `routes/web.php`.
- Configuration files live in `config/*` (see `config/cloudinary.php`, `config/sanctum.php`).

Business logic (overview)

- Examples & Questions: Admins create `Example` records that include multiple `Question` entries. Examples represent quiz content users can take or preview.
- Exam Flow: Clients start exams, receive questions, submit answers, and the backend calculates and records `Score` entries.
- Scoring & Ranking: After submission the server computes results and rankings; frontend fetches these for leaderboards.
- User Actions: Interactions (favorites, attempts, actions) are logged using `UserAction` for history and analytics.
- Auth & Email: Authentication and email flows (OTP, forgot password) are implemented via Laravel mailables in `app/Mail` and Sanctum for API tokens.
- Admin: Admin-only endpoints are protected by middleware and role checks.

Project structure (selected)

- `app/Models`  Eloquent models (`Example`, `Question`, `Score`, `User`, `UserAction`, `Sector`).
- `app/Http/Controllers`  request handlers for API and web.
- `database/migrations` & `database/seeders`  schema and sample data.
- `routes`  endpoint definitions.
- `resources`  frontend assets and views.

Contributing

- Create issues or PRs; run tests and keep changes focused. Update migrations/seeders when altering data models.

License

See `composer.json` for license information.

---

This README provides a concise guide for backend developers. For frontend details, see the frontend project in the workspace.
