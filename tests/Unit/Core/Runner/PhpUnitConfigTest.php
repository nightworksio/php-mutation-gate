<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;

it('names where PHPUnit\'s config may be in a directory, in the order PHPUnit looks', function (): void {
    expect(PhpUnitConfig::candidatesIn(Path::root()))->toEqual(Paths::of(
        Path::of('phpunit.xml'),
        Path::of('phpunit.dist.xml'),
        Path::of('phpunit.xml.dist'),
    ))
        ->and([...PhpUnitConfig::candidatesIn(Path::of('packages/money'))][2])->toEqual(Path::of('packages/money/phpunit.xml.dist'))
        ->and(PhpUnitConfig::DistXml->in(Path::of('config')))->toEqual(Path::of('config/phpunit.dist.xml'));
});
