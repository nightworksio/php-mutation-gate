<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\PathRule as ConfiguredPathRule;
use NightWorksIO\MutationGate\Config\Suggest as SuggestBuilder;
use NightWorksIO\MutationGate\Config\Timeouts;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Cost\MutatorTallies;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Proof\NewestProofs;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Suggest\Suggestion;
use NightWorksIO\MutationGate\Core\Suggest\Suggestions;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Tallies;

$none = static fn(): NewestProofs => Proofs::none()->newest();

$mutant = static fn(string $file, int $line, MutantStatus $status): Mutant => Mutant::of(
    MutantId::hash(Path::of($file), 'Plus', sprintf('-%d', $line), 0),
    sprintf('%d', $line),
    Location::of(Path::of($file), Line::of($line), Line::of($line)),
    Mutation::of('Plus', MutatorFamily::Arithmetic, sprintf('-%d', $line)),
    $status,
    Unmeasured::duration(),
);

$proof = static fn(string $unit, string $at, Mutant ...$mutants): Proof => Proof::of(
    Digest::sha256Of(sprintf('%s %s', $unit, $at)),
    Path::of($unit),
    Mutants::of(...$mutants),
    Run::of('local:now', Moment::at($at), Digest::sha256Of('base')),
);

/**
 * @param  list<Suggestion>             $suggestions
 * @return list<array{string, string}> each suggestion's evidence and the change it lays, as a config writes it
 */
function suggested(array $suggestions): array
{
    return array_map(
        static fn(Suggestion $suggestion): array => [
            $suggestion->why(),
            $suggestion->change()->written(ProjectRoot::origin())->line(),
        ],
        $suggestions,
    );
}

it('turns off an idle mutator, which costs time and finds nothing, the costliest first', function () use ($none): void {
    $tallies = Tallies::holding(
        Tallies::of('fake', 'Plus', 600, 600, 0, 0, 30.0),
        Tallies::of('fake', 'Minus', 700, 700, 0, 0, 60.0),
        Tallies::of('fake', 'Busy', 1_000, 900, 100, 0, 10.0),
    );

    expect(suggested(Suggestions::of($tallies, $none(), Flows::settings())))->toBe([
        [
            '`Minus` under fake: 700 judged, 0 survived, 60% of all the time (1m). It finds nothing: turn it off.',
            '{"mutators":{"except":["Minus"]}}',
        ],
        [
            '`Plus` under fake: 600 judged, 0 survived, 30% of all the time (30s). It finds nothing: turn it off.',
            '{"mutators":{"except":["Plus"]}}',
        ],
    ]);
});

it('calls no mutator idle that falls short of any threshold', function (int $mutants, int $killed, int $survived, int $ignored, float $seconds) use ($none): void {
    $tallies = Tallies::holding(
        Tallies::of('fake', 'Plus', $mutants, $killed, $survived, $ignored, $seconds),
        Tallies::of('fake', 'Other', 10, 5, 5, 0, 100.0),
    );

    expect(Suggestions::of($tallies, $none(), Flows::settings()))->toBe([]);
})->with([
    'too few judged' => [499, 499, 0, 0, 30.0],
    'a survivor' => [600, 599, 1, 0, 30.0],
    'too little of the time' => [600, 600, 0, 0, 1.0],
]);

it('reads each threshold from suggest', function () use ($none): void {
    $tallies = Tallies::holding(Tallies::of('fake', 'Plus', 10, 9, 1, 0, 1.0), Tallies::of('fake', 'Other', 10, 5, 5, 0, 9.0));
    $settings = Flows::settings(SuggestBuilder::idleJudged(10), SuggestBuilder::idleSurvivors(1), SuggestBuilder::idleShare(10));

    expect(array_column(suggested(Suggestions::of($tallies, $none(), $settings)), 1))->toBe(['{"mutators":{"except":["Plus"]}}']);
});

it('turns off a noisy mutator, most of whose mutants were ignored or equivalent, and calls an idle one idle alone', function () use ($none): void {
    $tallies = Tallies::holding(
        Tallies::of('fake', 'Noisy', 100, 10, 0, 90, 1.0),
        Tallies::of('fake', 'Few', 49, 0, 0, 49, 1.0),
        Tallies::of('fake', 'Some', 100, 30, 0, 70, 1.0),
        Tallies::of('fake', 'Both', 600, 500, 0, 100, 20.0),
        Tallies::of('fake', 'Busy', 100, 50, 50, 0, 77.0),
    );

    expect(array_column(suggested(Suggestions::of($tallies, $none(), Flows::settings())), 0))->toBe([
        '`Both` under fake: 500 judged, 0 survived, 20% of all the time (20s). It finds nothing: turn it off.',
        '`Noisy` under fake: 90 of 100 mutants (90%) ignored or equivalent. It makes noise: turn it off.',
    ]);
});

it('suggests nothing of a runner the config does not choose, and shares only its runner\'s time', function () use ($none): void {
    $tallies = Tallies::holding(
        Tallies::of('pest', 'Noisy', 100, 0, 0, 100, 1.0),
        Tallies::of('infection', 'Costly', 600, 600, 0, 0, 1_000.0),
        Tallies::of('fake', 'Plus', 600, 600, 0, 0, 10.0),
        Tallies::of('fake', 'Busy', 100, 50, 50, 0, 90.0),
    );

    expect(array_column(suggested(Suggestions::of($tallies, $none(), Flows::settings())), 1))->toBe(['{"mutators":{"except":["Plus"]}}']);
});

it('gives the files at the project\'s root every path the glob matches', function () use ($mutant, $proof): void {
    $proofs = Proofs::of($proof('Money.php', '2026-11-01T00:00:00Z', ...array_map(static fn(int $line): Mutant => $mutant('Money.php', $line, MutantStatus::TimedOut), [1, 2, 3])));

    expect(array_column(suggested(Suggestions::of(MutatorTallies::none(), $proofs->newest(), Flows::settings())), 1))
        ->toBe(['{"paths":[{"glob":"**","timeouts":{"seconds":20}}]}']);
});

it('gives each directory where enough mutants of the newest proofs ran out of time twice its timeout, the most timeouts first', function () use ($mutant, $proof): void {
    $proofs = Proofs::of(
        $proof('src/Legacy/A.php', '2026-11-01T00:00:00Z', $mutant('src/Legacy/A.php', 1, MutantStatus::TimedOut), $mutant('src/Legacy/A.php', 2, MutantStatus::Skipped)),
        $proof('src/Legacy/B.php', '2026-11-01T00:00:00Z', $mutant('src/Legacy/B.php', 1, MutantStatus::TimedOut), $mutant('src/Legacy/B.php', 2, MutantStatus::Survived)),
        $proof('src/Slow/C.php', '2026-10-01T00:00:00Z', ...array_map(static fn(int $line): Mutant => $mutant('src/Slow/C.php', $line, MutantStatus::TimedOut), [1, 2, 3, 4])),
        $proof('src/Slow/C.php', '2026-11-01T00:00:00Z', ...array_map(static fn(int $line): Mutant => $mutant('src/Slow/C.php', $line, MutantStatus::TimedOut), [1, 2, 3])),
        $proof('src/Fast/D.php', '2026-11-01T00:00:00Z', $mutant('src/Fast/D.php', 1, MutantStatus::TimedOut), $mutant('src/Fast/D.php', 2, MutantStatus::TimedOut)),
    );

    expect(suggested(Suggestions::of(MutatorTallies::none(), $proofs->newest(), Flows::settings(Timeouts::seconds(10)))))->toBe([
        [
            '`src/Legacy/`: 3 mutants of the newest proofs ran out of time at 10s. Give its files a timeout of 20s.',
            '{"paths":[{"glob":"src/Legacy/**","timeouts":{"seconds":20}}]}',
        ],
        [
            '`src/Slow/`: 3 mutants of the newest proofs ran out of time at 10s. Give its files a timeout of 20s.',
            '{"paths":[{"glob":"src/Slow/**","timeouts":{"seconds":20}}]}',
        ],
    ]);
});

it('starts from the timeout the paths entries matching a directory give it, and raises the most where it would fall below', function () use ($mutant, $proof): void {
    $proofs = Proofs::of($proof('src/Legacy/A.php', '2026-11-01T00:00:00Z', ...array_map(static fn(int $line): Mutant => $mutant('src/Legacy/A.php', $line, MutantStatus::TimedOut), [1, 2, 3])));
    $settings = Flows::settings(ConfiguredPathRule::matching('src/Legacy/**', Gate::configure()->with(Timeouts::seconds(200))));

    expect(array_column(suggested(Suggestions::of(MutatorTallies::none(), $proofs->newest(), $settings)), 1))
        ->toBe(['{"paths":[{"glob":"src/Legacy/**","timeouts":{"seconds":400,"most":400}}]}']);
});

it('sets the most a raised entry allows where the project\'s own would fall below it, so the entry is valid on its own', function () use ($mutant, $proof): void {
    $proofs = Proofs::of($proof('src/Legacy/A.php', '2026-11-01T00:00:00Z', ...array_map(static fn(int $line): Mutant => $mutant('src/Legacy/A.php', $line, MutantStatus::TimedOut), [1, 2, 3])));
    $settings = Flows::settings(
        Timeouts::most(300),
        ConfiguredPathRule::matching('src/Legacy/**', Gate::configure()->with(Timeouts::seconds(200))->with(Timeouts::most(400))),
    );
    $suggested = Suggestions::of(MutatorTallies::none(), $proofs->newest(), $settings);

    expect(array_column(suggested($suggested), 1))->toBe(['{"paths":[{"glob":"src/Legacy/**","timeouts":{"seconds":400,"most":400}}]}'])
        ->and(Settings::settled($settings->effective()->over($suggested[0]->change()), new DateTimeImmutable('2026-11-05')))->toBeInstanceOf(Settings::class);
});

it('reads how many timeouts make a directory slow from suggest', function () use ($mutant, $proof): void {
    $proofs = Proofs::of($proof('src/A.php', '2026-11-01T00:00:00Z', $mutant('src/A.php', 1, MutantStatus::TimedOut)));

    expect(Suggestions::of(MutatorTallies::none(), $proofs->newest(), Flows::settings()))->toBe([])
        ->and(Suggestions::of(MutatorTallies::none(), $proofs->newest(), Flows::settings(SuggestBuilder::timeoutCluster(1))))->toHaveCount(1);
});
