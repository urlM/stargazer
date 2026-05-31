# Stargazer
Symfony app for browsing and refreshing top-starred public PHP repositories from GitHub.

## Setup (Reviewer Quickstart)
1. Start Docker Desktop.
2. From repo root, build and start the web app, worker, Redis, and database:
   - `docker compose up --build -d`
3. Install PHP dependencies:
   - `docker compose exec web composer install`
4. Run migrations:
   - `docker compose exec web php bin/console doctrine:migrations:migrate --no-interaction`
5. Open the app:
   - `http://localhost:8080`

Optional verification:
- `docker compose exec web php bin/console about`

## Docker Commands
- Start stack: `docker compose up -d`
- Rebuild and start: `docker compose up --build -d`
- Stop stack: `docker compose down`
- View logs: `docker compose logs -f`
- View worker logs: `docker compose logs -f worker`
- Restart worker: `docker compose restart worker`
- Open shell in web container: `docker compose exec web sh`

## Worker Commands
- Check worker container status: `docker compose ps worker`
- Tail worker logs: `docker compose logs -f worker`
- Restart the worker after config/code changes: `docker compose restart worker`
- Recreate just the worker service: `docker compose up -d --build worker`

## Database / Migration Commands
- Create migration: `docker compose exec web php bin/console make:migration`
- Run migrations: `docker compose exec web php bin/console doctrine:migrations:migrate --no-interaction`
- Migration status: `docker compose exec web php bin/console doctrine:migrations:status`

## Testing Commands
- Run full test suite:
  - `docker compose exec web php bin/phpunit`
- Run a single test file:
  - `docker compose exec web php bin/phpunit tests/Functional/RepositoryDetailWorkflowTest.php`

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

## Worker Troubleshooting
- Validate the composed services: `docker compose config`
- Check whether the worker is running: `docker compose ps worker`
- Inspect recent worker output: `docker compose logs --tail=100 worker`
- Verify Redis readiness: `docker compose exec redis redis-cli ping`
- Verify database readiness: `docker compose exec database mariadb-admin ping -h localhost --silent`
- Run the built-in infrastructure check: `docker compose exec web php bin/console app:worker:health`
- If the worker is stuck after dependency or env changes, recreate it: `docker compose up -d --build worker`

---

## Template
This project is built on the [VUMC VICTR Flagship Symfony Application Template](https://github.com/vumc-victr/flagship-template).

### Verify the template setup
After `docker compose up -d` and `docker compose exec web composer install`:
- `http://127.0.0.1:8080/test/` — confirms the template's DB connection and Test entity are working
- `http://127.0.0.1:8080/` — template home page

### Configure ports
Default ports: web on `8080`, database on `8306`. To override:
- `cp compose.override.yaml.dist compose.override.yaml`
- Edit `compose.override.yaml` with your preferred ports.

### Connect to the database directly
Host: `127.0.0.1`, Port: `8306`, Username: `root` (no password), Database: `app`
- `mysql -uroot -h127.0.0.1 -P8306 app`
