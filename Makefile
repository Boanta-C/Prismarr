DEV_COMPOSE = docker compose --env-file symfony/.env.local -f docker-compose.yml -f docker-compose.dev.yml
COMPOSER    = docker run --rm -v "$(shell pwd)/symfony:/app" composer:2

# Dev (bind mounts live, APP_ENV=dev)
dev:
	$(DEV_COMPOSE) up -d --build

# Prod (image baked)
prod:
	docker compose --env-file symfony/.env.local up -d --build

# Force-recreate the container after env/config change (rebuild included)
restart:
	$(DEV_COMPOSE) up -d --build --force-recreate prismarr

# Full stop
stop:
	docker compose down

# Container logs (FrankenPHP + worker interleaved)
logs:
	docker logs prismarr -f

# Rebuild image without cache
build:
	$(DEV_COMPOSE) build --no-cache

# Install a Composer package
composer:
	$(COMPOSER) $(filter-out $@,$(MAKECMDGOALS))

# Symfony console command inside the container
console:
	docker exec prismarr php bin/console $(filter-out $@,$(MAKECMDGOALS))

# First-boot initialization: create the SQLite DB
init:
	docker exec prismarr mkdir -p var/data
	docker exec prismarr php bin/console doctrine:schema:create --no-interaction

%:
	@:
