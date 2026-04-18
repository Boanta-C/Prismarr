#!/command/with-contenv sh
# Prismarr — script d'initialisation exécuté UNE fois au démarrage du container,
# avant frankenphp et messenger-worker (dépendance s6).
#
# Rôles :
#   1. S'assurer que le répertoire var/data (volume) existe et est writable.
#   2. Générer un .env.local avec secrets aléatoires au premier démarrage,
#      persisté dans le volume pour survivre aux rebuilds de l'image.
#   3. Créer le schéma SQLite si la BDD est vide.
#
# Idempotent : on peut le relancer autant qu'on veut, il ne refait rien s'il
# n'y a rien à faire.

set -e

APP_DIR="/var/www/html"
DATA_DIR="$APP_DIR/var/data"
ENV_STORED="$DATA_DIR/.env.local"
ENV_LINK="$APP_DIR/.env.local"
DB_FILE="$DATA_DIR/prismarr.db"

echo "[prismarr-init] Starting…"

# ── 1. Volume prêt et writable ──────────────────────────────────────────────
mkdir -p "$DATA_DIR" "$APP_DIR/var/cache" "$APP_DIR/var/log" "$APP_DIR/var/sessions"
chown -R www-data:www-data "$APP_DIR/var"

# ── 2. Génération des secrets au premier démarrage ──────────────────────────
if [ ! -f "$ENV_STORED" ]; then
  echo "[prismarr-init] Première exécution : génération des secrets (.env.local)."

  APP_SECRET=$(openssl rand -hex 32)
  MERCURE_JWT_SECRET=$(openssl rand -hex 32)
  JWT_PASSPHRASE=$(openssl rand -hex 32)

  cat > "$ENV_STORED" <<EOF
# Secrets générés automatiquement au premier démarrage de Prismarr.
# Persistés dans le volume (prismarr_data). Ne pas committer, ne pas éditer
# à la main sauf pour régénérer.

APP_SECRET=$APP_SECRET
MERCURE_JWT_SECRET=$MERCURE_JWT_SECRET
JWT_PASSPHRASE=$JWT_PASSPHRASE
EOF

  chown www-data:www-data "$ENV_STORED"
  chmod 600 "$ENV_STORED"
fi

# Symlink .env.local → volume seulement si rien n'existe déjà à la racine.
# En dev (bind-mount du projet), on respecte le .env.local local du dev.
if [ ! -e "$ENV_LINK" ]; then
  ln -s "$ENV_STORED" "$ENV_LINK"
fi

# ── 3. Schéma BDD si SQLite vide ────────────────────────────────────────────
need_schema=0
if [ ! -f "$DB_FILE" ] || [ ! -s "$DB_FILE" ]; then
  need_schema=1
fi

if [ "$need_schema" = "1" ]; then
  echo "[prismarr-init] BDD absente, création du schéma SQLite…"
  cd "$APP_DIR"
  php bin/console doctrine:schema:create --no-interaction 2>/dev/null || \
    php bin/console doctrine:schema:update --force --no-interaction
  chown www-data:www-data "$DB_FILE" 2>/dev/null || true
fi

echo "[prismarr-init] Ready."
