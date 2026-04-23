<?php

namespace App\Twig;

use App\Service\DisplayPreferencesService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Exposes the `display_*` preferences to templates. Usage:
 *
 *   {% set prefs = display_prefs() %}
 *   {{ prefs.theme_color_hex }}              {# "#6366f1" #}
 *   {{ display_pref('timezone') }}           {# "Europe/Paris" #}
 *   {{ item.date|prismarr_date }}            {# "21/04/2026" (per user format) #}
 *   {{ item.date|prismarr_time }}            {# "14:30" or "2:30 PM" #}
 *   {{ item.date|prismarr_datetime }}        {# "21/04/2026 · 14:30" #}
 */
class DisplayPreferencesExtension extends AbstractExtension
{
    public function __construct(
        private readonly DisplayPreferencesService $prefs,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('display_prefs', [$this->prefs, 'all']),
            new TwigFunction('display_pref', [$this, 'pref']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('prismarr_date',     [$this, 'filterDate']),
            new TwigFilter('prismarr_time',     [$this, 'filterTime']),
            new TwigFilter('prismarr_datetime', [$this, 'filterDateTime']),
        ];
    }

    public function filterDate(mixed $dt): ?string
    {
        return $this->prefs->formatDate($this->asDateTime($dt));
    }

    public function filterTime(mixed $dt): ?string
    {
        return $this->prefs->formatTime($this->asDateTime($dt));
    }

    public function filterDateTime(mixed $dt): ?string
    {
        return $this->prefs->formatDateTime($this->asDateTime($dt));
    }

    public function pref(string $key): mixed
    {
        return match ($key) {
            'home_page'            => $this->prefs->getHomePage(),
            'toasts'               => $this->prefs->areToastsEnabled(),
            'timezone'             => $this->prefs->getTimezone(),
            'date_format'          => $this->prefs->getDateFormat(),
            'time_format'          => $this->prefs->getTimeFormat(),
            'theme_color'          => $this->prefs->getThemeColor(),
            'theme_color_hex'      => $this->prefs->getThemeColorHex(),
            'theme_color_rgb'      => $this->prefs->getThemeColorRgb(),
            'qbit_refresh_seconds' => $this->prefs->getQbitRefreshSeconds(),
            'ui_density'           => $this->prefs->getUiDensity(),
            default                => null,
        };
    }

    /**
     * Accept DateTimeInterface, ISO string, or Unix timestamp — mirrors
     * Twig's native `|date` filter tolerance so callers can swap `|date`
     * for `|prismarr_date` without touching their data payloads.
     */
    private function asDateTime(mixed $dt): ?\DateTimeInterface
    {
        if ($dt === null || $dt === '') {
            return null;
        }
        if ($dt instanceof \DateTimeInterface) {
            return $dt;
        }
        try {
            if (is_int($dt)) {
                return (new \DateTimeImmutable('@' . $dt));
            }
            return new \DateTimeImmutable((string) $dt);
        } catch (\Throwable) {
            return null;
        }
    }
}
