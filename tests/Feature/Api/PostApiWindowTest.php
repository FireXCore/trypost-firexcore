<?php

declare(strict_types=1);

use App\Enums\Post\Status;
use App\Models\Post;

beforeEach(function () {
    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->plainToken = $result['plain_token'];
});

function windowPost(string $workspaceId, string $userId, ?string $scheduled, ?string $published, Status $status): Post
{
    return Post::factory()->create([
        'workspace_id' => $workspaceId,
        'user_id' => $userId,
        'scheduled_at' => $scheduled,
        'published_at' => $published,
        'status' => $status,
    ]);
}

it('returns every post when no window is given', function () {
    windowPost($this->workspace->id, $this->user->id, '2026-01-05 10:00:00', null, Status::Scheduled);
    windowPost($this->workspace->id, $this->user->id, '2026-09-05 10:00:00', '2026-09-05 10:05:00', Status::Published);

    $this->getJson(route('api.posts.index'), ['Authorization' => "Bearer {$this->plainToken}"])
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('bounds results by the requested window', function () {
    $inside = windowPost($this->workspace->id, $this->user->id, '2026-09-03 09:00:00', '2026-09-03 09:01:00', Status::Published);
    windowPost($this->workspace->id, $this->user->id, '2026-08-20 09:00:00', '2026-08-20 09:01:00', Status::Published);
    windowPost($this->workspace->id, $this->user->id, '2026-09-20 09:00:00', null, Status::Scheduled);

    $response = $this->getJson(
        route('api.posts.index').'?from=2026-09-01&to=2026-09-07',
        ['Authorization' => "Bearer {$this->plainToken}"],
    );

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $inside->id);
});

it('includes posts on both boundary days', function () {
    windowPost($this->workspace->id, $this->user->id, '2026-09-01 00:00:00', '2026-09-01 00:00:00', Status::Published);
    windowPost($this->workspace->id, $this->user->id, '2026-09-07 23:59:00', '2026-09-07 23:59:00', Status::Published);

    // Inclusive on both ends: a report for 1-7 September that silently drops
    // the 7th is the classic off-by-one in a weekly figure.
    $this->getJson(
        route('api.posts.index').'?from=2026-09-01&to=2026-09-07',
        ['Authorization' => "Bearer {$this->plainToken}"],
    )->assertOk()->assertJsonCount(2, 'data');
});

it('windows on when a post actually published, not when it was planned', function () {
    // Scheduled in August, actually published in September. A filter on
    // scheduled_at alone files this under the wrong week.
    $late = windowPost($this->workspace->id, $this->user->id, '2026-08-29 12:00:00', '2026-09-02 12:00:00', Status::Published);

    $response = $this->getJson(
        route('api.posts.index').'?from=2026-09-01&to=2026-09-07',
        ['Authorization' => "Bearer {$this->plainToken}"],
    );

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $late->id);
});

it('falls back to the scheduled date for a post that has not published', function () {
    $pending = windowPost($this->workspace->id, $this->user->id, '2026-09-04 12:00:00', null, Status::Scheduled);

    $this->getJson(
        route('api.posts.index').'?from=2026-09-01&to=2026-09-07',
        ['Authorization' => "Bearer {$this->plainToken}"],
    )->assertOk()->assertJsonPath('data.0.id', $pending->id);
});

it('filters by status', function () {
    windowPost($this->workspace->id, $this->user->id, '2026-09-03 09:00:00', '2026-09-03 09:00:00', Status::Published);
    windowPost($this->workspace->id, $this->user->id, '2026-09-04 09:00:00', null, Status::Draft);

    $this->getJson(
        route('api.posts.index').'?status=published',
        ['Authorization' => "Bearer {$this->plainToken}"],
    )->assertOk()->assertJsonCount(1, 'data');
});

it('honours per_page and caps it', function () {
    Post::factory()->count(3)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scheduled_at' => '2026-09-03 09:00:00',
    ]);

    $this->getJson(route('api.posts.index').'?per_page=2', ['Authorization' => "Bearer {$this->plainToken}"])
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson(route('api.posts.index').'?per_page=500', ['Authorization' => "Bearer {$this->plainToken}"])
        ->assertStatus(422);
});

it('rejects a window whose end precedes its start', function () {
    $this->getJson(
        route('api.posts.index').'?from=2026-09-07&to=2026-09-01',
        ['Authorization' => "Bearer {$this->plainToken}"],
    )->assertStatus(422);
});

it('never returns another workspace post inside a window', function () {
    $other = createApiTestToken();
    windowPost($other['workspace']->id, $other['user']->id, '2026-09-03 09:00:00', '2026-09-03 09:00:00', Status::Published);

    $this->getJson(
        route('api.posts.index').'?from=2026-09-01&to=2026-09-07',
        ['Authorization' => "Bearer {$this->plainToken}"],
    )->assertOk()->assertJsonCount(0, 'data');
});
