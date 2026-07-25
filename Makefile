# Project-scoped developer commands.
#
# Every target delegates to `docker compose`, which acts only on THIS project. No target
# touches an unrelated container, image, network or volume (Constitution V).

.DEFAULT_GOAL := help
.PHONY: help setup up down restart logs ps migrate shell psql redis-cli \
        build rebuild verify acceptance test test-backend test-frontend e2e \
        lint analyse style style-fix audit scan reset

help: ## Show this help
	@grep -hE '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

# --- lifecycle ---------------------------------------------------------------------------

setup: ## First run: create .env, build images, start everything and migrate
	@test -f .env || (cp .env.example .env && echo "created .env from .env.example")
	docker compose build
	docker compose up -d --wait
	docker compose exec app php yii migrate --interactive=0

up: ## Start the stack and wait until every service is healthy
	docker compose up -d --wait

down: ## Stop and remove this project's containers (data is KEPT)
	docker compose down

restart: ## Recreate the stateless services
	docker compose up -d --force-recreate app websocket nginx

build: ## Build images
	docker compose build

rebuild: ## Build images from scratch, ignoring the cache
	docker compose build --no-cache

logs: ## Follow application logs
	docker compose logs -f app websocket nginx

ps: ## Show service status
	docker compose ps

# --- database ----------------------------------------------------------------------------

migrate: ## Apply pending migrations
	docker compose exec app php yii migrate --interactive=0

psql: ## Open a psql shell
	docker compose exec postgres psql -U "$${POSTGRES_USER:-app}" -d "$${POSTGRES_DB:-app}"

redis-cli: ## Open a redis-cli shell
	docker compose exec redis redis-cli

shell: ## Open a shell in the application container
	docker compose exec app sh

# --- quality gates -------------------------------------------------------------------------

verify: ## Run every check CI runs (the one documented verification command)
	bash scripts/verify.sh

acceptance: ## Run the container-level acceptance checks (outages, SIGTERM, production mode)
	bash scripts/acceptance.sh

test: test-backend test-frontend e2e ## Run every test layer

test-backend: ## PHPUnit: unit, integration/contract, legacy parity
	docker compose exec app composer test

test-frontend: ## Vitest unit and component tests
	docker compose exec frontend npm test

e2e: ## Playwright end-to-end tests
	docker compose exec frontend npm run test:e2e

lint: ## ESLint and TypeScript
	docker compose exec frontend npm run lint
	docker compose exec frontend npm run typecheck

analyse: ## PHPStan
	docker compose exec app composer analyse

style: ## Check PHP coding style
	docker compose exec app composer style

style-fix: ## Fix PHP coding style
	docker compose exec app composer style:fix

audit: ## Composer and npm vulnerability audits
	docker compose exec app composer audit --abandoned=report
	docker compose exec frontend npm audit --audit-level=high

scan: ## Scan the repository and running stack for forbidden legacy components
	bash scripts/scan-forbidden.sh

# --- destructive (explicit, project-scoped) ------------------------------------------------

reset: ## DESTROYS this project's database volume, then rebuilds from scratch
	@echo "This deletes THIS project's PostgreSQL volume and every item stored in it."
	@echo "Unrelated Docker containers, images, networks and volumes are untouched."
	@printf 'Type "reset" to continue: ' && read answer && [ "$$answer" = "reset" ]
	docker compose down --volumes
	$(MAKE) setup
