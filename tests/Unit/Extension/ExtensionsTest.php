<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Extension\Origin;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\RegisteredKind;

it('builds what was registered under a name from the options a config gives it', function (RegisteredKind $kind): void {
    $adapter = $kind->adapter();
    $given = new ArrayObject();
    $registry = $kind->register(new Extensions(Origin::of('acme/a')), static function (Options $options) use ($given, $adapter): object {
        $given->append($options->json());

        return $adapter;
    });

    expect($kind->lookUp($registry, Options::ofJson('{"channel": "#ci"}')))->toBe($adapter)
        ->and($given->getArrayCopy())->toBe(['{"channel": "#ci"}']);
})->with(RegisteredKind::cases());

it('passes on the problems an adapter finds in its options', function (RegisteredKind $kind): void {
    $invalid = Invalid::because(Problem::at('channel', 'expected a channel name'));
    $registry = $kind->register(new Extensions(Origin::of('acme/a')), static fn(): Invalid => $invalid);

    expect($kind->lookUp($registry, Options::none()))->toBe($invalid);
})->with(RegisteredKind::cases());

it('cannot judge with a name nothing registered', function (RegisteredKind $kind): void {
    expect($kind->lookUp(new Extensions(Origin::of('acme/a')), Options::none()))->toEqual(CannotJudge::because(sprintf('No %s is registered as "it".', $kind->value)));
})->with(RegisteredKind::cases());

it('leaves the registry it came from as it was', function (RegisteredKind $kind): void {
    $adapter = $kind->adapter();
    $registry = new Extensions(Origin::of('acme/a'));
    $kind->register($registry, static fn(): object => $adapter);

    expect($kind->lookUp($registry, Options::none()))->toBeInstanceOf(CannotJudge::class);
})->with(RegisteredKind::cases());

it('takes in what another package registered', function (RegisteredKind $kind): void {
    $adapter = $kind->adapter();
    $merged = new Extensions(Origin::of('acme/a'))->merge($kind->register(new Extensions(Origin::of('acme/b')), static fn(): object => $adapter));

    expect($merged instanceof Extensions ? $kind->lookUp($merged, Options::none()) : $merged)->toBe($adapter);
})->with(RegisteredKind::cases());

it('refuses two packages registering one name, naming both', function (RegisteredKind $kind): void {
    $adapter = $kind->adapter();
    $ours = $kind->register(new Extensions(Origin::of('acme/a')), static fn(): object => $adapter);
    $theirs = $kind->register(new Extensions(Origin::of('acme/b')), static fn(): object => $adapter);

    expect($ours->merge($theirs))->toEqual(CannotJudge::because(sprintf('Two packages register a %s named "it": acme/a and acme/b. Remove one of the packages, or run with --no-extensions.', $kind->value)));
})->with(RegisteredKind::cases());

it('lets one package register a name its registry already holds', function (RegisteredKind $kind): void {
    $adapter = $kind->adapter();
    $ours = $kind->register(new Extensions(Origin::of('acme/a')), static fn(): object => $adapter);

    expect($ours->merge($kind->register(new Extensions(Origin::of('acme/a')), static fn(): object => $adapter)))->toBeInstanceOf(Extensions::class);
})->with(RegisteredKind::cases());

it('names every name two packages both register', function (): void {
    $runner = static fn(): RunnerFake => RunnerFake::ofTheFixture();
    $reporter = static fn(): ReporterFake => new ReporterFake();
    $ours = new Extensions(Origin::of('acme/a'))->withRunner(Name::of('pest'), $runner)->withReporter(Name::of('sarif'), $reporter);
    $theirs = new Extensions(Origin::of('acme/b'))->withRunner(Name::of('pest'), $runner)->withReporter(Name::of('sarif'), $reporter);

    expect($ours->merge($theirs))->toEqual(CannotJudge::because(
        'Two packages register a runner named "pest": acme/a and acme/b. Two packages register a reporter named "sarif": acme/a and acme/b. Remove one of the packages, or run with --no-extensions.',
    ));
});

it('holds presets, config fragments with a name', function (): void {
    $fragment = Document::ofJson('{"trees": [{"path": "app"}]}');
    $registry = $fragment instanceof Document ? new Extensions(Origin::of('acme/a'))->withPreset(Name::of('laravel'), $fragment) : new Extensions(Origin::of('acme/a'));

    expect(Lookup::in($registry)->preset(Name::of('laravel')))->toBe($fragment)
        ->and(Lookup::in($registry)->preset(Name::of('symfony')))->toEqual(CannotJudge::because('No preset is registered as "symfony".'));
});

it('takes in the presets another package registered, and refuses one registered twice', function (): void {
    $fragment = Document::ofJson('{}');
    $preset = static fn(string $package): Extensions => $fragment instanceof Document ? new Extensions(Origin::of($package))->withPreset(Name::of('laravel'), $fragment) : new Extensions(Origin::of($package));
    $merged = new Extensions(Origin::of('acme/a'))->merge($preset('acme/b'));

    expect($merged instanceof Extensions ? Lookup::in($merged)->preset(Name::of('laravel')) : $merged)->toBe($fragment)
        ->and($preset('acme/a')->merge($preset('acme/b')))->toEqual(CannotJudge::because(
            'Two packages register a preset named "laravel": acme/a and acme/b. Remove one of the packages, or run with --no-extensions.',
        ));
});
