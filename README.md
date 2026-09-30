# Career Toolkit

Career Toolkit is intended to become a private, local-first application for
maintaining canonical, structured career data and generating job-specific
resumes from it.

Longer term, it will likely include:

- Structured career facts with evidence/provenance
- Employers, roles, projects, skills, and metrics
- Job descriptions and job/application records
- AI-assisted job analysis and resume tailoring
- Human review of selected facts and generated wording
- Resume preview and deterministic PDF generation
- Potentially other career artifacts, such as cover letters and application
  responses

**Current status:** this repository is only an application foundation. The
career-data domain model (facts, employers, roles, skills, jobs,
applications, etc.) has intentionally not been designed yet. It will be
designed after reconciling the current resume (see `sources/resume/`) with
the author's portfolio.

## Stack

- Laravel 13
- PHP 8.5 (installed locally; requires PHP ^8.3 per `composer.json`)
- Inertia.js
- React 19 + TypeScript
- Tailwind CSS 4
- SQLite
- Vite
- Pest (testing)

Authentication scaffolding has intentionally been omitted for now, since
this is a local, single-user application.

## Local setup

```bash
composer install
npm install
cp .env.example .env   # if .env does not already exist
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

## Running the app

```bash
php artisan serve       # backend at http://127.0.0.1:8000
npm run dev              # Vite dev server (asset hot reload)
```

Or build assets for production and serve normally:

```bash
npm run build
php artisan serve
```

If a persistent browser-inspector worker is deployed on the LAN (see
`workers/browser-inspector/README.md` "Normal development
connectivity"), set `SERVER_HOST=0.0.0.0` in `.env` so `php artisan
serve`/`composer run dev` binds to all interfaces instead of just
`127.0.0.1` — ordinary LAN binding, not public exposure.

## Useful commands

```bash
php artisan test        # run the Pest test suite
npm run types:check      # TypeScript type checking
npm run check             # lint + format check (via vite-plus)
npm run check:fix         # lint + format, auto-fixing
```

## Project layout notes

- `sources/resume/` — holds the current resume, used as one source of truth
  during career-data reconciliation. Nothing else in the career-data domain
  has been modeled yet.
