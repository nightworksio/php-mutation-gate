<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Registry\ExtensionPoint;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Support\Registering;

it('refuses what a registration built that is not what its extension point takes', function (ExtensionPoint $point): void {
    $registry = Registering::registerMisbuilt($point, Name::of('it'), new Extensions(Origin::of('acme/gate')), static fn(): object => new stdClass());

    expect(Registering::lookUp($point, $registry, Options::none()))->toEqual(CannotJudge::because(sprintf(
        'The %1$s registered as "it" built something that is not a %1$s.',
        $point->value,
    )));
})->with(Registering::adapterPoints());

it('builds each registration anew from the options it is given', function (): void {
    $registry = new Extensions(Origin::of('acme/gate'))
        ->withReporter(Name::of('it'), static fn(): ReporterFake => new ReporterFake());
    $lookup = Lookup::in($registry);

    expect($lookup->reporter(Name::of('it'), Options::none()))
        ->not->toBe($lookup->reporter(Name::of('it'), Options::none()));
});
