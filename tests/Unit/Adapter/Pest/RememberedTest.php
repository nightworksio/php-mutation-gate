<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Remembered;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Ran;
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
        ->and($written)->toBe(1);
});

it('runs each baseline once, keeping whether it passed, and keeps nothing of one stopped at its deadline', function (): void {
    $remembered = new Remembered();
    $runs = 0;
    $running = static function (Ran $ran) use (&$runs): Closure {
        return static function () use ($ran, &$runs): Ran {
            $runs++;

            return $ran;
        };
    };

    $passed = [
        $remembered->baseline('passes', $running(Ran::finished(succeeded: true, output: ''))),
        $remembered->baseline('passes', $running(Ran::finished(succeeded: false, output: ''))),
        $remembered->baseline('fails', $running(Ran::finished(succeeded: false, output: ''))),
        $remembered->baseline('fails', $running(Ran::finished(succeeded: true, output: ''))),
        $remembered->baseline('stopped', $running(Ran::stopped(''))),
        $remembered->baseline('stopped', $running(Ran::finished(succeeded: true, output: ''))),
    ];

    expect($passed)->toBe([true, true, false, false, false, true])
        ->and($runs)->toBe(4);
});
