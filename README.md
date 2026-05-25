# Stargazer

Bootstrap for a Symfony-based GitHub repository browser focused on top-starred public PHP repositories.

## Current status

Slice 1 is in progress. The repository now includes:

- a `php:8.3-fpm` Docker image
- an `nginx` + `php` + `mariadb` Compose stack
- nginx routing aimed at `public/index.php`
- baseline environment defaults in `.env`
- a temporary PHP landing page for startup validation

The next blocking step is scaffolding the real Symfony 7.4 application and installing its dependencies.

## First run

1. Start Docker Desktop.
2. From the repo root, run `docker compose up --build`.
3. Open `http://localhost:8080`.

## Local overrides

Create `.env.local` for machine-specific overrides such as:

- `APP_SECRET`
- `DATABASE_URL`
- `GITHUB_TOKEN`

`.env.local` is gitignored and should not be committed.

## Planned next step

Once Docker is available for package installation, scaffold Symfony 7.4 and add the baseline bundles:

- Twig
- Doctrine ORM
- Doctrine Migrations
- Symfony HttpClient
- test tooling
