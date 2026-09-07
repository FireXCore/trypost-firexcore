<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\XAnalytics;
use App\Support\Analytics\MetricKey;

beforeEach(function () {
    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->plainToken = $result['plain_token'];
});

function analyticsAccount(Workspace $workspace, Platform $platform = Platform::X): SocialAccount
{
    return SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => $platform,
        'status' => Status::Connected,
        'token_expires_at' => now()->addHours(2),
    ]);
}

it('returns account analytics with stable machine keys', function () {
    $account = analyticsAccount($this->workspace);

    $this->mock(XAnalytics::class)
        ->shouldReceive('getMetrics')
        ->once()
        ->andReturn([
            ['label' => __('analytics.metrics.impressions'), 'value' => 1200],
            ['label' => __('analytics.metrics.likes'), 'value' => 34],
        ]);

    $response = $this->getJson(route('api.social-accounts.analytics', $account), [
        'Authorization' => "Bearer {$this->plainToken}",
    ]);

    $response->assertOk();
    $response->assertJsonPath('supported', true);
    $response->assertJsonPath('platform', 'x');
    $response->assertJsonPath('metrics.0.key', 'impressions');
    $response->assertJsonPath('metrics.0.value', 1200);
    $response->assertJsonPath('metrics.1.key', 'likes');
});

it('leaves the key null for a label outside the metric catalogue', function () {
    $account = analyticsAccount($this->workspace);

    // A raw platform metric name, the shape several providers fall back to.
    $this->mock(XAnalytics::class)
        ->shouldReceive('getMetrics')
        ->andReturn([['label' => 'Page impressions organic v2', 'value' => 9]]);

    $response = $this->getJson(route('api.social-accounts.analytics', $account), [
        'Authorization' => "Bearer {$this->plainToken}",
    ]);

    $response->assertOk();
    // Null, never a guess: a consumer must be able to drop what it cannot
    // identify rather than file it under a metric it might not be.
    $response->assertJsonPath('metrics.0.key', null);
    $response->assertJsonPath('metrics.0.label', 'Page impressions organic v2');
});

it('resolves keys even when a cached label was rendered in another locale', function () {
    $account = analyticsAccount($this->workspace);

    // The analytics services cache on account + window, NOT on locale, so a
    // label rendered for a French web session is what the next API read gets.
    $this->mock(XAnalytics::class)
        ->shouldReceive('getMetrics')
        ->andReturn([['label' => __('analytics.metrics.followers', [], 'fr'), 'value' => 88]]);

    $response = $this->getJson(route('api.social-accounts.analytics', $account), [
        'Authorization' => "Bearer {$this->plainToken}",
    ]);

    $response->assertOk();
    $response->assertJsonPath('metrics.0.key', 'followers');
});

it('reports an unsupported platform instead of an empty metric list', function () {
    $account = analyticsAccount($this->workspace, Platform::Bluesky);

    $response = $this->getJson(route('api.social-accounts.analytics', $account), [
        'Authorization' => "Bearer {$this->plainToken}",
    ]);

    $response->assertOk();
    // Empty metrics would be indistinguishable from "no activity", and the
    // consumer would store a real zero for something nobody measured.
    $response->assertJsonPath('supported', false);
    $response->assertJsonPath('reason', 'platform_not_supported');
    $response->assertJsonPath('metrics', []);
});

it('passes the requested window through to the platform service', function () {
    $account = analyticsAccount($this->workspace);

    $this->mock(XAnalytics::class)
        ->shouldReceive('getMetrics')
        ->once()
        ->withArgs(function (SocialAccount $received, $since, $until) use ($account): bool {
            return $received->is($account)
                && $since?->toDateString() === '2026-09-01'
                && $until?->toDateString() === '2026-09-07';
        })
        ->andReturn([]);

    $this->getJson(
        route('api.social-accounts.analytics', $account).'?since=2026-09-01&until=2026-09-07',
        ['Authorization' => "Bearer {$this->plainToken}"],
    )->assertOk();
});

it('rejects a window whose end precedes its start', function () {
    $account = analyticsAccount($this->workspace);

    $this->getJson(
        route('api.social-accounts.analytics', $account).'?since=2026-09-07&until=2026-09-01',
        ['Authorization' => "Bearer {$this->plainToken}"],
    )->assertStatus(422);
});

it('rejects an unbounded window', function () {
    $account = analyticsAccount($this->workspace);

    $this->getJson(
        route('api.social-accounts.analytics', $account).'?since=2000-01-01&until=2026-09-07',
        ['Authorization' => "Bearer {$this->plainToken}"],
    )->assertStatus(422);
});

it('cannot read analytics for an account in another workspace', function () {
    $account = analyticsAccount(Workspace::factory()->create());

    // Route-model binding resolves across every workspace on the instance, so
    // this is the check that keeps one tenant's token off another's numbers.
    $this->getJson(route('api.social-accounts.analytics', $account), [
        'Authorization' => "Bearer {$this->plainToken}",
    ])->assertNotFound();
});

it('requires authentication', function () {
    $account = analyticsAccount($this->workspace);

    $this->getJson(route('api.social-accounts.analytics', $account))->assertUnauthorized();
});

it('does not expose account tokens', function () {
    $account = analyticsAccount($this->workspace);

    $this->mock(XAnalytics::class)->shouldReceive('getMetrics')->andReturn([]);

    $response = $this->getJson(route('api.social-accounts.analytics', $account), [
        'Authorization' => "Bearer {$this->plainToken}",
    ]);

    $response->assertOk();
    $response->assertJsonMissing(['access_token']);
    $response->assertJsonMissing(['refresh_token']);
});

it('resolves every catalogue label to its own key', function () {
    MetricKey::flush();

    foreach (array_keys(trans('analytics.metrics', [], 'en')) as $key) {
        expect(MetricKey::resolve(__("analytics.metrics.{$key}", [], 'en')))
            ->toBe($key, "label for {$key} did not resolve back to it");
    }
});
