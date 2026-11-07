<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\PackageWatch;
use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Tests\Support\Environment;

it('names each package first loaded since it was last asked, by the nearest directory Composer installed', function (): void {
    $included = new ArrayObject(['/v/acme/one/src/A.php', '/project/src/B.php']);
    $watch = PackageWatch::over(['/v/acme/one' => 'acme/one', '/v/acme/two' => 'acme/two'], static fn(): array => [...$included], static fn(): string => '');

    expect($watch->newlyLoaded())->toBe(['acme/one'])
        ->and($watch->newlyLoaded())->toBe([]);

    $included->append('/v/acme/two/deep/er/C.php');
    $included->append('/v/acme/one/src/D.php');

    expect($watch->newlyLoaded())->toBe(['acme/two', 'acme/one']);
});

it('says a process it started ended only once, since it was last asked', function (): void {
    $used = new ArrayObject(['0']);
    $watch = PackageWatch::over([], static fn(): array => [], static fn(): string => $used[0] ?? '');

    expect($watch->startedAProcess())->toBeFalse();

    $used[0] = '1';

    expect($watch->startedAProcess())->toBeTrue()
        ->and($watch->startedAProcess())->toBeFalse();
});

it('watches only where the gate asks, and reads this process itself', function (): void {
    $asked = Environment::during([ChildVariable::RecordsLoads->value => '1'], PackageWatch::fromEnvironment(...));
    $off = Environment::during([ChildVariable::RecordsLoads->value => null], PackageWatch::fromEnvironment(...));

    expect($off)->toBe(Off::WatchingPackages)
        ->and($asked)->toBeInstanceOf(PackageWatch::class)
        ->and($asked instanceof PackageWatch ? $asked->newlyLoaded() : [])->toContain('pestphp/pest')
        ->and($asked instanceof PackageWatch ? $asked->newlyLoaded() : ['more'])->toBe([])
        ->and($asked instanceof PackageWatch && $asked->startedAProcess())->toBeFalse();
});

it('sees a process this one started and waited for', function (): void {
    $watch = Environment::during([ChildVariable::RecordsLoads->value => '1'], PackageWatch::fromEnvironment(...));
    exec('true');

    expect($watch instanceof PackageWatch && $watch->startedAProcess())->toBeTrue();
});
