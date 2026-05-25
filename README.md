# Stargazer

Bootstrap for a Symfony-based GitHub repository browser focused on top-starred public PHP repositories.

## Current status

Slice 1 is now scaffolded and runnable. The repository includes:

- a `php:8.3-fpm` Docker image
- an `nginx` + `php` + `mariadb` Compose stack
- a Symfony 7.4 application scaffold
- Twig, Doctrine ORM, Doctrine Migrations, HttpClient, Monolog, and test tooling
- a minimal Symfony landing page served through nginx
- baseline environment defaults in `.env`

The next implementation step is the repository domain and persistence slice.

## First run

1. Start Docker Desktop.
2. From the repo root, run `docker compose up --build`.
3. Open `http://localhost:8080`.
4. Verify the app container with `docker compose exec php php bin/console about`.

## Local overrides

Create `.env.local` for machine-specific overrides such as:

- `APP_SECRET`
- `DATABASE_URL`
- `GITHUB_TOKEN`

`.env.local` is gitignored and should not be committed.

## Planned next step

Build the persistence slice:

- add the `Repository` entity
- create the first Doctrine migration
- implement the repository query layer
- render a DB-backed list page
