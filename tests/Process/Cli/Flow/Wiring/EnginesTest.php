<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Outbreak\Outbreak;
use NightWorksIO\MutationGate\Adapter\PhpStan\PhpStanOption;
use NightWorksIO\MutationGate\Adapter\Serve\OutbreakDaemon;
use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Wiring;
use NightWorksIO\MutationGate\Config\Outbreak as OutbreakSettings;
use NightWorksIO\MutationGate\Config\OutbreakServe;
use NightWorksIO\MutationGate\Config\StaticCheck;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Fakes\StaticCheckerFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\OutbreakStandIn;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Wirings;

$holds = [
    'holds:src/Adapter/Outbreak/Outbreak.php',
    'holds:src/Cli/Flow/Wiring.php',
];

afterEach(function (): void {
    Scratch::sweep();
});

it('wires Outbreak served by this user\'s daemon where outbreak serve is on, and by none where it is off', function (bool $serving): void {
    $binary = OutbreakStandIn::answering('{"outbreak":1}')->binary();
    $settings = Flows::settings(OutbreakSettings::on(), OutbreakSettings::binary($binary), $serving ? OutbreakServe::serving() : OutbreakServe::notServing());

    $adapters = new Wiring(Wirings::registry(), Variables::of([]), Wirings::detected())->adapters($settings, Directory::at(Flows::project()));
    $maps = $adapters instanceof Adapters ? $adapters->maps : $adapters;

    expect($maps)->toBeInstanceOf(Outbreak::class)
        ->and($maps instanceof Outbreak ? $maps->served() : $maps)->toBeInstanceOf($serving ? OutbreakDaemon::class : NotGiven::class);
})->with(['serving' => [true], 'not serving' => [false]])->group(...$holds);

it('hands PHPStan staticCheck.php and staticCheck.turbo, and an analyser of any other name neither', function (string $name, bool $handed): void {
    $options = [];
    $registry = Wirings::registry()->withStaticChecker(Name::of($name), static function (Options $given) use (&$options): StaticCheckerFake {
        $options[] = [$given->object(Key::of(PhpStanOption::Php->value)) instanceof Options, is_bool($given->flag(Key::of(PhpStanOption::Turbo->value)))];

        return StaticCheckerFake::findingNothing();
    });

    new Wiring($registry, Variables::of([]), Wirings::detected())->adapters(Flows::settings(StaticCheck::uses($name)), Directory::at(Flows::project()));

    expect($options)->toBe([[$handed, $handed]]);
})->with([
    'PHPStan' => ['phpstan', true],
    'another' => ['fake', false],
])->group(...$holds);
