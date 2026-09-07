<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Models\SocialAccount;
use App\Services\Social\Telegram\TelegramAnalytics;
use App\Support\Analytics\MetricKey;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

/**
 * Account-level analytics, in one place.
 *
 * The per-platform dispatch used to live inline in the web AnalyticsController.
 * The REST API needs exactly the same dispatch, and a second copy would drift:
 * a platform added to one list and not the other reads as "this account has no
 * analytics" rather than as the omission it is.
 *
 * @see \App\Services\Post\PostMetricsFetcher for the post-level equivalent.
 */
class AccountAnalyticsResolver
{
    /**
     * Platforms with an account-level analytics implementation. A platform
     * absent from this list is reported as unsupported, never as zero.
     *
     * @var array<int, Platform>
     */
    public const SUPPORTED_PLATFORMS = [
        Platform::TikTok,
        Platform::Instagram,
        Platform::InstagramFacebook,
        Platform::Threads,
        Platform::Facebook,
        Platform::X,
        Platform::LinkedInPage,
        Platform::Pinterest,
        Platform::YouTube,
        Platform::Telegram,
    ];

    public static function supports(Platform $platform): bool
    {
        return in_array($platform, self::SUPPORTED_PLATFORMS, true);
    }

    /**
     * Rendered, human-labelled metrics — the shape the web UI consumes.
     *
     * An unreachable platform yields an empty list rather than a 500: the page
     * the user just opened should not fail because a third party is down. The
     * catch is deliberately narrow; catching Throwable here would render a
     * defect in our own code as "this account has no activity".
     *
     * @return array<int, array{label: string, value: int|string}>
     */
    public function forAccount(SocialAccount $account, ?Carbon $since = null, ?Carbon $until = null): array
    {
        try {
            return match ($account->platform) {
                Platform::TikTok => app(TikTokAnalytics::class)->getMetrics($account),
                Platform::Instagram, Platform::InstagramFacebook => app(InstagramAnalytics::class)->getMetrics($account, $since, $until),
                Platform::Threads => app(ThreadsAnalytics::class)->getMetrics($account, $since, $until),
                Platform::Facebook => app(FacebookAnalytics::class)->getMetrics($account, $since, $until),
                Platform::X => app(XAnalytics::class)->getMetrics($account, $since, $until),
                Platform::LinkedInPage => app(LinkedInPageAnalytics::class)->getMetrics($account, $since, $until),
                Platform::Pinterest => app(PinterestAnalytics::class)->getMetrics($account, $since, $until),
                Platform::YouTube => app(YouTubeAnalytics::class)->getMetrics($account, $since, $until),
                Platform::Telegram => app(TelegramAnalytics::class)->getMetrics($account),
                default => [],
            };
        } catch (PlatformUnavailableException|ConnectionException $e) {
            report($e);

            return [];
        }
    }

    /**
     * Machine-readable metrics for the REST API: every entry carries the stable
     * catalogue key alongside the human label.
     *
     * Rendered in the fixed API locale so the key lookup is deterministic — the
     * underlying services translate their labels with the active locale, and an
     * instance running in another language would otherwise resolve nothing.
     *
     * `key` is null when the label is not in the metric catalogue (a raw
     * platform metric name, a Telegram reaction emoji). It stays null: a
     * consumer must be able to tell "we do not know what this is" from a
     * confident mislabelling.
     *
     * @return array<int, array{key: string|null, label: string, value: int|float|string}>
     */
    public function forAccountKeyed(SocialAccount $account, ?Carbon $since = null, ?Carbon $until = null): array
    {
        $previous = App::getLocale();

        try {
            App::setLocale(MetricKey::API_LOCALE);

            $metrics = $this->forAccount($account, $since, $until);
        } finally {
            App::setLocale($previous);
        }

        $keyed = [];

        foreach ($metrics as $metric) {
            $label = data_get($metric, 'label');
            $value = data_get($metric, 'value');

            if (! is_string($label) || $value === null) {
                continue;
            }

            $key = MetricKey::resolve($label);

            if ($key === null) {
                Log::debug('Unmapped account analytics label', [
                    'platform' => $account->platform?->value,
                    'label' => $label,
                ]);
            }

            $keyed[] = [
                'key' => $key,
                'label' => $label,
                'value' => $value,
            ];
        }

        return $keyed;
    }
}
