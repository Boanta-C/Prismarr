<?php

namespace App\Exception;

/**
 * Levée par ConfigService quand un service tiers (Radarr, TMDb…) est requis
 * mais n'a pas encore été configuré via le wizard ou la page d'administration.
 */
class ServiceNotConfiguredException extends \RuntimeException
{
    public function __construct(
        public readonly string $service,
        public readonly string $missingKey,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Le service "%s" n\'est pas configuré (clé manquante : %s).', $service, $missingKey),
            0,
            $previous,
        );
    }
}
