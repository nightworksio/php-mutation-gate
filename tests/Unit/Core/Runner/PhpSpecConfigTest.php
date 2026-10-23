<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\PhpSpecConfig;

it('names each place PhpSpec looks for its config, in its order', function (): void {
    expect(PhpSpecConfig::candidatesIn(Path::of('packages/billing')))->toEqual(Paths::of(
        Path::of('packages/billing/phpspec.yml'),
        Path::of('packages/billing/.phpspec.yml'),
        Path::of('packages/billing/phpspec.yml.dist'),
        Path::of('packages/billing/phpspec.yaml'),
        Path::of('packages/billing/.phpspec.yaml'),
        Path::of('packages/billing/phpspec.yaml.dist'),
    ));
});
