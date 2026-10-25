<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\VerdictWarnings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Considered;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A unit held by its own group. */
function heldWarned(string $path): Unit
{
    return Unit::held(Path::of($path), Group::named(sprintf('holds:%s', $path)));
}

it('names no file every test runs through that a unit of the plan holds, proved, carried or in any shard', function (): void {
    $map = CoverageMap::empty();

    foreach (range(1, 20) as $test) {
        foreach (['src/Proved.php', 'src/Carried.php', 'src/First.php', 'src/Last.php', 'src/Loose.php'] as $file) {
            $map = $map->covered(Path::of($file), Line::of(3), TestId::of(sprintf('HotTest::runs %d', $test)));
        }
    }

    $shard = static fn(int $id, string $held): Shard => Shard::of(ShardId::of($id), Package::at(Path::root()), Units::of(heldWarned($held)), Seconds::of(1.0), $held);
    $plan = Planned::of($shard(1, 'src/First.php'), $shard(2, 'src/Last.php'))->considering(
        Considered::everything()->proving(Units::of(heldWarned('src/Proved.php')))->carrying(Units::of(heldWarned('src/Carried.php'))),
    );

    $warnings = new VerdictWarnings(Flows::adapters(Scratch::directory()), Flows::settings())->of($plan, TreeVerdicts::none(), Warnings::none(), $map);

    expect(array_map(static fn(Warning $warning): string => $warning->text(), [...$warnings]))->toBe([
        '`src/Loose.php` is run by 20 of 20 tests and nothing holds it; each of its mutants runs most of the suite.',
    ]);
});
