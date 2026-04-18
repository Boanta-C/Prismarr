DEV_COMPOSE = docker compose --env-file symfony/.env.local -f docker-compose.yml -f docker-compose.dev.yml
COMPOSER    = docker run --rm -v "$(shell pwd)/symfony:/app" composer:2

# Dev (bind mounts live, APP_ENV=dev)
dev:
	$(DEV_COMPOSE) up -d

# Prod (images baked)
prod:
	docker compose --env-file symfony/.env.local up -d

# Force-recreate app + worker en dev (après changement d'env/config)
restart:
	$(DEV_COMPOSE) up -d --force-recreate app worker

# Arrêt complet
stop:
	docker compose down

# Logs worker en temps réel
logs:
	docker logs prismarr_worker -f

# Logs app en temps réel
logs-app:
	docker logs prismarr_app -f

# Rebuild images prod sans cache
build:
	docker compose build --no-cache

# Installer un package Composer
composer:
	$(COMPOSER) $(filter-out $@,$(MAKECMDGOALS))

# Commande console Symfony dans le container dev
console:
	docker exec prismarr_app php bin/console $(filter-out $@,$(MAKECMDGOALS))

# Initialisation premier démarrage : créer la BDD SQLite + appliquer les migrations
init:
	docker exec prismarr_app mkdir -p var/data
	docker exec prismarr_app php bin/console doctrine:migrations:migrate --no-interaction

%:
	@:
