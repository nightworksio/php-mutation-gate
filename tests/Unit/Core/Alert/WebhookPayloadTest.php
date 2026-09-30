<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Alert\Alert;
use NightWorksIO\MutationGate\Core\Alert\AlertEvent;
use NightWorksIO\MutationGate\Core\Alert\WebhookPayload;
use NightWorksIO\MutationGate\Core\Baseline\Lowered;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Report\ReportSchema;
use NightWorksIO\MutationGate\Core\Report\TrendEntry;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Previous;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('posts the event, where it happened, the verdict and each tree, leaving out what a tree has no value for', function (): void {
    $entry = TrendEntry::none()->withScore(Path::of('src'), Score::ofHundredths(8_100));
    $payload = WebhookPayload::json(Alert::of(AlertEvent::Failed, Verdicts::failing(), $entry), Previous::ci());

    expect(Decoded::at($payload))->toBe([
        'format' => 1,
        'event' => 'failed',
        'repository' => 'octo/gate',
        'ref' => 'refs/heads/main',
        'commit' => '5eeca8f0d2b1c4a7e9f3a6b8c0d2e4f6a8b0c2d4',
        'run' => 'https://github.example/octo/gate/actions/runs/7',
        'verdict' => 'failed',
        'trees' => [
            ['path' => 'src', 'floor' => 80.0, 'score' => 37.5, 'previous' => 81.0],
            ['path' => 'app/Legacy'],
            ['path' => 'src/Empty', 'floor' => 90.0],
        ],
        'cannotJudge' => [],
    ]);
});

it('says where a floor went down from, and why, and why a run cannot judge', function (): void {
    $tree = static fn(string $path): TreeVerdict => TreeVerdict::judged(
        Tree::at(Path::of($path), Floor::of(70), Package::at(Path::root())),
        Unrecorded::floor(),
        JudgedUnits::none(),
        JudgedMutants::none(),
        Uncovered::Count,
    );
    $verdict = Verdict::of(TreeVerdicts::of($tree('app')->withLowering(Lowered::from(Floor::of(90), 'Legacy code')), $tree('lib')))
        ->withCannotJudge(CannotJudge::because('Shard 2 wrote no result.'));
    $entry = TrendEntry::none()->withFloor(Path::of('app'), Floor::of(90))->withFloor(Path::of('lib'), Floor::of(75));
    $payload = WebhookPayload::json(Alert::of(AlertEvent::FloorLowered, $verdict, $entry), Previous::ci());

    expect(Decoded::at($payload, 'trees'))->toBe([
        ['path' => 'app', 'floor' => 70.0, 'lowered' => ['from' => 90.0, 'reason' => 'Legacy code']],
        ['path' => 'lib', 'floor' => 70.0, 'lowered' => ['from' => 75.0]],
    ])
        ->and(Decoded::at($payload, 'verdict'))->toBe('cannot-judge')
        ->and(Decoded::at($payload, 'cannotJudge'))->toBe(['Shard 2 wrote no result.'])
        ->and(Schema::errors($payload, Schema::at('resources/webhook.schema.json')))->toBe([]);
});

it('posts what the committed schema describes', function (AlertEvent $event): void {
    $schema = Schema::at('resources/webhook.schema.json');
    $payload = WebhookPayload::json(Alert::of($event, Verdicts::failing(), TrendEntry::none()), Previous::ci());

    expect((string) file_get_contents($schema))->toBe(sprintf("%s\n", ReportSchema::webhook()), 'resources/webhook.schema.json is out of date. Run composer report:schema and commit it.')
        ->and(Schema::errors($payload, $schema))->toBe([]);
})->with(AlertEvent::cases());
