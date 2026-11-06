<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Cost\MutatorTally;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Cost\SavedLately;
use NightWorksIO\MutationGate\Core\Cost\SavingsWindow;
use NightWorksIO\MutationGate\Core\Report\Summary;
use NightWorksIO\MutationGate\Core\Report\SummarySchema;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use NightWorksIO\MutationGate\Tests\Support\Tallies;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('says the commit, time, verdict, the project\'s score, each tree\'s floor and score, the floor for new code and what was saved', function (): void {
    expect(Summary::of(Verdicts::failing(), Revision::ref('3f9a1c2'), Moment::at('2026-09-30T10:00:00Z'), Floor::of(95), SavedLately::of(Seconds::of(147_600.0), SavingsWindow::standard())))->toBe([
        'format' => 1,
        'commit' => '3f9a1c2',
        'time' => '2026-09-30T10:00:00Z',
        'verdict' => 'failed',
        'score' => 44.44,
        'trees' => [
            ['path' => 'src', 'floor' => 80.0, 'score' => 44.44],
            ['path' => 'app/Legacy'],
            ['path' => 'src/Empty', 'floor' => 90.0],
        ],
        'newCode' => ['floor' => 95.0],
        'savings' => ['seconds' => 147_600.0, 'window' => '30d'],
    ]);
});

it('leaves out a score where there is nothing to mutate, a floor no tree is held to, and savings with no history', function (): void {
    $summary = Summary::of(Verdicts::empty(), Revision::ref('3f9a1c2'), Moment::at('2026-09-30T10:00:00Z'), Floor::whole(), NoHistory::yet());

    expect($summary)->not->toHaveKey('score')
        ->and($summary)->not->toHaveKey('savings')
        ->and($summary['newCode'])->toBe(['floor' => 100.0]);
});

it('names the ten costliest mutators the default branch\'s ledger tallies, and none where it tallies none', function (): void {
    $tallies = Tallies::holding(...array_map(
        static fn(int $at): MutatorTally => Tallies::of('pest', sprintf('M%02d', $at), 4, 2, 2, 0, (float) $at),
        range(1, 12),
    ));
    $verdict = Verdicts::failing();
    $summary = Summary::of($verdict->withAccount($verdict->account()->withTallies($tallies)), Revision::ref('3f9a1c2'), Moment::at('2026-09-30T10:00:00Z'), Floor::of(95), NoHistory::yet());
    $json = Summary::json($verdict->withAccount($verdict->account()->withTallies($tallies)), Revision::ref('3f9a1c2'), Moment::at('2026-09-30T10:00:00Z'), Floor::of(95), NoHistory::yet());

    expect($summary['mutators'] ?? [])->toHaveCount(10)
        ->and(($summary['mutators'] ?? [])[0])->toBe(['runner' => 'pest', 'mutator' => 'M12', 'seconds' => 12.0, 'survived' => 2, 'killRate' => 50.0])
        ->and(($summary['mutators'] ?? [])[9]['mutator'])->toBe('M03')
        ->and(Summary::of($verdict, Revision::ref('3f9a1c2'), Moment::at('2026-09-30T10:00:00Z'), Floor::of(95), NoHistory::yet()))->not->toHaveKey('mutators')
        ->and(Schema::errors($json, Schema::at('resources/summary.schema.json')))->toBe([]);
});

it('writes what its committed schema describes', function (string $verdict): void {
    $json = Summary::json(Verdicts::named($verdict), Revision::ref('3f9a1c2'), Moment::at('2026-09-30T10:00:00Z'), Floor::of(90), SavedLately::of(Seconds::of(60.0), SavingsWindow::standard()));

    expect(Schema::errors($json, Schema::at('resources/summary.schema.json')))->toBe([])
        ->and(Schema::errors(Summary::json(Verdicts::named($verdict), Revision::ref('a'), Moment::at('2026-09-30T10:00:00Z'), Floor::of(90), NoHistory::yet()), Schema::at('resources/summary.schema.json')))->toBe([]);
})->with(['failing', 'passing', 'empty', 'cannot judge', 'sampled']);

it('commits its schema as it is generated', function (): void {
    expect((string) file_get_contents(Schema::at('resources/summary.schema.json')))
        ->toBe(sprintf("%s\n", SummarySchema::json()), 'resources/summary.schema.json is out of date. Run composer report:schema and commit it.');
});
