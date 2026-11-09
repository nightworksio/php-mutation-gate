<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Outbreak;
use NightWorksIO\MutationGate\Config\Runner;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('writes every outbreak key, as the config file would', function (): void {
    expect(Configs::written(Gate::configure()->runner(Runner::pest())->with(
        Outbreak::off(),
        Outbreak::binary('bin/outbreak'),
        Outbreak::timeout('90s'),
        Outbreak::threads(2),
    )))
        ->toMatchArray(['outbreak' => ['enabled' => false, 'binary' => 'bin/outbreak', 'timeout' => '90s', 'threads' => 2]])
        ->and(Configs::built(Gate::configure()->runner(Runner::pest())->with(Outbreak::on()))->shards()->outbreak()->enabled())
        ->toBeTrue();
});
