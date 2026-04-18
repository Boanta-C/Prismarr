FROM nginx:1.25-alpine

# Config Nginx baked dans l'image (pas de bind mount)
RUN rm /etc/nginx/conf.d/default.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Assets Symfony publics baked dans l'image pour Portainer
# En dev, docker-compose.dev.yml surcharge avec un bind mount live
COPY symfony/public /var/www/html/public

EXPOSE 80
