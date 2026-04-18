DEV_COMPOSE = docker compose --env-file symfony/.env.local -f docker-compose.yml -f docker-compose.dev.yml
COMPOSER    = docker run --rm -v "$(shell pwd)/symfony:/app" composer:2

# Dev (bind mounts live, APP_ENV=dev)
dev:
	$(DEV_COMPOSE) up -d --build

# Prod (image baked)
prod:
	docker compose --env-file symfony/.env.local up -d --build

# Force-recreate le container après changement d'env/config (rebuild inclus)
restart:
	$(DEV_COMPOSE) up -d --build --force-recreate prismarr

# Arrêt complet
stop:
	docker compose down

# Logs du container (FrankenPHP + worker mélangés)
logs:
	docker logs prismarr -f

# Rebuild image sans cache
build:
	$(DEV_COMPOSE) build --no-cache

# Installer un package Composer
composer:
	$(COMPOSER) $(filter-out $@,$(MAKECMDGOALS))

# Commande console Symfony dans le container
console:
	docker exec prismarr php bin/console $(filter-out $@,$(MAKECMDGOALS))

# Initialisation premier démarrage : créer la BDD SQLite
init:
	docker exec prismarr mkdir -p var/data
	docker exec prismarr php bin/console doctrine:schema:create --no-interaction

%:
	@:
