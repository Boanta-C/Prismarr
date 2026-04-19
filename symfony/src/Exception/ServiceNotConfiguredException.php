<?php

namespace App\Exception;

/**
 * Thrown by ConfigService when a third-party service (Radarr, TMDb…) is required
 * but has not yet been configured via the wizard or the administration page.
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
