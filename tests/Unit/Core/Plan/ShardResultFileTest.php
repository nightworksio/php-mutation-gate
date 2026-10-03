<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Analysis\Unchecked;
use NightWorksIO\MutationGate\Core\Analysis\UncheckedSurvivor;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\Covered;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Hold\HeldMisses;
use NightWorksIO\MutationGate\Core\Hold\NotCovered;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

$measured = Measurement::of(Seconds::of(42.5), 'pest', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')));

$survivor = Mutant::of(
    MutantId::hash(Path::of('src/Money.php'), 'LessThan', "-a < b\n+a <= b", 0),
    '7',
    Location::of(Path::of('src/Money.php'), Line::of(12), Line::of(12)),
    Mutation::of('LessThan', MutatorFamily::Boundary, "-a < b\n+a <= b"),
    MutantStatus::Survived,
    Seconds::of(0.25),
);

$finished = static fn(Mutant $survivor, Measurement $measured): ShardResult => ShardResult::of(
    Digest::of('9c1e'),
    ShardId::of(2),
    Keys::none()
        ->with(Path::of('src/Money.php'), Digest::of('aaa'))
        ->with(Path::of('src/Limit.php'), Unkeyed::because('No git.')),
    MutationResult::of(Mutants::of($survivor), 1),
    $measured,
);

it('writes every mutant record, each unit key and what the shard measured', function () use (
    $finished,
    $survivor,
    $measured,
): void {
    expect(ShardResultFile::encode($finished($survivor, $measured)))->toBe(sprintf(<<<'JSON'
        {
            "format": 1,
            "plan": "9c1e",
            "shard": 2,
            "units": {
                "src/Money.php": "aaa",
                "src/Limit.php": {
                    "unkeyed": "No git."
                }
            },
            "measured": {
                "seconds": 42.5,
                "runner": "pest",
                "at": "2026-09-29T20:48:17Z"
            },
            "mutants": [
                {
                    "id": "%s",
                    "native": "7",
                    "file": "src/Money.php",
                    "line": 12,
                    "end": 12,
                    "mutator": "LessThan",
                    "family": "boundary",
                    "diff": "-a < b\n+a <= b",
                    "status": "survived",
                    "seconds": 0.25
                }
            ],
            "skipped": 1
        }
        JSON, $survivor->id()->value()));
});

it('writes why the runner could not judge instead of mutants', function () use ($measured): void {
    $stopped = CannotJudge::because('Pest stopped.');
    $result = ShardResult::of(Digest::of('9c1e'), ShardId::of(1), Keys::none(), $stopped, $measured);

    expect(ShardResultFile::encode($result))->toBe(<<<'JSON'
        {
            "format": 1,
            "plan": "9c1e",
            "shard": 1,
            "units": {},
            "measured": {
                "seconds": 42.5,
                "runner": "pest",
                "at": "2026-09-29T20:48:17Z"
            },
            "cannotJudge": "Pest stopped."
        }
        JSON);
});

it('reads back a finished shard it wrote', function () use ($finished, $survivor, $measured): void {
    $result = $finished($survivor, $measured);

    expect(ShardResultFile::decode(ShardResultFile::encode($result)))->toEqual($result);
});

it('lists the mutants that gave two answers after the rest, and reads them back', function () use (
    $finished,
    $survivor,
    $measured,
): void {
    $other = MutantId::hash(Path::of('src/Money.php'), 'Plus', '', 0);
    $result = $finished($survivor, $measured)->withFlaky(MutantIds::of($survivor->id(), $other));
    $written = ShardResultFile::encode($result);

    expect($written)->toEndWith(sprintf(
        "    \"skipped\": 1,\n    \"flaky\": [\n        \"%s\",\n        \"%s\"\n    ]\n}",
        $survivor->id()->value(),
        $other->value(),
    ))
        ->and(ShardResultFile::decode($written))->toEqual($result)
        ->and(ShardResultFile::encode($finished($survivor, $measured)))->not->toContain('"flaky"');
});

it('lists each held unit its holding tests miss lines of, with why, and reads them back', function () use (
    $finished,
    $survivor,
    $measured,
): void {
    $misses = HeldMisses::of(
        NotCovered::because(Unit::held(Path::of('src/Kernel.php'), Group::named('holds:src/Kernel.php')), 'Missed 48.'),
        NotCovered::because(Unit::held(Path::of('src/Http'), Filter::matching('HttpTest')), 'Missed all.'),
    );
    $result = $finished($survivor, $measured)->withMisses($misses);
    $written = ShardResultFile::encode($result);

    expect($written)->toContain('"missed": [')
        ->and(ShardResultFile::decode($written))->toEqual($result)
        ->and(ShardResultFile::encode($finished($survivor, $measured)))->not->toContain('"missed"');
});

it('lists each held unit its holding tests cover, with those of them that run it, and reads them back', function () use (
    $finished,
    $survivor,
    $measured,
): void {
    $covered = HeldCovered::of(
        Covered::by(
            Unit::held(Path::of('src/Kernel.php'), Group::named('holds:src/Kernel.php')),
            TestIds::of(TestId::of('KernelTest::boots'), TestId::of('KernelTest::stops')),
        ),
        Covered::by(Unit::held(Path::of('src/Http'), Filter::matching('HttpTest')), TestIds::of(TestId::of('HttpTest::serves'))),
    );
    $result = $finished($survivor, $measured)->withCovered($covered);
    $written = ShardResultFile::encode($result);

    expect($written)->toContain("\"judging\": [\n                \"KernelTest::boots\",\n                \"KernelTest::stops\"\n            ]")
        ->and(ShardResultFile::decode($written))->toEqual($result)
        ->and(ShardResultFile::encode($finished($survivor, $measured)))->not->toContain('"covered"');
});

it('lists what the shard warns of, and reads it back', function () use ($finished, $survivor, $measured): void {
    $result = $finished($survivor, $measured)
        ->withWarnings(Warnings::of(Warning::that('One.'), Warning::that('Two.')));
    $written = ShardResultFile::encode($result);

    expect($written)->toEndWith("    \"warnings\": [\n        \"One.\",\n        \"Two.\"\n    ]\n}")
        ->and(ShardResultFile::decode($written))->toEqual($result)
        ->and(ShardResultFile::encode($finished($survivor, $measured)))->not->toContain('"warnings"');
});

it('lists the units its budget ran out before, and reads them back', function () use ($finished, $survivor, $measured): void {
    $unjudged = Units::of(
        Unit::file(Path::of('src/Late.php')),
        Unit::held(Path::of('src/Http'), Group::named('holds:src/Http')),
    );
    $result = $finished($survivor, $measured)->withUnjudged($unjudged);
    $written = ShardResultFile::encode($result);

    expect($written)->toContain('"unjudged": [')
        ->and(ShardResultFile::decode($written))->toEqual($result)
        ->and(ShardResultFile::encode($finished($survivor, $measured)))->not->toContain('"unjudged"');
});

it('lists what static analysis\'s checks of its survivors came to, and reads it back', function () use ($finished, $survivor, $measured): void {
    $checks = SurvivorChecks::none()
        ->timing(AnalyserHistory::of('phpstan')->checked(Seconds::of(0.5)))
        ->leaving(UncheckedSurvivor::of(Unchecked::OutOfScope, Path::of('lib/Legacy.php')));
    $result = $finished($survivor, $measured)->withChecks($checks);
    $written = ShardResultFile::encode($result);

    expect($written)->toContain('"staticChecks": {')
        ->and(ShardResultFile::decode($written))->toEqual($result)
        ->and(ShardResultFile::encode($finished($survivor, $measured)))->not->toContain('"staticChecks"');
});

it('reads back a shard that could not judge', function () use ($measured): void {
    $stopped = CannotJudge::because('Pest stopped.');
    $result = ShardResult::of(Digest::of('9c1e'), ShardId::of(1), Keys::none(), $stopped, $measured);

    expect(ShardResultFile::decode(ShardResultFile::encode($result)))->toEqual($result);
});

it('refuses what is not a shard result, saying where it went wrong', function (string $json, string $where): void {
    expect(ShardResultFile::decode($json))
        ->toEqual(CannotJudge::because(sprintf('A shard result cannot be read: %s', $where)));
})->with([
    'not JSON' => ['not a result', 'the file.format is missing.'],
    'another format' => ['{"format": 2}', 'the file.format is not format 1.'],
    'a shard numbered nought' => ['{"format": 1, "plan": "9c1e", "shard": 0}', 'the file.shard is not a shard number.'],
    'mutants that are not a list' => [
        '{"format": 1, "plan": "9c1e", "shard": 1, "units": {}, "mutants": "none"}',
        'the file.mutants is not a list.',
    ],
    'no count of skipped mutants' => [
        '{"format": 1, "plan": "9c1e", "shard": 1, "units": {}, "mutants": []}',
        'the file.skipped is missing.',
    ],
    'flaky mutants that are not a list' => [
        '{"format": 1, "plan": "9c1e", "shard": 1, "units": {}, "mutants": [], "skipped": 0, '
            . '"measured": {"seconds": 1, "runner": "pest", "at": "2026-09-29T20:48:17Z"}, "flaky": "none"}',
        'the file.flaky is not a list.',
    ],
    'a flaky mutant that is no id' => [
        '{"format": 1, "plan": "9c1e", "shard": 1, "units": {}, "mutants": [], "skipped": 0, '
            . '"measured": {"seconds": 1, "runner": "pest", "at": "2026-09-29T20:48:17Z"}, '
            . '"flaky": ["0123456789ab", "no"]}',
        'the file.flaky[1] is not a mutant id.',
    ],
    'a survivor left unchecked for no reason the gate knows' => [
        '{"format": 1, "plan": "9c1e", "shard": 1, "units": {}, "cannotJudge": "x", '
            . '"measured": {"seconds": 1, "runner": "pest", "at": "2026-09-29T20:48:17Z"}, '
            . '"staticChecks": {"analysers": {}, "unchecked": [{"why": "bored", "file": "src/A.php"}]}}',
        'the file.staticChecks.unchecked[0].why is not why a survivor was left unchecked.',
    ],
    'an instant that is not one' => [
        '{"format": 1, "plan": "9c1e", "shard": 1, "units": {}, "cannotJudge": "x", '
            . '"measured": {"seconds": 1, "runner": "pest", "at": "yesterday"}}',
        'the file.measured.at is not an instant.',
    ],
]);
