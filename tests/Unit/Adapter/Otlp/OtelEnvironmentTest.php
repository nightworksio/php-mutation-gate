<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Otlp\OtelEnvironment;
use NightWorksIO\MutationGate\Core\Ci\Variables;

it('exports to the endpoint OpenTelemetry\'s variable names, or to a collector on this machine', function (): void {
    expect(OtelEnvironment::of(Variables::of(['OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://otel.example:4318']))->endpoint())
        ->toBe('https://otel.example:4318')
        ->and(OtelEnvironment::of(Variables::of([]))->endpoint())->toBe('http://localhost:4318');
});

it('reads the headers as percent-encoded pairs, leaving out a pair it cannot read', function (): void {
    $environment = OtelEnvironment::of(Variables::of(['OTEL_EXPORTER_OTLP_HEADERS' => 'api-key=a%20b, x-team = gate ,broken,=nameless']));

    expect($environment->headers())->toBe(['api-key' => 'a b', 'x-team' => 'gate'])
        ->and(OtelEnvironment::of(Variables::of([]))->headers())->toBe([]);
});

it('names the service by OTEL_SERVICE_NAME, then the resource attributes, then mutation-gate', function (Variables $environment, array $resource): void {
    expect(OtelEnvironment::of($environment)->resource())->toBe($resource);
})->with([
    'nothing set' => [Variables::of([]), ['service.name' => 'mutation-gate']],
    'attributes' => [
        Variables::of(['OTEL_RESOURCE_ATTRIBUTES' => 'deployment.environment=ci,service.name=gate-ci']),
        ['service.name' => 'gate-ci', 'deployment.environment' => 'ci'],
    ],
    'a service name over the attributes' => [
        Variables::of(['OTEL_SERVICE_NAME' => 'named', 'OTEL_RESOURCE_ATTRIBUTES' => 'service.name=gate-ci,team=a']),
        ['service.name' => 'named', 'team' => 'a'],
    ],
]);

it('reads a header whose name reads as a number as the name it is', function (): void {
    $environment = OtelEnvironment::of(Variables::of(['OTEL_EXPORTER_OTLP_HEADERS' => '12=b,api-key=a']));

    expect($environment->headers())->toBe(['12' => 'b', 'api-key' => 'a']);
});
