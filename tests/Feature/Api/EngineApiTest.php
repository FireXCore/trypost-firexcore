<?php

declare(strict_types=1);

beforeEach(function () {
    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->plainToken = $result['plain_token'];
});

it('reports the engine identity and running build', function () {
    config()->set('trypost.version', 'v1.0.9-test');
    config()->set('trypost.self_hosted', true);

    $response = $this->getJson(route('api.engine.show'), [
        'Authorization' => "Bearer {$this->plainToken}",
    ]);

    $response->assertOk();
    $response->assertJson([
        'engine' => 'trypost',
        'version' => 'v1.0.9-test',
        'self_hosted' => true,
    ]);
});

it('requires authentication', function () {
    // The build number of a private instance is operational detail, not public
    // information.
    $this->getJson(route('api.engine.show'))->assertUnauthorized();
});

it('exposes nothing beyond identity', function () {
    $response = $this->getJson(route('api.engine.show'), [
        'Authorization' => "Bearer {$this->plainToken}",
    ]);

    $response->assertOk();
    expect(array_keys($response->json()))
        ->toEqualCanonicalizing(['engine', 'version', 'self_hosted']);
});
