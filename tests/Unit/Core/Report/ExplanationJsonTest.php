<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Proof\Records;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Report\Explanation;
use NightWorksIO\MutationGate\Core\Report\ExplanationJson;
use NightWorksIO\MutationGate\Core\Report\Explanations;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Explained;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Schema;

/** The JSON, having checked it against `explain`'s schema. */
function explainedJson(Explanations $explained): string
{
    $json = ExplanationJson::of($explained);

    expect(Schema::errors($json, Schema::at('resources/explain.schema.json')))->toBe([]);

    return $json;
}

it('names the cluster it explains, and gives each member its entry', function (): void {
    $explained = Explained::cluster();
    $cluster = $explained->cluster();
    $json = explainedJson($explained);

    expect(Decoded::at($json, 'format'))->toBe(1)
        ->and(Decoded::at($json, 'cluster', 'id'))->toBe($cluster instanceof Cluster ? $cluster->id()->value() : 'a cluster')
        ->and(Decoded::at($json, 'cluster', 'members', 0))->toBe(Decoded::at($json, 'mutants', 0, 'mutant', 'id'))
        ->and(Decoded::at($json, 'cluster', 'members', 2))->toBe(Decoded::at($json, 'mutants', 2, 'mutant', 'id'))
        ->and(Decoded::at($json, 'mutants', 0, 'mutant', 'tests'))->toBe(['CartTest::fits', 'CartTest::saves'])
        ->and(Decoded::at($json, 'mutants', 0, 'unit'))->toBe(['unknown' => 'Not run.']);
});

it('gives a proved kill its entry, its killers as its tests, and the unit its group holds with the run it is from', function (): void {
    $kill = JudgedKill::of(ProvedKill::of(
        Explained::id(),
        Path::of('src/Held.php'),
        Line::of(11),
        'Plus',
        TestIds::of(TestId::of('HeldTest::doubles')),
    ));
    $unit = JudgedUnit::of(Unit::held(Path::of('src/Held.php'), Group::named('holds:src/Held.php')), Origin::Proved)
        ->withRun(Run::of('github:7/1', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')));

    $json = explainedJson(Explanations::ofMutant(
        Explanation::judged($kill, KillMatrix::none(), $unit, Reasons::of(), Records::none(IdPrefix::of(Explained::id()))),
    ));

    expect(Decoded::at($json, 'cluster'))->toBeNull()
        ->and(Decoded::at($json, 'mutants', 0, 'mutant', 'family'))->toBeNull()
        ->and(Decoded::at($json, 'mutants', 0, 'mutant', 'diff'))->toBeNull()
        ->and(Decoded::at($json, 'mutants', 0, 'mutant', 'killedBy'))->toBe([0])
        ->and(Decoded::at($json, 'mutants', 0, 'tests'))->toBe([['id' => 'HeldTest::doubles', 'name' => 'HeldTest::doubles', 'outcome' => 'killed']])
        ->and(Decoded::at($json, 'mutants', 0, 'unit'))->toBe([
            'path' => 'src/Held.php',
            'group' => 'holds:src/Held.php',
            'origin' => 'proved',
            'run' => 'github:7/1',
            'reach' => [],
        ])
        ->and(Decoded::at($json, 'mutants', 0, 'history'))->toBe([]);
});
