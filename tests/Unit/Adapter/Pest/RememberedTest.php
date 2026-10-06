<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Remembered;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
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

it('runs each baseline once, all it does not know in one call, and keeps nothing of one stopped or never started', function (): void {
    $remembered = new Remembered();
    $asked = [];
    $running = static function (Ran ...$ends) use (&$asked): Closure {
        return static function (array $keys) use ($ends, &$asked): ProcessEnds {
            $asked[] = $keys;

            return ProcessEnds::of(...$ends);
        };
    };

    $first = $remembered->baselines(
        ['passes', 'fails', 'passes', 'stopped', 'unstarted'],
        $running(Ran::finished(succeeded: true, output: ''), Ran::finished(succeeded: false, output: ''), Ran::stopped('')),
    );
    $again = $remembered->baselines(
        ['fails', 'stopped', 'passes', 'unstarted'],
        $running(Ran::finished(succeeded: true, output: ''), Ran::finished(succeeded: true, output: '')),
    );
    $known = $remembered->baselines(['passes'], $running());

    expect([$first, $again, $known])->toBe([
        [true, false, true, false, false],
        [false, true, true, true],
        [true],
    ])
        ->and($asked)->toBe([['passes', 'fails', 'stopped', 'unstarted'], ['stopped', 'unstarted']]);
});
