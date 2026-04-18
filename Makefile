DEV_COMPOSE = docker compose --env-file symfony/.env.local -f docker-compose.yml -f docker-compose.dev.yml
COMPOSER    = docker run --rm -v "$(shell pwd)/symfony:/app" composer:2

# Dev (bind mounts live, APP_ENV=dev)
dev:
	$(DEV_COMPOSE) up -d

# Prod (images baked, APP_ENV=prod) — simule Portainer avec les vars locales
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
	docker logs argos_worker -f

# Logs app en temps réel
logs-app:
	docker logs argos_app -f

# Rebuild images prod sans cache
build:
	docker compose build --no-cache

# Installer un package Composer (ex: make composer require symfony/http-client)
composer:
	$(COMPOSER) $(filter-out $@,$(MAKECMDGOALS))

# Commande console Symfony dans le container dev (ex: make console cache:clear)
console:
	docker exec argos_app php bin/console $(filter-out $@,$(MAKECMDGOALS))

# Vérifier les handlers Messenger enregistrés
debug-messenger:
	docker exec argos_worker php bin/console debug:messenger

# Vérifier les tâches planifiées
debug-scheduler:
	docker exec argos_worker php bin/console debug:scheduler

%:
	@:
