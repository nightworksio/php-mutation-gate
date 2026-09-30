<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily as Family;
use NightWorksIO\MutationGate\Core\Report\Trend;
use NightWorksIO\MutationGate\Core\Report\TrendEntry;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement as Judged;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('appends a verdict\'s entry: its commit, time, judgement, the project\'s score, each tree\'s and each floor', function (): void {
    $trend = Trend::none()->with(Verdicts::failing(), Revision::ref('abc123'), Moment::at('2026-09-30T10:00:00Z'));

    expect(Decoded::at($trend->json()))->toBe([
        'format' => 1,
        'runs' => [[
            'commit' => 'abc123',
            'time' => '2026-09-30T10:00:00Z',
            'verdict' => 'failed',
            'score' => 44.44,
            'trees' => ['src' => 44.44],
            'floors' => ['src' => 80.0, 'src/Empty' => 90.0],
        ]],
    ])
        ->and(iterator_to_array($trend->scores(), preserve_keys: false))->toBe([44.44]);
});

it('leaves out the score of a set with nothing to mutate', function (): void {
    $trend = Trend::none()->with(Verdicts::empty(), Revision::ref('abc123'), Moment::at('2026-09-30T10:00:00Z'));

    expect(Decoded::at($trend->json(), 'runs', 0))->toBe([
        'commit' => 'abc123',
        'time' => '2026-09-30T10:00:00Z',
        'verdict' => 'passed',
        'trees' => [],
        'floors' => ['src' => 80.0],
    ])
        ->and(iterator_to_array($trend->scores(), preserve_keys: false))->toBe([]);
});

it('reads back what it wrote and appends to it', function (): void {
    $written = Trend::none()->with(Verdicts::failing(), Revision::ref('one'), Moment::at('2026-09-30T10:00:00Z'))->json();
    $trend = Trend::decode($written)->with(Verdicts::passing(), Revision::ref('two'), Moment::at('2026-09-30T11:00:00Z'));

    expect(iterator_to_array($trend->scores(), preserve_keys: false))->toBe([44.44, 100.0])
        ->and(Decoded::column($trend->json(), 'commit', 'runs'))->toBe(['one', 'two']);
});

it('keeps the newest 500 entries', function (): void {
    $runs = [];

    for ($run = 1; $run <= 500; ++$run) {
        $runs[] = ['commit' => sprintf('c%d', $run), 'time' => '2026-09-30T10:00:00Z', 'score' => 50.0, 'trees' => []];
    }

    $trend = Trend::decode((string) json_encode(['format' => 1, 'runs' => $runs]))->with(Verdicts::passing(), Revision::ref('c501'), Moment::at('2026-09-30T11:00:00Z'));
    $commits = Decoded::column($trend->json(), 'commit', 'runs');

    expect($commits)->toHaveCount(500)
        ->and($commits[0])->toBe('c2')
        ->and($commits[499])->toBe('c501');
});

it('drops an entry that is not in shape, and reads text that is not a trend as none', function (): void {
    $trend = Trend::decode((string) json_encode(['format' => 1, 'runs' => [
        ['commit' => 'kept', 'time' => '2026-09-30T10:00:00Z', 'score' => 80, 'trees' => ['src' => 80]],
        ['commit' => 'no time', 'trees' => []],
        ['commit' => 'a tree as text', 'time' => '2026-09-30T10:00:00Z', 'trees' => ['src' => 'high']],
        ['commit' => 'a score past 100', 'time' => '2026-09-30T10:00:00Z', 'trees' => ['src' => 101]],
        ['commit' => 'a floor below 0', 'time' => '2026-09-30T10:00:00Z', 'trees' => [], 'floors' => ['src' => -1]],
        ['commit' => 'an unknown verdict', 'time' => '2026-09-30T10:00:00Z', 'trees' => [], 'verdict' => 'fine'],
    ]]));

    expect(Decoded::column($trend->json(), 'commit', 'runs'))->toBe(['kept'])
        ->and(iterator_to_array($trend->scores(), preserve_keys: false))->toBe([80.0])
        ->and(iterator_to_array(Trend::decode('not json')->scores(), preserve_keys: false))->toBe([])
        ->and(iterator_to_array(Trend::decode('{"runs": "many"}')->scores(), preserve_keys: false))->toBe([]);
});

it('writes a run\'s runner time and the full one-job run beside its scores, and reads them back', function (): void {
    $trend = Trend::none()->with(Verdicts::named('accounted'), Revision::ref('abc123'), Moment::at('2026-09-30T12:00:00Z'));
    $run = Decoded::at($trend->json(), 'runs', 0);

    expect($run)->toMatchArray(['commit' => 'abc123', 'runnerSeconds' => 840.0, 'fullRunSeconds' => 6060.0])
        ->and(Trend::decode($trend->json())->json())->toBe($trend->json())
        ->and(Decoded::at(Trend::none()->with(Verdicts::failing(), Revision::ref('abc123'), Moment::at('2026-09-30T12:00:00Z'))->json(), 'runs', 0))
        ->not->toHaveKeys(['runnerSeconds', 'fullRunSeconds']);
});

it('sums what the runs since an instant saved, never less than nothing each', function (): void {
    $trend = Trend::decode('{"format": 1, "runs": [
        {"commit": "a", "time": "2026-08-01T00:00:00Z", "trees": {}, "runnerSeconds": 10, "fullRunSeconds": 1000},
        {"commit": "b", "time": "2026-08-31T12:00:00Z", "trees": {}, "runnerSeconds": 100, "fullRunSeconds": 700},
        {"commit": "c", "time": "2026-09-11T00:00:00Z", "trees": {}, "runnerSeconds": 900, "fullRunSeconds": 700},
        {"commit": "d", "time": "2026-09-12T00:00:00Z", "trees": {}, "runnerSeconds": 100},
        {"commit": "e", "time": "2026-09-13T00:00:00Z", "trees": {}, "fullRunSeconds": 100}
    ]}');

    expect($trend->savedSince(Moment::at('2026-08-31T12:00:00Z')))->toEqual(Seconds::of(600.0))
        ->and($trend->savedSince(Moment::at('2026-09-01T00:00:00Z')))->toEqual(Seconds::of(0.0))
        ->and($trend->savedSince(Moment::at('2026-10-01T00:00:00Z')))->toEqual(NoHistory::yet())
        ->and(Trend::none()->savedSince(Moment::at('2026-08-31T12:00:00Z')))->toEqual(NoHistory::yet());
});

it('hands on its newest entry: what that verdict judged, and each tree\'s floor and score', function (): void {
    $trend = Trend::none()
        ->with(Verdicts::passing(), Revision::ref('one'), Moment::at('2026-09-30T10:00:00Z'))
        ->with(Verdicts::failing(), Revision::ref('two'), Moment::at('2026-09-30T11:00:00Z'));
    $newest = Trend::decode($trend->json())->newest();

    expect($newest->verdict())->toBe(Judgement::Failed)
        ->and($newest->floorOf(Path::of('src')))->toEqual(Floor::of(80))
        ->and($newest->scoreOf(Path::of('src')))->toEqual(Score::ofHundredths(4_444))
        ->and($newest->floorOf(Path::of('app/Legacy')))->toEqual(Unrecorded::floor())
        ->and($newest->scoreOf(Path::of('src/Empty')))->toEqual(Unrecorded::floor());
});

it('hands on an entry that recorded nothing where it has none, or its newest came before judgements were kept', function (): void {
    $old = Trend::decode('{"format": 1, "runs": [{"commit": "a", "time": "2026-09-30T10:00:00Z", "trees": {"src": 80}}]}');

    expect(Trend::none()->newest())->toEqual(TrendEntry::none())
        ->and($old->newest()->verdict())->toEqual(Unrecorded::floor())
        ->and($old->newest()->floorOf(Path::of('src')))->toEqual(Unrecorded::floor())
        ->and($old->newest()->scoreOf(Path::of('src')))->toEqual(Score::ofHundredths(8_000));
});

it('writes and reads back a tree whose path reads as a number, beside one that does not', function (): void {
    $root = Package::at(Path::root());
    $killed = JudgedMutant::of(
        Verdicts::mutant('12/Money.php:9', 'TrueValue', Family::Literal, Verdicts::diff('return true;', 'return false;')),
        Judged::Killed,
    );
    $tree = static fn(string $path, int $floor): TreeVerdict => TreeVerdict::judged(
        Tree::at(Path::of($path), Floor::of($floor), $root),
        Unrecorded::floor(),
        JudgedUnits::none(),
        JudgedMutants::of($killed),
        Uncovered::Count,
    );
    $verdict = Verdict::of(TreeVerdicts::of($tree('12', 80), $tree('src', 70)));
    $trend = Trend::none()->with($verdict, Revision::ref('abc123'), Moment::at('2026-09-30T10:00:00Z'));
    $newest = Trend::decode($trend->json())->newest();

    expect(Decoded::at($trend->json(), 'runs', 0, 'floors'))->toBe(['12' => 80.0, 'src' => 70.0])
        ->and($newest->floorOf(Path::of('12')))->toEqual(Floor::of(80))
        ->and($newest->scoreOf(Path::of('12')))->toEqual(Score::ofHundredths(10_000))
        ->and($newest->floorOf(Path::of('src')))->toEqual(Floor::of(70));
});
