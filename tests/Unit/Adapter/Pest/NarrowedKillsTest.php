<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Control;
use NightWorksIO\MutationGate\Adapter\Pest\NarrowedKills;
use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\PrefixReplay;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Placed;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Adapter\Pest\ReplayedKills;
use NightWorksIO\MutationGate\Adapter\Pest\ReplayVerdict;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\OrderDigest;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\PestCases;
use NightWorksIO\MutationGate\Tests\Support\PestRun;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

/** The mutant of src/Money.php's line with this number, whose native id is `native-<line>`, with this status and killers. */
$mutant = static function (int $line, MutantStatus $status, string ...$killers): Mutant {
    $made = Verdicts::mutant(sprintf('src/Money.php:%d', $line), 'Plus', MutatorFamily::Arithmetic, sprintf('-%d', $line));

    return Mutant::of($made->id(), $made->nativeId(), $made->location(), $made->mutation(), $status, Unmeasured::duration())
        ->killedBy(TestIds::of(...array_map(TestId::of(...), $killers)));
};

/** A results file in which Pest planned the mutants of lines 1 to 7, and then wrote these lines. */
function narrowedResults(string ...$lines): string
{
    $file = sprintf('%s/results.jsonl', Scratch::directory());
    $planned = array_map(
        static fn(int $line): string => PestRun::planned(sprintf('native-%d', $line), '/p/src/Money.php', $line, 'Plus', 'a', 'b'),
        range(1, 7),
    );
    PestRun::write($file, array_values([...$planned, ...$lines]));

    return $file;
}

it('doubts a kill no test is named for, one with no record, and a narrowed one its files do not vouch for, asking for every set of files at once', function () use ($mutant): void {
    $mutants = [
        $mutant(1, MutantStatus::Killed, 'T::a'),
        $mutant(2, MutantStatus::Killed, 'T::b'),
        $mutant(3, MutantStatus::Killed, 'T::c'),
        $mutant(4, MutantStatus::Killed, 'T::d'),
        $mutant(5, MutantStatus::Killed, 'T::e'),
        $mutant(6, MutantStatus::Killed),
        $mutant(7, MutantStatus::Survived),
        $mutant(8, MutantStatus::Killed, 'T::h'),
    ];
    $file = narrowedResults(
        PestRun::killed('native-1', 'T::a'),
        PestRun::narrowed('native-1', ['/p/tests/ASpec.php', '/p/tests/BSpec.php']),
        PestRun::killed('native-2', 'T::b'),
        PestRun::narrowed('native-2', ['/p/tests/ASpec.php', '/p/tests/BSpec.php']),
        PestRun::killed('native-3', 'T::c'),
        PestRun::narrowed('native-3', ['/p/tests/CSpec.php']),
        PestRun::killed('native-4', 'T::d'),
        PestRun::errored('native-5', 'T::e'),
        PestRun::narrowed('native-7', ['/p/tests/CSpec.php']),
        PestRun::finished('native-1', PestStatus::Tested, 0.1),
    );
    $asked = [];

    $doubted = NarrowedKills::in(MutationResult::of(Mutants::of(...$mutants), 0), $file, PestCases::project())->doubted(
        Group::named('holds:src/Money.php'),
        static function (array $controls) use (&$asked): array {
            $asked[] = $controls;

            return array_map(
                static fn(Control $control): bool => array_map(static fn(Path $test): string => $test->value(), [...$control->tests()]) !== ['/p/tests/CSpec.php'],
                $controls,
            );
        },
        ReplayedKills::none(),
    );

    expect(array_map(static fn(Mutant $each): string => $each->nativeId(), [...$doubted]))
        ->toEqualCanonicalizing(['native-3', 'native-6', 'native-8'])
        ->and($asked)->toEqual([[
            Control::of(Path::of('src/Money.php'), Paths::of(Path::of('/p/tests/ASpec.php'), Path::of('/p/tests/BSpec.php')), Group::named('holds:src/Money.php')),
            Control::of(Path::of('src/Money.php'), Paths::of(Path::of('/p/tests/CSpec.php')), Group::named('holds:src/Money.php')),
        ]]);
});

it('judges a narrowed kill only tests that errored made by its control, as any other narrowed kill', function () use ($mutant): void {
    $file = narrowedResults(
        PestRun::errored('native-1', 'T::a'),
        PestRun::narrowed('native-1', ['/p/tests/ASpec.php']),
        PestRun::errored('native-2', 'T::b'),
        PestRun::narrowed('native-2', ['/p/tests/CSpec.php']),
    );
    $result = MutationResult::of(Mutants::of($mutant(1, MutantStatus::Killed, 'T::a'), $mutant(2, MutantStatus::Killed, 'T::b')), 0);

    $doubted = NarrowedKills::in($result, $file, PestCases::project())->doubted(
        WholeSuite::tests(),
        static fn(array $controls): array => array_map(
            static fn(Control $control): bool => array_map(static fn(Path $test): string => $test->value(), [...$control->tests()]) !== ['/p/tests/CSpec.php'],
            $controls,
        ),
        ReplayedKills::none(),
    );

    expect(array_map(static fn(Mutant $each): string => $each->nativeId(), [...$doubted]))->toBe(['native-2']);
});

it('asks for a control of each file a narrowed kill changes, though the runs of several loaded the same files', function () use ($mutant): void {
    $tax = Verdicts::mutant('src/Tax.php:3', 'Plus', MutatorFamily::Arithmetic, '-3');
    $taxKill = Mutant::of($tax->id(), 'native-tax', $tax->location(), $tax->mutation(), MutantStatus::Killed, Unmeasured::duration())
        ->killedBy(TestIds::of(TestId::of('T::t')));
    $file = narrowedResults(
        PestRun::planned('native-tax', '/p/src/Tax.php', 3, 'Plus', 'a', 'b'),
        PestRun::killed('native-1', 'T::a'),
        PestRun::narrowed('native-1', ['/p/tests/ASpec.php']),
        PestRun::killed('native-tax', 'T::t'),
        PestRun::narrowed('native-tax', ['/p/tests/ASpec.php']),
    );
    $asked = [];

    $doubted = NarrowedKills::in(MutationResult::of(Mutants::of($mutant(1, MutantStatus::Killed, 'T::a'), $taxKill), 0), $file, PestCases::project())->doubted(
        WholeSuite::tests(),
        static function (array $controls) use (&$asked): array {
            $asked = $controls;

            return array_map(static fn(Control $control): bool => $control->source()->value() === 'src/Money.php', $controls);
        },
        ReplayedKills::none(),
    );

    expect(array_map(static fn(Control $control): string => $control->source()->value(), $asked))->toBe(['src/Money.php', 'src/Tax.php'])
        ->and(array_map(static fn(Mutant $each): string => $each->nativeId(), [...$doubted]))->toBe(['native-tax']);
});

it('never doubts a kill of a mutant Pest left uncovered, which the trial judged', function () use ($mutant): void {
    $file = narrowedResults(
        PestRun::finished('native-1', PestStatus::Uncovered, 0.0),
        PestRun::finished('native-2', PestStatus::Tested, 0.1),
    );

    $doubted = NarrowedKills::in(
        MutationResult::of(Mutants::of($mutant(1, MutantStatus::Killed), $mutant(2, MutantStatus::Killed)), 0),
        $file,
        PestCases::project(),
    )->doubted(
        WholeSuite::tests(),
        static fn(array $controls): array => array_map(static fn(): bool => true, $controls),
        ReplayedKills::none(),
    );

    expect(array_map(static fn(Mutant $each): string => $each->nativeId(), [...$doubted]))->toBe(['native-2']);
});

it('doubts every kill, and asks nothing, where the records cannot be read', function () use ($mutant): void {
    $killed = $mutant(1, MutantStatus::Killed, 'T::a');
    $asked = 0;

    $doubted = NarrowedKills::in(
        MutationResult::of(Mutants::of($killed, $mutant(2, MutantStatus::Survived)), 0),
        sprintf('%s/missing.jsonl', Scratch::directory()),
        PestCases::project(),
    )->doubted(WholeSuite::tests(), static function (array $controls) use (&$asked): array {
        $asked++;

        return array_map(static fn(): bool => true, $controls);
    }, ReplayedKills::none());

    expect([...$doubted])->toEqual([$killed])
        ->and($asked)->toBe(0);
});

it('doubts a narrowed kill whose set of files the answer leaves out', function () use ($mutant): void {
    $file = narrowedResults(
        PestRun::killed('native-1', 'T::a'),
        PestRun::narrowed('native-1', ['/p/tests/ASpec.php']),
        PestRun::killed('native-2', 'T::b'),
        PestRun::narrowed('native-2', ['/p/tests/BSpec.php']),
    );

    $doubted = NarrowedKills::in(
        MutationResult::of(Mutants::of($mutant(1, MutantStatus::Killed, 'T::a'), $mutant(2, MutantStatus::Killed, 'T::b')), 0),
        $file,
        PestCases::project(),
    )->doubted(WholeSuite::tests(), static fn(): array => [true], ReplayedKills::none());

    expect(array_map(static fn(Mutant $each): string => $each->nativeId(), [...$doubted]))->toBe(['native-2']);
});

it('replays a narrowed kill only tests that errored made where its order is known, and doubts it where the replay does not let it stand', function (ReplayVerdict $verdict, array $doubts) use ($mutant): void {
    $at = PestCases::project();
    $loaded = sprintf('%s/tests/ASpec.php', $at->root());
    $order = OrderDigest::of(TestId::of('T::x'), TestId::of('T::a'))->value();
    $file = narrowedResults(
        PestRun::erroredAt('native-1', 'T::a', Placed::at(2, $order, 9)),
        PestRun::narrowed('native-1', [$loaded]),
        rtrim(RecordLine::arguments(PestRun::mutated('native-1'), ['vendor/bin/pest', '--bail', '--filter=ASpec', $loaded])),
        rtrim(RecordLine::limited(PestRun::mutated('native-1'), 2.0)),
    );
    $kills = NarrowedKills::in(MutationResult::of(Mutants::of($mutant(1, MutantStatus::Killed, 'T::a')), 0), $file, $at);
    $replayed = [];
    $controls = [];

    $unvouched = $kills->unvouched(static function (array $replays) use (&$replayed, $verdict): array {
        $replayed = $replays;

        return [$verdict];
    });
    $doubted = $kills->doubted(WholeSuite::tests(), static function (array $asking) use (&$controls): array {
        $controls = $asking;

        return [];
    }, $unvouched);
    $files = Paths::of(Path::of('tests/ASpec.php'));

    expect($replayed)->toEqual([PrefixReplay::of(
        Path::of('src/Money.php'),
        PestRun::mutated('native-1'),
        ['vendor/bin/pest', '--bail', '--filter=ASpec', $loaded],
        $files,
        2,
        Prefix::keyOf($files, $order),
        Seconds::of(2.0),
    )])
        ->and($controls)->toBe([])
        ->and([...$unvouched->unjudged()])->toBe([])
        ->and(array_map(static fn(Mutant $each): string => $each->nativeId(), [...$doubted]))->toBe($doubts);
})->with([
    'a replay that lets it stand' => [ReplayVerdict::Stands, []],
    'a replay in which a test fails unmutated too' => [ReplayVerdict::Failed, ['native-1']],
    'a replay with no time left' => [ReplayVerdict::NoTime, ['native-1']],
]);

it('replays each narrowed kill whose order is known, once for an order and file within the longer limit of its kills, in place of a control of its files, and leaves unjudged those its replay does not let stand', function (ReplayVerdict $verdict, array $unjudged) use ($mutant): void {
    $at = PestCases::project();
    $loaded = sprintf('%s/tests/ASpec.php', $at->root());
    $order = OrderDigest::of(TestId::of('T::x'), TestId::of('T::a'))->value();
    $file = narrowedResults(
        PestRun::killedAt('native-1', 'T::a', Placed::at(2, $order, 9)),
        PestRun::narrowed('native-1', [$loaded]),
        rtrim(RecordLine::arguments(PestRun::mutated('native-1'), ['vendor/bin/pest', '--bail', '--filter=ASpec', $loaded])),
        rtrim(RecordLine::limited(PestRun::mutated('native-1'), 0.5)),
        PestRun::killed('native-2', 'T::b'),
        PestRun::narrowed('native-2', [$loaded]),
        PestRun::killedAt('native-3', 'T::a', Placed::at(2, $order, 9)),
        PestRun::narrowed('native-3', [$loaded]),
        rtrim(RecordLine::arguments(PestRun::mutated('native-3'), ['vendor/bin/pest', '--bail', '--filter=ASpec', $loaded])),
        rtrim(RecordLine::limited(PestRun::mutated('native-3'), 5.0)),
    );
    $asked = [];
    $kills = NarrowedKills::in(
        MutationResult::of(Mutants::of(
            $mutant(1, MutantStatus::Killed, 'T::a'),
            $mutant(2, MutantStatus::Killed, 'T::b'),
            $mutant(3, MutantStatus::Killed, 'T::a'),
        ), 0),
        $file,
        $at,
    );

    $unvouched = $kills->unvouched(static function (array $replays) use (&$asked, $verdict): array {
        $asked = $replays;

        return [$verdict];
    });
    $controls = [];
    $kills->doubted(WholeSuite::tests(), static function (array $asking) use (&$controls): array {
        $controls = $asking;

        return [true];
    }, $unvouched);
    $files = Paths::of(Path::of('tests/ASpec.php'));

    expect(array_map(static fn(Mutant $each): array => [$each->nativeId(), $each->status(), $each->reason()], [...$unvouched->unjudged()]))->toEqual($unjudged)
        ->and($asked)->toEqual([PrefixReplay::of(
            Path::of('src/Money.php'),
            PestRun::mutated('native-1'),
            ['vendor/bin/pest', '--bail', '--filter=ASpec', $loaded],
            $files,
            2,
            Prefix::keyOf($files, $order),
            Seconds::of(5.0),
        )])
        ->and($controls)->toEqual([Control::of(Path::of('src/Money.php'), Paths::of(Path::of($loaded)), WholeSuite::tests())])
        ->and($kills->sets())->toBe(2);
})->with([
    'a replay that lets it stand' => [ReplayVerdict::Stands, []],
    'a replay that does not' => [ReplayVerdict::OtherOrder, fn(): array => [
        ['native-1', MutantStatus::Unjudged, ReplayVerdict::OtherOrder->reason()],
        ['native-3', MutantStatus::Unjudged, ReplayVerdict::OtherOrder->reason()],
    ]],
]);

it('replays the narrowed kills of one order once for each file they change, and those of one file once for each order, leaving unjudged the kills of each replay that does not let them stand', function (): void {
    $at = PestCases::project();
    $loaded = sprintf('%s/tests/ASpec.php', $at->root());
    $first = OrderDigest::of(TestId::of('T::x'), TestId::of('T::a'))->value();
    $second = OrderDigest::of(TestId::of('T::y'), TestId::of('T::a'))->value();
    $arguments = ['vendor/bin/pest', '--bail', '--filter=ASpec', $loaded];
    $kill = static fn(string $native, string $order): array => [
        PestRun::killedAt($native, 'T::a', Placed::at(2, $order, 9)),
        PestRun::narrowed($native, [$loaded]),
        rtrim(RecordLine::arguments(PestRun::mutated($native), $arguments)),
        rtrim(RecordLine::limited(PestRun::mutated($native), 2.0)),
    ];
    $file = sprintf('%s/results.jsonl', Scratch::directory());
    PestRun::write($file, [
        PestRun::planned('native-1', '/p/src/Money.php', 1, 'Plus', 'a', 'b'),
        PestRun::planned('native-2', '/p/src/Money.php', 2, 'Plus', 'a', 'b'),
        PestRun::planned('native-3', '/p/src/Other.php', 3, 'Plus', 'a', 'b'),
        ...$kill('native-1', $first),
        ...$kill('native-2', $second),
        ...$kill('native-3', $first),
    ]);
    $killed = static function (string $at, string $native): Mutant {
        $made = Verdicts::mutant($at, 'Plus', MutatorFamily::Arithmetic, $native);

        return Mutant::of($made->id(), $native, $made->location(), $made->mutation(), MutantStatus::Killed, Unmeasured::duration())
            ->killedBy(TestIds::of(TestId::of('T::a')));
    };
    $kills = NarrowedKills::in(MutationResult::of(Mutants::of(
        $killed('src/Money.php:1', 'native-1'),
        $killed('src/Money.php:2', 'native-2'),
        $killed('src/Other.php:3', 'native-3'),
    ), 0), $file, $at);
    $asked = [];

    $unvouched = $kills->unvouched(static function (array $replays) use (&$asked): array {
        $asked = $replays;

        return [ReplayVerdict::OtherOrder, ReplayVerdict::OtherOrder, ReplayVerdict::Stands];
    });
    $files = Paths::of(Path::of('tests/ASpec.php'));
    $replay = static fn(string $changed, string $native, string $order): PrefixReplay => PrefixReplay::of(
        Path::of($changed),
        PestRun::mutated($native),
        $arguments,
        $files,
        2,
        Prefix::keyOf($files, $order),
        Seconds::of(2.0),
    );

    expect($asked)->toEqual([
        $replay('src/Money.php', 'native-1', $first),
        $replay('src/Money.php', 'native-2', $second),
        $replay('src/Other.php', 'native-3', $first),
    ])
        ->and(array_map(static fn(Mutant $mutant): string => $mutant->nativeId(), [...$unvouched->unjudged()]))->toBe(['native-1', 'native-2'])
        ->and($kills->sets())->toBe(3);
});

it('neither replays nor controls a kill of a run no file narrowed, though its order is known', function () use ($mutant): void {
    $at = PestCases::project();
    $order = OrderDigest::of(TestId::of('T::x'), TestId::of('T::a'))->value();
    $file = narrowedResults(
        PestRun::killedAt('native-1', 'T::a', Placed::at(2, $order, 9)),
        rtrim(RecordLine::arguments(PestRun::mutated('native-1'), ['vendor/bin/pest', '--bail'])),
        rtrim(RecordLine::limited(PestRun::mutated('native-1'), 2.0)),
    );
    $kills = NarrowedKills::in(MutationResult::of(Mutants::of($mutant(1, MutantStatus::Killed, 'T::a')), 0), $file, $at);
    $asked = [];
    $controls = [];

    $unvouched = $kills->unvouched(static function (array $replays) use (&$asked): array {
        $asked = $replays;

        return [];
    });
    $doubted = $kills->doubted(WholeSuite::tests(), static function (array $asking) use (&$controls): array {
        $controls = $asking;

        return [];
    }, $unvouched);

    expect([$asked, $controls, [...$unvouched->unjudged()], [...$doubted], $kills->sets()])->toBe([[], [], [], [], 0]);
});

it('leaves unjudged the kills a failed assertion made, and doubts those only errors made, of each replay that does not let them stand, past one that does', function () use ($mutant): void {
    $at = PestCases::project();
    $loaded = sprintf('%s/tests/ASpec.php', $at->root());
    $arguments = ['vendor/bin/pest', '--bail', '--filter=ASpec', $loaded];
    $order = static fn(string $before): string => OrderDigest::of(TestId::of($before), TestId::of('T::a'))->value();
    $kill = static fn(string $native, string $record): array => [
        $record,
        PestRun::narrowed($native, [$loaded]),
        rtrim(RecordLine::arguments(PestRun::mutated($native), $arguments)),
        rtrim(RecordLine::limited(PestRun::mutated($native), 2.0)),
    ];
    $file = narrowedResults(
        ...$kill('native-1', PestRun::erroredAt('native-1', 'T::a', Placed::at(2, $order('T::w'), 9))),
        ...$kill('native-2', PestRun::erroredAt('native-2', 'T::a', Placed::at(2, $order('T::x'), 9))),
        ...$kill('native-3', PestRun::killedAt('native-3', 'T::a', Placed::at(2, $order('T::x'), 9))),
        ...$kill('native-4', PestRun::erroredAt('native-4', 'T::a', Placed::at(2, $order('T::y'), 9))),
    );
    $kills = NarrowedKills::in(MutationResult::of(Mutants::of(
        ...array_map(static fn(int $line): Mutant => $mutant($line, MutantStatus::Killed, 'T::a'), [1, 2, 3, 4]),
    ), 0), $file, $at);

    $unvouched = $kills->unvouched(static fn(): array => [
        ReplayVerdict::Stands,
        ReplayVerdict::OtherOrder,
        ReplayVerdict::OtherOrder,
    ]);
    $doubted = $kills->doubted(WholeSuite::tests(), static fn(): array => [], $unvouched);

    expect(array_map(static fn(Mutant $each): array => [$each->nativeId(), $each->status()], [...$unvouched->unjudged()]))
        ->toBe([['native-3', MutantStatus::Unjudged]])
        ->and(array_map(static fn(Mutant $each): array => [$each->nativeId(), $each->status()], [...$doubted]))
        ->toBe([['native-2', MutantStatus::Killed], ['native-4', MutantStatus::Killed]])
        ->and($kills->sets())->toBe(3);
});
