# Stargazer

Symfony app for browsing and refreshing top-starred public PHP repositories from GitHub.

## Setup (Reviewer Quickstart)

1. Start Docker Desktop.
2. From repo root, build and start services:
   - `docker compose up --build -d`
3. Install PHP dependencies in the container (if needed):
   - `docker compose run --rm php composer install`
4. Run migrations:
   - `docker compose run --rm php php bin/console doctrine:migrations:migrate --no-interaction`
5. Open the app:
   - `http://localhost:8080`

Optional verification:
- `docker compose exec php php bin/console about`

## Docker Commands

- Start stack: `docker compose up -d`
- Rebuild and start: `docker compose up --build -d`
- Stop stack: `docker compose down`
- View logs: `docker compose logs -f`
- Open shell in PHP container: `docker compose exec php sh`

## Database / Migration Commands

- Create migration: `docker compose run --rm php php bin/console make:migration`
- Run migrations: `docker compose run --rm php php bin/console doctrine:migrations:migrate --no-interaction`
- Migration status: `docker compose run --rm php php bin/console doctrine:migrations:status`

## Testing Commands

- Run full test suite:
  - `docker compose run --rm php php bin/phpunit`
- Run a single test file:
  - `docker compose run --rm php php bin/phpunit tests/Functional/RepositoryDetailWorkflowTest.php`

## GitHub Token Configuration (`GITHUB_TOKEN`)

The refresh flow reads `GITHUB_TOKEN` from environment config.

Preferred (project-local) option:
1. Create `.env.local` in repo root.
2. Add:
   - `GITHUB_TOKEN=ghp_your_token_here`
3. Restart services if already running:
   - `docker compose down && docker compose up -d`

Alternative local env override options:
- PowerShell (current terminal session):
  - `$env:GITHUB_TOKEN="ghp_your_token_here"`
- macOS/Linux shell (current terminal session):
  - `export GITHUB_TOKEN=ghp_your_token_here`

Notes:
- `.env.local` is gitignored and should never be committed.
- Use a fine-scoped personal access token with minimal required permissions.
