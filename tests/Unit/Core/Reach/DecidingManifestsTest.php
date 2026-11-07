<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Composer\DecidingPackages;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\LoadedPackages;
use NightWorksIO\MutationGate\Core\Coverage\ProcessLoads;
use NightWorksIO\MutationGate\Core\Coverage\TestLoad;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Reach\DecidingManifests;
use NightWorksIO\MutationGate\Core\Reach\LockLoads;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Locks;

it('marks a lock changed in its place deciding alike where it moved only packages the config leaves out', function (string $console, bool $alike): void {
    $locks = DecidingManifests::in(Packages::of(Flows::trees()), DecidingPackages::named(Glob::of('pestphp/*')));
    $path = Path::of('composer.lock');
    $sources = Sources::of(
        ByPath::none()->with($path, Locks::of(['symfony/console' => $console, 'pestphp/pest' => '5.2.1'])),
        ByPath::none()->with($path, Locks::of(['symfony/console' => '8.1.7', 'pestphp/pest' => $alike ? '5.2.1' : '5.2.0'])),
    );
    $change = Change::modified($path, Lines::none());

    expect($locks->marking($sources, $change)->decidesAlike($change))->toBe($alike);
})->with([
    'only the console moved' => ['8.1.9', true],
    'pest moved too' => ['8.1.9', false],
]);

it('marks nothing alike where the lock was added, or the change is not to a lock', function (Change $change): void {
    $locks = DecidingManifests::in(Packages::of(Flows::trees()), DecidingPackages::named(Glob::of('pestphp/*')));
    $lock = Locks::of(['symfony/console' => '8.1.7']);
    $sources = Sources::of(
        ByPath::none()->with($change->path(), $lock),
        ByPath::none()->with($change->path(), $lock),
    );

    expect($locks->marking($sources, $change)->decidesAlike($change))->toBeFalse();
})->with([
    'added' => [fn(): Change => Change::added(Path::of('composer.lock'), Lines::none())],
    'neither a manifest nor a lock' => [fn(): Change => Change::modified(Path::of('src/Money.php'), Lines::none())],
    'a lock outside its package\'s root' => [fn(): Change => Change::modified(Path::of('src/composer.lock'), Lines::none())],
]);

it('says a lock missing at either end decides', function (): void {
    $locks = DecidingManifests::in(Packages::of(Flows::trees()), DecidingPackages::named(Glob::of('pestphp/*')));
    $lock = Locks::of(['symfony/console' => '8.1.7']);

    expect($locks->decides(Missing::at(Path::of('composer.lock')), $lock))->toBeTrue()
        ->and($locks->decides($lock, Missing::at(Path::of('composer.lock'))))->toBeTrue()
        ->and($locks->decides($lock, $lock))->toBeFalse();
});

it('marks a manifest changed in its place deciding alike where it moved only what describes the package or packages the config leaves out', function (string $key, array $now, bool $alike): void {
    $manifests = DecidingManifests::in(Packages::of(Flows::trees()), DecidingPackages::named(Glob::of('pestphp/*')));
    $path = Path::of('composer.json');
    $before = ['description' => 'a', 'require' => ['psr/log' => '^3.0', 'pestphp/pest' => '^5.2']];
    $sources = Sources::of(
        ByPath::none()->with($path, Contents::of((string) json_encode([...$before, $key => $now]))),
        ByPath::none()->with($path, Contents::of((string) json_encode($before))),
    );
    $change = Change::modified($path, Lines::none());

    expect($manifests->marking($sources, $change)->decidesAlike($change))->toBe($alike);
})->with([
    'the description' => ['description', ['b'], true],
    'a package the config leaves out' => ['require', ['psr/log' => '^3.1', 'pestphp/pest' => '^5.2'], true],
    'a package the config names' => ['require', ['psr/log' => '^3.0', 'pestphp/pest' => '^5.3'], false],
    'autoload' => ['autoload', ['psr-4' => []], false],
]);

it('says a manifest missing at either end decides', function (): void {
    $manifests = DecidingManifests::in(Packages::of(Flows::trees()), DecidingPackages::named(Glob::of('pestphp/*')));
    $manifest = Contents::of('{"require": {}}');

    expect($manifests->manifestDecides(Missing::at(Path::of('composer.json')), $manifest))->toBeTrue()
        ->and($manifests->manifestDecides($manifest, Missing::at(Path::of('composer.json'))))->toBeTrue()
        ->and($manifests->manifestDecides($manifest, $manifest))->toBeFalse();
});

function lockSources(string $before, string $now): Sources
{
    $path = Path::of('composer.lock');

    return Sources::of(
        ByPath::none()->with($path, Locks::of(['acme/one' => $now])),
        ByPath::none()->with($path, Locks::of(['acme/one' => $before])),
    );
}

function loadedMap(): CoverageMap
{
    return CoverageMap::of()
        ->covered(Path::of('src/One.php'), Line::of(3), TestId::of('A::a'))
        ->loading(LoadedPackages::of(ProcessLoads::of(TestLoad::of('A::a', 'acme/one'))));
}

it('leaves a lock\'s move to the tests that loaded it, where the map records every test', function (): void {
    $change = Change::modified(Path::of('composer.lock'), Lines::none());
    $marked = DecidingManifests::in(Packages::of(Flows::trees()), DecidingPackages::byLoads())
        ->measuredBy(loadedMap())
        ->marking(lockSources('1.0.0', '1.0.1'), $change);

    expect($marked->decidesAlike($change))->toBeTrue()
        ->and($marked->loadsMoved(Path::of('composer.lock')))->toEqual(LockLoads::of(Listed::of('acme/one'), loadedMap()));
});

it('decides where the map records no loads, or leaves a test out', function (CoverageMap $map): void {
    $change = Change::modified(Path::of('composer.lock'), Lines::none());
    $marked = DecidingManifests::in(Packages::of(Flows::trees()), DecidingPackages::byLoads())
        ->measuredBy($map)
        ->marking(lockSources('1.0.0', '1.0.1'), $change);

    expect($marked->decidesAlike($change))->toBeFalse()
        ->and($marked->loadsMoved(Path::of('composer.lock')))->toBeInstanceOf(NotGiven::class);
})->with([
    'no record' => [fn(): CoverageMap => CoverageMap::of()->covered(Path::of('src/One.php'), Line::of(3), TestId::of('A::a'))],
    'a test it leaves out' => [fn(): CoverageMap => loadedMap()->covered(Path::of('src/Two.php'), Line::of(3), TestId::of('B::a'))],
]);

it('reads no loads of a lock it decides alike by name, or of a manifest it leaves alike', function (): void {
    $lock = Change::modified(Path::of('composer.lock'), Lines::none());
    $named = DecidingManifests::in(Packages::of(Flows::trees()), DecidingPackages::named(Glob::of('pestphp/*')))
        ->measuredBy(loadedMap())
        ->marking(lockSources('1.0.0', '1.0.1'), $lock);
    $manifest = Path::of('composer.json');
    $change = Change::modified($manifest, Lines::none());
    $json = static fn(string $constraint): Contents => Contents::of((string) json_encode(['require' => ['acme/one' => $constraint]]));
    $manifests = DecidingManifests::in(Packages::of(Flows::trees()), DecidingPackages::byLoads())
        ->measuredBy(loadedMap())
        ->marking(Sources::of(ByPath::none()->with($manifest, $json('^2.0')), ByPath::none()->with($manifest, $json('^1.0'))), $change);

    expect($named->decidesAlike($lock))->toBeTrue()
        ->and($named->loadsMoved(Path::of('composer.lock')))->toBeInstanceOf(NotGiven::class)
        ->and($manifests->decidesAlike($change))->toBeTrue()
        ->and($manifests->loadsMoved($manifest))->toBeInstanceOf(NotGiven::class);
});
