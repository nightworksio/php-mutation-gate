<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistory;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Analysis\Unchecked;
use NightWorksIO\MutationGate\Core\Analysis\UncheckedSurvivor;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\Covered;
use NightWorksIO\MutationGate\Core\Hold\HeldChecks;
use NightWorksIO\MutationGate\Core\Hold\NotCovered;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Proof\GateRelease;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Core\Verdict\DoomedBy;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

$makeMeasured = static fn(): Measurement => Measurement::of(Seconds::of(42.5), 'pest', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')));

$makeSurvivor = static fn(): Mutant => Mutant::of(
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
    $makeSurvivor,
    $makeMeasured,
): void {
    $survivor = $makeSurvivor();
    $measured = $makeMeasured();

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

it('writes why the runner could not judge instead of mutants', function () use ($makeMeasured): void {
    $measured = $makeMeasured();

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

it('reads back a finished shard it wrote', function () use ($finished, $makeSurvivor, $makeMeasured): void {
    $survivor = $makeSurvivor();
    $measured = $makeMeasured();

    $result = $finished($survivor, $measured);

    expect(ShardResultFile::decode(ShardResultFile::encode($result)))->toEqual($result);
});

it('writes the gate that measured a shard, and reads it back, and reads none from a result that names none', function () use ($finished, $makeSurvivor, $makeMeasured): void {
    $survivor = $makeSurvivor();
    $measured = $makeMeasured();

    $result = $finished($survivor, $measured->measuredBy(GateRelease::spelt('nightworksio/mutation-gate 1.2.0')));
    $read = ShardResultFile::decode(ShardResultFile::encode($result));

    expect(ShardResultFile::encode($result))->toContain('"measuredBy": "nightworksio/mutation-gate 1.2.0"')
        ->and($read instanceof ShardResult ? $read->measured()->gate()->value() : $read)->toBe('nightworksio/mutation-gate 1.2.0')
        ->and(ShardResultFile::encode($finished($survivor, $measured)))->not->toContain('measuredBy');
});

it('writes each kill\'s evidence beside its mutant, and reads it back', function () use ($makeMeasured): void {
    $measured = $makeMeasured();

    $killed = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', "-a + b\n+a - b", 0),
        '8',
        Location::of(Path::of('src/Money.php'), Line::of(9), Line::of(9)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, "-a + b\n+a - b"),
        MutantStatus::Killed,
        Seconds::of(0.5),
    );
    $evidence = Evidence::none()
        ->withPrefix(Prefix::keyedAt(3, '0123456789ab'))
        ->withEnded(Ended::of(255, signalled: false, printed: "PHP Fatal error\n"));
    $result = ShardResult::of(
        Digest::of('9c1e'),
        ShardId::of(1),
        Keys::none(),
        MutationResult::of(Mutants::of($killed), 0)->withEvidence(Evidences::none()->with($killed->id(), $evidence)),
        $measured,
    );
    $written = ShardResultFile::encode($result);

    expect($written)->toContain("\"prefix\": {\n                \"at\": 3,\n                \"key\": \"0123456789ab\"\n            },\n"
        . "            \"ended\": {\n                \"code\": 255,\n                \"signalled\": false,\n                \"tail\": \"PHP Fatal error\\n\"\n            }")
        ->and(ShardResultFile::decode($written))->toEqual($result);
});

it('writes only the evidence a runner gave: a prefix without its key, a process end without its code', function () use ($makeMeasured): void {
    $measured = $makeMeasured();

    $killed = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', "-a + b\n+a - b", 0),
        '8',
        Location::of(Path::of('src/Money.php'), Line::of(9), Line::of(9)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, "-a + b\n+a - b"),
        MutantStatus::Killed,
        Seconds::of(0.5),
    );
    $evidence = Evidence::none()->withPrefix(Prefix::at(1))->withEnded(Ended::of(NotGiven::value(), NotGiven::value(), 'Timeout'));
    $result = ShardResult::of(
        Digest::of('9c1e'),
        ShardId::of(1),
        Keys::none(),
        MutationResult::of(Mutants::of($killed), 0)->withEvidence(Evidences::none()->with($killed->id(), $evidence)),
        $measured,
    );
    $written = ShardResultFile::encode($result);

    expect($written)->toContain("\"prefix\": {\n                \"at\": 1\n            },\n            \"ended\": {\n                \"tail\": \"Timeout\"\n            }")
        ->and(ShardResultFile::decode($written))->toEqual($result)
        ->and(ShardResultFile::encode($result->withFlaky(MutantIds::none())))->toBe($written);
});

it('writes a process end that kept nothing it printed as its code and signal alone, and reads it back', function () use ($makeMeasured): void {
    $measured = $makeMeasured();

    $killed = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', "-a + b\n+a - b", 0),
        '8',
        Location::of(Path::of('src/Money.php'), Line::of(9), Line::of(9)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, "-a + b\n+a - b"),
        MutantStatus::Killed,
        Seconds::of(0.5),
    );
    $evidence = Evidence::none()->withEnded(Ended::unprinted(2, signalled: false));
    $result = ShardResult::of(
        Digest::of('9c1e'),
        ShardId::of(1),
        Keys::none(),
        MutationResult::of(Mutants::of($killed), 0)->withEvidence(Evidences::none()->with($killed->id(), $evidence)),
        $measured,
    );
    $written = ShardResultFile::encode($result);

    expect($written)->toContain("\"ended\": {\n                \"code\": 2,\n                \"signalled\": false\n            }")
        ->and(ShardResultFile::decode($written))->toEqual($result);
});

it('writes whether PHP recorded a fatal error in a kill\'s process beside its code, and reads it back', function () use ($makeMeasured): void {
    $measured = $makeMeasured();

    $killed = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', "-a + b\n+a - b", 0),
        '8',
        Location::of(Path::of('src/Money.php'), Line::of(9), Line::of(9)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, "-a + b\n+a - b"),
        MutantStatus::Killed,
        Seconds::of(0.5),
    );
    $evidence = Evidence::none()->withEnded(Ended::unprinted(255, signalled: false)->withFatal(fatal: true));
    $result = ShardResult::of(
        Digest::of('9c1e'),
        ShardId::of(1),
        Keys::none(),
        MutationResult::of(Mutants::of($killed), 0)->withEvidence(Evidences::none()->with($killed->id(), $evidence)),
        $measured,
    );
    $written = ShardResultFile::encode($result);

    expect($written)->toContain("\"ended\": {\n                \"code\": 255,\n                \"signalled\": false,\n                \"fatal\": true\n            }")
        ->and(ShardResultFile::decode($written))->toEqual($result);
});

it('lists the mutants that gave two answers after the rest, and reads them back', function () use (
    $finished,
    $makeSurvivor,
    $makeMeasured,
): void {
    $survivor = $makeSurvivor();
    $measured = $makeMeasured();

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
    $makeSurvivor,
    $makeMeasured,
): void {
    $survivor = $makeSurvivor();
    $measured = $makeMeasured();

    $held = HeldChecks::none()
        ->with(NotCovered::because(Unit::held(Path::of('src/Kernel.php'), Group::named('holds:src/Kernel.php')), 'Missed 48.'))
        ->with(NotCovered::because(Unit::held(Path::of('src/Http'), Filter::matching('HttpTest')), 'Missed all.'));
    $result = $finished($survivor, $measured)->withHeld($held);
    $written = ShardResultFile::encode($result);

    expect($written)->toContain('"missed": [')
        ->and(ShardResultFile::decode($written))->toEqual($result)
        ->and(ShardResultFile::encode($finished($survivor, $measured)))->not->toContain('"missed"');
});

it('lists each held unit its holding tests cover, with those of them that run it, and reads them back', function () use (
    $finished,
    $makeSurvivor,
    $makeMeasured,
): void {
    $survivor = $makeSurvivor();
    $measured = $makeMeasured();

    $held = HeldChecks::none()
        ->with(Covered::by(
            Unit::held(Path::of('src/Kernel.php'), Group::named('holds:src/Kernel.php')),
            TestIds::of(TestId::of('KernelTest::boots'), TestId::of('KernelTest::stops')),
        ))
        ->with(Covered::by(Unit::held(Path::of('src/Http'), Filter::matching('HttpTest')), TestIds::of(TestId::of('HttpTest::serves'))));
    $result = $finished($survivor, $measured)->withHeld($held);
    $written = ShardResultFile::encode($result);

    expect($written)->toContain("\"judging\": [\n                \"KernelTest::boots\",\n                \"KernelTest::stops\"\n            ]")
        ->and(ShardResultFile::decode($written))->toEqual($result)
        ->and(ShardResultFile::encode($finished($survivor, $measured)))->not->toContain('"covered"');
});

it('lists what the shard warns of, and reads it back', function () use ($finished, $makeSurvivor, $makeMeasured): void {
    $survivor = $makeSurvivor();
    $measured = $makeMeasured();

    $result = $finished($survivor, $measured)
        ->withWarnings(Warnings::of(Warning::that('One.'), Warning::that('Two.')));
    $written = ShardResultFile::encode($result);

    expect($written)->toEndWith("    \"warnings\": [\n        \"One.\",\n        \"Two.\"\n    ]\n}")
        ->and(ShardResultFile::decode($written))->toEqual($result)
        ->and(ShardResultFile::encode($finished($survivor, $measured)))->not->toContain('"warnings"');
});

it('lists the units its budget ran out before, and reads them back', function () use ($finished, $makeSurvivor, $makeMeasured): void {
    $survivor = $makeSurvivor();
    $measured = $makeMeasured();

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

it('names the survivor that made the run certain to fail, where the shard stopped on one, and reads it back', function (DoomedBy $by, string $why) use ($finished, $makeSurvivor, $makeMeasured): void {
    $survivor = $makeSurvivor();
    $measured = $makeMeasured();

    $doomed = Doomed::of(Path::of('src/Money.php'), $survivor->id(), Path::of('src'), Floor::whole(), $by);
    $result = $finished($survivor, $measured)->withDoomed($doomed);
    $written = ShardResultFile::encode($result);

    expect($written)->toEndWith(sprintf(
        "    \"doomed\": {\n        \"unit\": \"src/Money.php\",\n        \"mutant\": \"%s\",\n"
            . "        \"tree\": \"src\",\n        \"floor\": 100,\n        \"why\": \"%s\"\n    }\n}",
        $survivor->id()->value(),
        $why,
    ))
        ->and(ShardResultFile::decode($written))->toEqual($result)
        ->and(ShardResultFile::encode($finished($survivor, $measured)))->not->toContain('"doomed"')
        ->and(ShardResultFile::decode(ShardResultFile::encode($finished($survivor, $measured))))
        ->toEqual($finished($survivor, $measured));
})->with([
    'by its tree' => [DoomedBy::Tree, 'tree'],
    'by the new code' => [DoomedBy::NewCode, 'newCode'],
]);

it('reads back the floor a doom names as it was written', function () use ($finished, $makeSurvivor, $makeMeasured): void {
    $survivor = $makeSurvivor();
    $measured = $makeMeasured();

    $doomed = Doomed::of(Path::of('src/Money.php'), $survivor->id(), Path::of('src'), Floor::of(87.5), DoomedBy::Tree);
    $read = ShardResultFile::decode(ShardResultFile::encode($finished($survivor, $measured)->withDoomed($doomed)));

    expect($read instanceof ShardResult && $read->doomed() instanceof Doomed ? $read->doomed()->floor()->hundredths() : $read)
        ->toBe(8_750);
});

it('lists what static analysis\'s checks of its survivors came to, and reads it back', function () use ($finished, $makeSurvivor, $makeMeasured): void {
    $survivor = $makeSurvivor();
    $measured = $makeMeasured();

    $checks = SurvivorChecks::none()
        ->timing(AnalyserHistory::of('phpstan')->checked(Seconds::of(0.5)))
        ->leaving(UncheckedSurvivor::of(Unchecked::OutOfScope, Path::of('lib/Legacy.php')));
    $result = $finished($survivor, $measured)->withChecks($checks);
    $written = ShardResultFile::encode($result);

    expect($written)->toContain('"staticChecks": {')
        ->and(ShardResultFile::decode($written))->toEqual($result)
        ->and(ShardResultFile::encode($finished($survivor, $measured)))->not->toContain('"staticChecks"');
});

it('lists the steps the shard\'s time went to under what it measured, even one, a count only past one, and reads them back', function () use ($finished, $makeSurvivor, $makeMeasured): void {
    $survivor = $makeSurvivor();
    $measured = $makeMeasured();

    $steps = StepTimes::of(
        StepTime::of(Step::Mutation, Seconds::of(2.0), Seconds::of(30.5), 12),
        StepTime::of(Step::Baselines, Seconds::of(33.0), Seconds::of(4.25)),
    );
    $result = $finished($survivor, $measured->withSteps($steps));
    $written = ShardResultFile::encode($result);

    expect($written)->toContain(
        "\"steps\": [\n            {\n                \"step\": \"mutation\",\n                \"since\": 2.0,\n"
            . "                \"seconds\": 30.5,\n                \"count\": 12\n            },\n"
            . "            {\n                \"step\": \"baselines\",\n                \"since\": 33.0,\n"
            . "                \"seconds\": 4.25\n            }\n        ]",
    )
        ->and(ShardResultFile::decode($written))->toEqual($result)
        ->and(ShardResultFile::encode($finished($survivor, $measured->withSteps(StepTimes::of(StepTime::of(Step::Baselines, Seconds::of(1.0), Seconds::of(2.0)))))))
        ->toContain('"steps": [')
        ->and(ShardResultFile::encode($finished($survivor, $measured)))->not->toContain('"steps"');
});

it('reads back a shard that could not judge', function () use ($makeMeasured): void {
    $measured = $makeMeasured();

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
    'a step the gate does not know' => [
        '{"format": 1, "plan": "9c1e", "shard": 1, "units": {}, "cannotJudge": "x", '
            . '"measured": {"seconds": 1, "runner": "pest", "at": "2026-09-29T20:48:17Z", '
            . '"steps": [{"step": "dozing", "since": 0, "seconds": 1}]}}',
        'the file.measured.steps[0].step is not a step.',
    ],
    'an instant that is not one' => [
        '{"format": 1, "plan": "9c1e", "shard": 1, "units": {}, "cannotJudge": "x", '
            . '"measured": {"seconds": 1, "runner": "pest", "at": "yesterday"}}',
        'the file.measured.at is not an instant.',
    ],
    'a doom for no reason the gate knows' => [
        '{"format": 1, "plan": "9c1e", "shard": 1, "units": {}, "cannotJudge": "x", '
            . '"measured": {"seconds": 1, "runner": "pest", "at": "2026-09-29T20:48:17Z"}, '
            . '"doomed": {"unit": "src/A.php", "mutant": "0123456789ab", "tree": "src", "floor": 100, "why": "fate"}}',
        'the file.doomed.why is not why a survivor dooms a run.',
    ],
    'a doom by a mutant that is no id' => [
        '{"format": 1, "plan": "9c1e", "shard": 1, "units": {}, "cannotJudge": "x", '
            . '"measured": {"seconds": 1, "runner": "pest", "at": "2026-09-29T20:48:17Z"}, '
            . '"doomed": {"unit": "src/A.php", "mutant": "no", "tree": "src", "floor": 100, "why": "tree"}}',
        'the file.doomed.mutant is not a mutant id.',
    ],
    'a doom by a floor that is no percentage' => [
        '{"format": 1, "plan": "9c1e", "shard": 1, "units": {}, "cannotJudge": "x", '
            . '"measured": {"seconds": 1, "runner": "pest", "at": "2026-09-29T20:48:17Z"}, '
            . '"doomed": {"unit": "src/A.php", "mutant": "0123456789ab", "tree": "src", "floor": 101, "why": "tree"}}',
        'the file.doomed.floor is not a percentage.',
    ],
    'a doom that names no tree' => [
        '{"format": 1, "plan": "9c1e", "shard": 1, "units": {}, "cannotJudge": "x", '
            . '"measured": {"seconds": 1, "runner": "pest", "at": "2026-09-29T20:48:17Z"}, '
            . '"doomed": {"unit": "src/A.php", "mutant": "0123456789ab", "floor": 100, "why": "tree"}}',
        'the file.doomed.tree is missing.',
    ],
    'a prefix at nought' => [
        '{"format": 1, "plan": "9c1e", "shard": 1, "units": {}, "mutants": [{"id": "0123456789ab", "native": "1", "file": "src/A.php", "line": 1, "mutator": "Plus", "family": "arithmetic", "diff": "", "status": "killed", "prefix": {"at": 0}}], "skipped": 0, '
            . '"measured": {"seconds": 1, "runner": "pest", "at": "2026-09-29T20:48:17Z"}}',
        'the file.mutants[0].prefix.at is not a position, which counts from one.',
    ],
    'a prefix whose key is not twelve hex digits' => [
        '{"format": 1, "plan": "9c1e", "shard": 1, "units": {}, "mutants": [{"id": "0123456789ab", "native": "1", "file": "src/A.php", "line": 1, "mutator": "Plus", "family": "arithmetic", "diff": "", "status": "killed", "prefix": {"at": 1, "key": "XYZ"}}], "skipped": 0, '
            . '"measured": {"seconds": 1, "runner": "pest", "at": "2026-09-29T20:48:17Z"}}',
        'the file.mutants[0].prefix.key is not twelve lowercase hex digits.',
    ],
    'a process end whose tail is not text' => [
        '{"format": 1, "plan": "9c1e", "shard": 1, "units": {}, "mutants": [{"id": "0123456789ab", "native": "1", "file": "src/A.php", "line": 1, "mutator": "Plus", "family": "arithmetic", "diff": "", "status": "killed", "ended": {"code": 1, "tail": 5}}], "skipped": 0, '
            . '"measured": {"seconds": 1, "runner": "pest", "at": "2026-09-29T20:48:17Z"}}',
        'the file.mutants[0].ended.tail is not text.',
    ],
    'a process end whose signal is not yes or no' => [
        '{"format": 1, "plan": "9c1e", "shard": 1, "units": {}, "mutants": [{"id": "0123456789ab", "native": "1", "file": "src/A.php", "line": 1, "mutator": "Plus", "family": "arithmetic", "diff": "", "status": "killed", "ended": {"signalled": "yes", "tail": ""}}], "skipped": 0, '
            . '"measured": {"seconds": 1, "runner": "pest", "at": "2026-09-29T20:48:17Z"}}',
        'the file.mutants[0].ended.signalled is not true or false.',
    ],
    'a process end whose fatal error is not yes or no' => [
        '{"format": 1, "plan": "9c1e", "shard": 1, "units": {}, "mutants": [{"id": "0123456789ab", "native": "1", "file": "src/A.php", "line": 1, "mutator": "Plus", "family": "arithmetic", "diff": "", "status": "killed", "ended": {"code": 255, "fatal": 1}}], "skipped": 0, '
            . '"measured": {"seconds": 1, "runner": "pest", "at": "2026-09-29T20:48:17Z"}}',
        'the file.mutants[0].ended.fatal is not true or false.',
    ],
]);
