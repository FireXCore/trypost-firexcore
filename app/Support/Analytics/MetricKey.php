<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use Illuminate\Support\Facades\Lang;

/**
 * Stable machine keys for analytics metrics.
 *
 * The platform analytics services label their metrics for humans, through
 * `__('analytics.metrics.*')`. That is correct for the web UI and useless for
 * an API consumer: the string changes with the viewer's locale, so a client
 * keying on "Impressions" silently collects nothing the moment the instance is
 * switched to another language.
 *
 * This resolves a rendered label back to the translation key it came from, so
 * the REST API can publish a locale-independent identifier alongside the human
 * label.
 *
 * The reverse map spans EVERY shipped locale, not just the API locale. The
 * analytics services memoise their results under a cache key that does not
 * include the locale, so a label rendered for a French web session is served
 * verbatim to the next API read within the TTL. Resolving only English labels
 * would turn that into silent, total data loss for the consumer: every metric
 * would arrive with a null key and be dropped. English is registered first, so
 * it wins any collision between languages.
 *
 * A label that does not resolve returns null, and callers must keep it null.
 * Guessing a key from an unrecognised label is how one platform's "Page views"
 * silently becomes another's "Views" on a dashboard.
 */
final class MetricKey
{
    /** @var array<string, string>|null */
    private static ?array $reverse = null;

    /**
     * The locale metric labels are rendered in when a machine is reading them.
     * Fixed rather than request-derived: an API response must not change shape
     * because of an Accept-Language header.
     */
    public const API_LOCALE = 'en';

    /**
     * Resolve a rendered metric label to its stable key, or null when the label
     * is not one this application produced from the metric catalogue (a raw
     * platform metric name, or a Telegram reaction emoji).
     */
    public static function resolve(string $label): ?string
    {
        $normalised = self::normalise($label);

        if ($normalised === '') {
            return null;
        }

        return self::reverseMap()[$normalised] ?? null;
    }

    /**
     * Label -> key, built once per process from the base-locale catalogue.
     *
     * @return array<string, string>
     */
    private static function reverseMap(): array
    {
        if (self::$reverse !== null) {
            return self::$reverse;
        }

        $reverse = [];

        foreach (self::locales() as $locale) {
            $catalogue = Lang::get('analytics.metrics', [], $locale);

            if (! is_array($catalogue)) {
                continue;
            }

            foreach ($catalogue as $key => $label) {
                if (! is_string($key) || ! is_string($label)) {
                    continue;
                }

                // The rendered label is the primary lookup. The key itself is
                // registered too so a service that already emits a raw metric
                // name matching the catalogue ("page_views") still resolves.
                //
                // ??=, so the first locale to claim a normalised label keeps
                // it and English (registered first) wins any cross-language
                // collision.
                $reverse[self::normalise($label)] ??= $key;
                $reverse[self::normalise($key)] ??= $key;
            }
        }

        return self::$reverse = $reverse;
    }

    /**
     * Shipped locales, API locale first.
     *
     * @return array<int, string>
     */
    private static function locales(): array
    {
        $path = lang_path();

        $found = is_dir($path)
            ? array_values(array_filter(
                scandir($path) ?: [],
                fn (string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($path.DIRECTORY_SEPARATOR.$entry),
            ))
            : [];

        return array_values(array_unique([self::API_LOCALE, ...$found]));
    }

    /**
     * Case, spacing, punctuation and unit suffixes are presentation. Comparing
     * on letters and digits alone means "Avg. View Duration (s)" and
     * "avg_view_duration" collapse to the same lookup.
     */
    private static function normalise(string $label): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($label));
    }

    /** Test seam: the catalogue is immutable at runtime, but tests swap locales. */
    public static function flush(): void
    {
        self::$reverse = null;
    }
}
