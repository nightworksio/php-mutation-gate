<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Remembered;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Processes;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;

it('learns each thing once, and each set of groups once for what it withheld', function (): void {
    $remembered = new Remembered();
    $asked = [];
    $groups = static function (string $name) use (&$asked): Closure {
        return static function () use ($name, &$asked): Groups {
            $asked[] = $name;

            return Groups::of(Group::named($name));
        };
    };

    $first = $remembered->groups(Withheld::standard(), $groups('first'));
    $again = $remembered->groups(Withheld::standard(), $groups('again'));
    $other = $remembered->groups(Withheld::of('DEPLOY_*'), $groups('other'));
    $patched = [$remembered->patched(static fn(): bool => true), $remembered->patched(static fn(): bool => false)];
    $map = $remembered->map(Path::of('planned'), static fn(): CoverageMap => CoverageMap::of());
    $mapAgain = $remembered->map(Path::of('planned'), static fn(): CannotJudge => CannotJudge::because('read again'));
    $processes = [
        $remembered->processes(static fn(): Processes => Processes::of(4)),
        $remembered->processes(static fn(): Processes => Processes::of(2)),
    ];
    $written = 0;
    $remembered->writeOnce('/m', static function () use (&$written): void {
        $written++;
    });
    $remembered->writeOnce('/m', static function () use (&$written): void {
        $written++;
    });

    expect($first)->toBe($again)
        ->and($other)->not->toBe($first)
        ->and($asked)->toBe(['first', 'other'])
        ->and($patched)->toBe([true, true])
        ->and($mapAgain)->toBe($map)
        ->and(array_map(static fn(Processes $counted): int => $counted->count(), $processes))->toBe([4, 4])
        ->and($written)->toBe(1);
});
