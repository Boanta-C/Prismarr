<p align="center">
  <img src="symfony/public/img/prismarr/prismarr-logo-horizontal.png" alt="Prismarr" width="420">
</p>

<p align="center">
  <strong>Un dashboard unifié pour votre stack médias self-hosted.</strong>
</p>

<p align="center">
  <a href="#fonctionnalités">Fonctionnalités</a> ·
  <a href="#installation">Installation</a> ·
  <a href="#configuration">Configuration</a> ·
  <a href="#roadmap">Roadmap</a> ·
  <a href="#licence">Licence</a>
</p>

---

## À propos

**Prismarr** centralise **qBittorrent**, **Radarr**, **Sonarr**, **Prowlarr**, **Jellyseerr** et **TMDb** dans une seule interface Symfony moderne. Plus besoin de jongler entre 6 onglets pour gérer votre bibliothèque.

Conçu pour les homelabs : installation en une commande, zéro configuration de base de données, une seule image Docker à jour.

> **Statut actuel** : version 0.1-alpha — en développement actif. API et UI peuvent évoluer avant la release 1.0.

---

## Fonctionnalités

### 🎬 Gestion médias unifiée
- Films (Radarr) et Séries (Sonarr) avec 5 modes de vue
- Recherche globale `Ctrl+K` transversale
- Modal d'ajout rapide depuis n'importe quelle page
- Calendrier unifié (sorties films + épisodes)

### 📥 Téléchargements
- Dashboard qBittorrent complet (pagination, filtres, tri serveur)
- Upload `.torrent` drag-and-drop multi-fichiers
- Badges pipeline : cliquer sur un torrent ouvre le film/série Radarr/Sonarr
- Toasts automatiques à la fin des téléchargements (cross-tab)

### 🔍 Découverte
- Page TMDb enrichie : hero, recommandations personnalisées, tendances
- Watchlist perso, Explorer (filtres genre/décennie/acteur)
- Countdown des sorties à venir
- Deep-links vers votre bibliothèque existante

### 🛡️ VPN monitoring
- Intégration Gluetun : IP publique, pays, port forwarded
- Vérification automatique que le port-update pousse bien vers qBittorrent

### 👥 Multi-utilisateurs
- Authentification Symfony Security
- Rôles : admin / user
- Tokens API

---

## Installation

### Prérequis

- Docker et Docker Compose
- Au moins un des services : qBittorrent, Radarr, Sonarr, Prowlarr, Jellyseerr
- (Optionnel) Gluetun si qBittorrent tourne derrière VPN

### Démarrage rapide

```bash
# 1. Clonez le dépôt
git clone https://github.com/joshua/prismarr.git
cd prismarr

# 2. Copiez le template d'env
cp .env.example .env

# 3. Lancez
docker compose up -d

# 4. Ouvrez http://localhost:7070
# Le setup wizard vous guidera pour configurer vos API keys
```

### Upgrade

```bash
docker compose pull
docker compose up -d
```

Les migrations SQLite sont appliquées automatiquement au démarrage.

---

## Configuration

Tout se configure via l'**UI setup wizard** au premier démarrage. Aucune variable d'environnement n'est nécessaire pour les API keys.

Le fichier `.env` ne contient que :
- `APP_SECRET` : clé secrète Symfony (auto-générée)
- `PRISMARR_PRIMARY_COLOR` : couleur d'accent UI (défaut indigo)
- `PRISMARR_LOGO_URL` : logo custom optionnel

Les données (SQLite, logs, uploads) sont persistées dans le volume `./data`.

---

## Roadmap

### v1.0 — Release publique
- [ ] Setup wizard complet
- [ ] Multi-utilisateurs (login, register, permissions)
- [ ] Documentation install / troubleshoot
- [ ] Tests d'intégration critiques
- [ ] Image Docker Hub officielle

### v1.x — Améliorations
- [ ] Widget Jellyfin (sessions live + stats)
- [ ] RSS feeds qBittorrent
- [ ] Graphiques de vitesse historiques
- [ ] API REST publique pour intégrations tierces
- [ ] Support MariaDB/PostgreSQL en option

### v2.0 — Automation
- [ ] Auto-import bibliothèque existante
- [ ] Règles de traitement customisées
- [ ] Notifications Discord / Ntfy / Telegram

---

## Stack technique

- **Backend** : PHP 8.4 / Symfony 8
- **Frontend** : Tabler UI + Alpine.js + Turbo (Hotwire)
- **BDD** : SQLite (zéro-config)
- **Cache** : Redis
- **Temps réel** : Mercure SSE

---

## Contribuer

Les contributions sont bienvenues ! Ouvrez une issue avant de commencer une PR pour discuter du scope.

Conventions :
- Commits en français ou anglais, clairs, atomiques
- Respecter les patterns Turbo-safe (`var`, IIFE, `.then()`)
- Tester en local avant PR

---

## Licence

[AGPL-3.0](LICENSE) — vous pouvez utiliser, modifier et distribuer Prismarr librement, y compris en production self-hosted. Les dérivés doivent rester open-source sous la même licence.

---

## Remerciements

Inspiré par les travaux remarquables de :
- [Overseerr / Jellyseerr](https://github.com/Fallenbagel/jellyseerr)
- La famille [Servarr](https://wiki.servarr.com/) (Radarr, Sonarr, Prowlarr, Bazarr…)
- [Tabler](https://tabler.io/) pour l'UI kit

Et merci à toute la communauté r/selfhosted 💙
