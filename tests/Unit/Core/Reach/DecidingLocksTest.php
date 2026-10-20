<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Composer\DecidingPackages;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Reach\DecidingLocks;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Sources;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Locks;

it('marks a lock changed in its place deciding alike where it moved only packages the config leaves out', function (string $console, bool $alike): void {
    $locks = DecidingLocks::in(Packages::of(Flows::trees()), DecidingPackages::named(Glob::of('pestphp/*')));
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
    $locks = DecidingLocks::in(Packages::of(Flows::trees()), DecidingPackages::named(Glob::of('pestphp/*')));
    $lock = Locks::of(['symfony/console' => '8.1.7']);
    $sources = Sources::of(
        ByPath::none()->with($change->path(), $lock),
        ByPath::none()->with($change->path(), $lock),
    );

    expect($locks->marking($sources, $change)->decidesAlike($change))->toBeFalse();
})->with([
    'added' => [fn(): Change => Change::added(Path::of('composer.lock'), Lines::none())],
    'not a lock' => [fn(): Change => Change::modified(Path::of('composer.json'), Lines::none())],
    'a lock outside its package\'s root' => [fn(): Change => Change::modified(Path::of('src/composer.lock'), Lines::none())],
]);

it('says a lock missing at either end decides', function (): void {
    $locks = DecidingLocks::in(Packages::of(Flows::trees()), DecidingPackages::named(Glob::of('pestphp/*')));
    $lock = Locks::of(['symfony/console' => '8.1.7']);

    expect($locks->decides(Missing::at(Path::of('composer.lock')), $lock))->toBeTrue()
        ->and($locks->decides($lock, Missing::at(Path::of('composer.lock'))))->toBeTrue()
        ->and($locks->decides($lock, $lock))->toBeFalse();
});
