<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Config\Floors;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Registry\ExtensionPoint;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\Registering;

it('builds what was registered under a name from the options a config gives it', function (ExtensionPoint $point): void {
    $adapter = Registering::adapter($point);
    $given = new ArrayObject();
    $registry = Registering::register($point, new Extensions(Origin::of('acme/a')), static function (Options $options) use ($given, $adapter): object {
        $given->append($options->json());

        return $adapter;
    });

    expect(Registering::lookUp($point, $registry, Options::ofJson('{"channel": "#ci"}')))->toBe($adapter)
        ->and($given->getArrayCopy())->toBe(['{"channel": "#ci"}']);
})->with(Registering::adapterPoints());

it('passes on the problems an adapter finds in its options', function (ExtensionPoint $point): void {
    $invalid = Invalid::because(Problem::at('channel', 'expected a channel name'));
    $registry = Registering::register($point, new Extensions(Origin::of('acme/a')), static fn(): Invalid => $invalid);

    expect(Registering::lookUp($point, $registry, Options::none()))->toBe($invalid);
})->with(Registering::adapterPoints());

it('cannot judge with a name nothing registered', function (ExtensionPoint $point): void {
    expect(Registering::lookUp($point, new Extensions(Origin::of('acme/a')), Options::none()))->toEqual(CannotJudge::because(sprintf('No %s is registered as "it".', $point->value)));
})->with(ExtensionPoint::cases());

it('leaves the registry it came from as it was', function (ExtensionPoint $point): void {
    $adapter = Registering::adapter($point);
    $registry = new Extensions(Origin::of('acme/a'));
    Registering::register($point, $registry, static fn(): object => $adapter);

    expect(Registering::lookUp($point, $registry, Options::none()))->toBeInstanceOf(CannotJudge::class);
})->with(Registering::adapterPoints());

it('takes in what another package registered', function (ExtensionPoint $point): void {
    $adapter = Registering::adapter($point);
    $merged = new Extensions(Origin::of('acme/a'))->merge(Registering::register($point, new Extensions(Origin::of('acme/b')), static fn(): object => $adapter));

    expect($merged instanceof Extensions ? Registering::lookUp($point, $merged, Options::none()) : $merged)->toBe($adapter);
})->with(Registering::adapterPoints());

it('refuses two packages registering one name, naming both', function (ExtensionPoint $point): void {
    $adapter = Registering::adapter($point);
    $ours = Registering::register($point, new Extensions(Origin::of('acme/a')), static fn(): object => $adapter);
    $theirs = Registering::register($point, new Extensions(Origin::of('acme/b')), static fn(): object => $adapter);

    expect($ours->merge($theirs))->toEqual(CannotJudge::because(sprintf('Two packages register a %s named "it": acme/a and acme/b. Remove one of the packages, or run with --no-extensions.', $point->value)));
})->with(Registering::adapterPoints());

it('refuses a name registered twice under the same package, which a package cannot prove it is', function (ExtensionPoint $point): void {
    $adapter = Registering::adapter($point);
    $ours = Registering::register($point, new Extensions(Origin::of('nightworksio/mutation-gate')), static fn(): object => $adapter);
    $claimed = Registering::register($point, new Extensions(Origin::of('nightworksio/mutation-gate')), static fn(): object => $adapter);

    expect($ours->merge($claimed))->toEqual(CannotJudge::because(sprintf('Two packages register a %s named "it": nightworksio/mutation-gate and nightworksio/mutation-gate. Remove one of the packages, or run with --no-extensions.', $point->value)));
})->with(Registering::adapterPoints());

it('names every name two packages both register', function (): void {
    $runner = static fn(): RunnerFake => RunnerFake::ofTheFixture();
    $reporter = static fn(): ReporterFake => new ReporterFake();
    $ours = new Extensions(Origin::of('acme/a'))->withRunner(Name::of('pest'), $runner)->withReporter(Name::of('sarif'), $reporter);
    $theirs = new Extensions(Origin::of('acme/b'))->withRunner(Name::of('pest'), $runner)->withReporter(Name::of('sarif'), $reporter);

    expect($ours->merge($theirs))->toEqual(CannotJudge::because(
        'Two packages register a runner named "pest": acme/a and acme/b. Two packages register a reporter named "sarif": acme/a and acme/b. Remove one of the packages, or run with --no-extensions.',
    ));
});

it('holds presets, layers of config with a name', function (): void {
    $fragment = Layer::of(Floors::of(trees: Listed::of(DeclaredTree::of(Path::of('app'), Undeclared::floor(), Listed::of()))));
    $registry = new Extensions(Origin::of('acme/a'))->withPreset(Name::of('laravel'), $fragment);

    expect(Lookup::in($registry)->preset(Name::of('laravel')))->toBe($fragment)
        ->and(Lookup::in($registry)->preset(Name::of('symfony')))->toEqual(CannotJudge::because('No preset is registered as "symfony".'));
});

it('takes in the presets another package registered, and refuses one registered twice', function (): void {
    $fragment = Layer::none();
    $preset = static fn(string $package): Extensions => new Extensions(Origin::of($package))->withPreset(Name::of('laravel'), $fragment);
    $merged = new Extensions(Origin::of('acme/a'))->merge($preset('acme/b'));

    expect($merged instanceof Extensions ? Lookup::in($merged)->preset(Name::of('laravel')) : $merged)->toBe($fragment)
        ->and($preset('acme/a')->merge($preset('acme/b')))->toEqual(CannotJudge::because(
            'Two packages register a preset named "laravel": acme/a and acme/b. Remove one of the packages, or run with --no-extensions.',
        ));
});
