# Prismarr — Brand Assets

Logo pour Prismarr : stack média Docker externalisée d'ARGOS (alternative UI Radarr/Sonarr).

## Concept

Cristal facetté diffractant un spectre décomposé en 12 rayons. Métaphore directe : une lumière unifiée qui se décompose en services (Radarr, Sonarr, Prowlarr, Jellyseerr…). Le wordmark reprend la même logique : "Prismarr" en indigo qui se transforme en spectre sur le suffixe "arr" de la famille.

## Inventaire

### Icône principale (fond transparent)
- `prismarr-icon.svg` — Source vectorielle
- `prismarr-icon-{64,128,256,512,1024}.png` — Toutes résolutions

### Icône version sombre (Docker Hub, GitHub dark)
- `prismarr-icon-dark.svg`
- `prismarr-icon-dark-{256,512,1024}.png`

### Logo horizontal version claire
- `prismarr-logo-horizontal.svg` (400×110)
- `prismarr-logo-horizontal.png` (800px)
- `prismarr-logo-horizontal-2x.png` (1600px retina)

### Logo horizontal version sombre
- `prismarr-logo-horizontal-dark.svg` (400×110)
- `prismarr-logo-horizontal-dark.png` (800px)
- `prismarr-logo-horizontal-dark-2x.png` (1600px retina)

### Favicons et app icons
- `favicon.svg` — Source vectorielle
- `favicon.ico` — Multi-résolution 16/32/48
- `favicon-{16,32,48,64}.png` — Navigateurs classiques
- `favicon-180.png` — apple-touch-icon
- `favicon-192.png` — PWA Android
- `favicon-512.png` — PWA splash / maskable

## Palette

- **Indigo ARGOS** : `#6366f1`
- **Facettes cristal** : `#3730a3` → `#818cf8`
- **Spectre (12 couleurs)** :
  `#ef4444` `#f97316` `#eab308` `#22c55e` `#14b8a6` `#06b6d4`
  `#3b82f6` `#6366f1` `#8b5cf6` `#a855f7` `#d946ef` `#ec4899`

## Intégration

### Symfony/Twig — base.html.twig

```twig
<link rel="icon" type="image/svg+xml" href="{{ asset('build/images/favicon.svg') }}">
<link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('build/images/favicon-180.png') }}">
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="{{ asset('build/images/prismarr-logo-horizontal-dark.svg') }}">
  <img src="{{ asset('build/images/prismarr-logo-horizontal.svg') }}" alt="Prismarr" height="60">
</picture>
```

### manifest.webmanifest (PWA)

```json
{
  "name": "Prismarr",
  "short_name": "Prismarr",
  "icons": [
    { "src": "/favicon-192.png", "sizes": "192x192", "type": "image/png" },
    { "src": "/favicon-512.png", "sizes": "512x512", "type": "image/png", "purpose": "any maskable" }
  ],
  "theme_color": "#6366f1",
  "background_color": "#0f0c29"
}
```

### README GitHub

```markdown
<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="./assets/prismarr-logo-horizontal-dark.png">
    <img src="./assets/prismarr-logo-horizontal.png" alt="Prismarr" width="400">
  </picture>
</p>

<p align="center">Unified media stack for Radarr, Sonarr & friends</p>
```

### Docker Hub

Utiliser `prismarr-icon-dark-512.png` comme logo du repository.
