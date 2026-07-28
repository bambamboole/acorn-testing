<?php

declare(strict_types=1);

use Bambamboole\AcornTesting\Testing\Concerns\MakesHttpRequests;
use Bambamboole\AcornTesting\Testing\FeatureTestCase;
use Illuminate\Testing\TestResponse;

/**
 * The trait needs a booted WordPress to issue a real request, so these assert
 * the wiring instead: that consumers inherit the helpers, and that the response
 * type is illuminate/testing's rather than a vendored copy.
 */
it('exposes the request helpers on FeatureTestCase', function (string $method): void {
    expect(method_exists(FeatureTestCase::class, $method))->toBeTrue();
})->with(['get', 'getJson', 'post', 'postJson', 'put', 'patch', 'delete', 'call', 'json']);

it('returns illuminate/testing responses, not a vendored copy', function (): void {
    $source = file_get_contents(
        dirname(__DIR__, 2) . '/src/Testing/Concerns/MakesHttpRequests.php',
    );

    expect($source)->toContain('use Illuminate\Testing\TestResponse;');
    expect($source)->not->toContain('Tests\TestResponse');
});

it('gets its response assertions from illuminate/testing', function (): void {
    expect(class_exists(TestResponse::class))->toBeTrue();

    // The trait is useless without these; consumers assert on them constantly.
    foreach (['assertOk', 'assertForbidden', 'assertBadRequest', 'assertNotFound'] as $assertion) {
        expect(method_exists(TestResponse::class, $assertion))->toBeTrue();
    }
});

it('requires only a container from the host class', function (): void {
    $uses = class_uses_recursive(FeatureTestCase::class);

    expect($uses)->toContain(MakesHttpRequests::class);
    expect(property_exists(FeatureTestCase::class, 'app'))->toBeTrue();
});
