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

> **Statut actuel** : version 1.0 en préparation — durcissement sécurité et polissage avant la publication publique.

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

### 🛡️ Sécurité
- Authentification Symfony Security + rate-limiter login (5 tentatives / 15 min)
- Container non-root, Content Security Policy dynamique
- Garde SSRF sur les URLs configurées par l'utilisateur

---

## Installation

### Prérequis

- Docker et Docker Compose
- Au moins un des services : qBittorrent, Radarr, Sonarr, Prowlarr, Jellyseerr
- (Optionnel) Gluetun si qBittorrent tourne derrière VPN

### Démarrage rapide

```bash
# 1. Téléchargez le docker-compose.yml
curl -O https://raw.githubusercontent.com/joshuabv2005/prismarr/main/docker-compose.example.yml
mv docker-compose.example.yml docker-compose.yml

# 2. Lancez
docker compose up -d

# 3. Ouvrez http://localhost:7070
# Le setup wizard guide la création du compte admin et la configuration
# des services (TMDb, Radarr, Sonarr, Prowlarr, Jellyseerr, qBittorrent).
```

Les secrets (`APP_SECRET`, `MERCURE_JWT_SECRET`) sont auto-générés au premier
démarrage et persistés dans le volume `prismarr_data`. Aucune configuration
manuelle n'est requise.

### Upgrade

```bash
docker compose pull
docker compose up -d
```

Les migrations SQLite sont appliquées automatiquement au démarrage.

---

## Configuration

Tout se configure via l'**UI setup wizard** au premier démarrage. Aucune variable d'environnement n'est nécessaire pour les clés API — elles sont stockées dans la base SQLite (table `setting`) et peuvent être modifiées après coup depuis l'interface.

Variables d'environnement optionnelles (`docker-compose.yml`) :

- `APP_ENV=prod` (défaut) — basculer en `dev` uniquement pour le développement
- `PRISMARR_PORT=7070` (défaut) — port exposé
- `TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR` (défaut) — à ajuster si derrière Traefik, nginx, Cloudflare Tunnel

Les données (SQLite, logs, sessions) sont persistées dans le volume Docker `prismarr_data`.

### Mot de passe admin oublié

```bash
docker exec -it prismarr php bin/console app:user:reset-password <email>
```

---

## Roadmap

### v1.0 — Release publique (en cours)
- [x] Setup wizard 7 étapes
- [x] Authentification + rate-limiter login
- [x] Migrations Doctrine (upgrades propres)
- [x] Suite PHPUnit (~100 tests)
- [x] Image Docker multi-arch
- [ ] Traduction anglaise (EN/FR switcher)
- [ ] Page admin paramètres
- [ ] Publication Docker Hub

### v1.x — Améliorations
- [ ] Multi-utilisateurs avec rôles distincts (lecture seule vs admin)
- [ ] Widget Jellyfin (sessions live + stats)
- [ ] Notifications Discord / Ntfy / Telegram
- [ ] Graphiques de vitesse historiques
- [ ] API REST publique pour intégrations tierces

### v2.0 — Automation
- [ ] Auto-import bibliothèque existante
- [ ] Règles de traitement customisées
- [ ] Support MariaDB / PostgreSQL en option

---

## Stack technique

- **Backend** : PHP 8.4 / Symfony 8
- **Serveur** : FrankenPHP (Caddy + PHP embed, worker mode) avec s6-overlay
- **Frontend** : Tabler UI + Alpine.js + Turbo (Hotwire) via AssetMapper
- **BDD** : SQLite (zéro-config, migrations Doctrine automatiques)
- **Cache + sessions** : filesystem (pas de Redis requis)
- **Queue** : Symfony Messenger (transport Doctrine)
- **Temps réel** : Mercure SSE intégré à Caddy

---

## Contribuer

Les contributions sont bienvenues — ouvrez une issue pour discuter du scope avant d'attaquer une PR.

- **Guide contributeur** : voir [CONTRIBUTING.md](CONTRIBUTING.md) (checklist Definition of Done + règles d'or)
- **Code of Conduct** : voir [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) (Contributor Covenant v2.1)
- **Vulnérabilité de sécurité** : voir [SECURITY.md](SECURITY.md) — **ne pas ouvrir d'issue publique**, contact par email
- **Historique** : voir [CHANGELOG.md](CHANGELOG.md)

Avant tout commit : `make check` (lint PHP + lint Twig + suite PHPUnit complète).

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
