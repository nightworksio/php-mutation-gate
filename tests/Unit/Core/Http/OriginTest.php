<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Http\Origin;

it('names a URL by its scheme, host and port alone', function (string $url, string $origin): void {
    expect(Origin::of($url))->toBe($origin);
})->with([
    'a host' => ['https://otel.example', 'https://otel.example'],
    'a port and a path' => ['http://localhost:4318/v1/traces', 'http://localhost:4318'],
    'userinfo and a query' => ['https://user:key@otel.example/otlp?api-key=secret#part', 'https://otel.example'],
    'an IPv6 host' => ['http://[::1]:4318/', 'http://[::1]:4318'],
    'no scheme' => ['otel.example:4318', 'an unreadable URL'],
    'nothing' => ['', 'an unreadable URL'],
]);
