<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPasses;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads each pass that ended as a step within the mutation step, the second from the first\'s end, each with its bound', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $mutation = StepTime::of(Step::Mutation, Seconds::of(20.0), Seconds::of(300.0), 9);

    StreamPasses::ended($results, Step::FirstPass, 40.0, 9, 120.0);
    StreamPasses::ended($results, Step::SecondPass, 84.0, 2, 120.0);
    file_put_contents(sprintf('%s.passes', $results), "not a pass\n{\"step\": \"mutation\", \"since\": 0, \"seconds\": 1}\n", FILE_APPEND);

    expect(StreamPasses::within($results, $mutation))->toEqual(StepTimes::of(
        StepTime::of(Step::FirstPass, Seconds::of(20.0), Seconds::of(40.0), 9)->bounded(Seconds::of(120.0)),
        StepTime::of(Step::SecondPass, Seconds::of(60.0), Seconds::of(84.0), 2)->bounded(Seconds::of(120.0)),
    ));
});

it('lists no pass of a run that names no results, and none once cleared for the next run', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $mutation = StepTime::of(Step::Mutation, Seconds::of(0.0), Seconds::of(1.0));

    StreamPasses::ended(results: false, pass: Step::FirstPass, seconds: 4.0, mutants: 1, bound: 1.0);
    StreamPasses::ended('', Step::FirstPass, 4.0, 1, 1.0);
    $none = StreamPasses::within($results, $mutation);
    StreamPasses::ended($results, Step::FirstPass, 4.0, 1, 1.0);
    StreamPasses::cleared($results);
    StreamPasses::cleared($results);

    expect($none)->toEqual(StepTimes::none())
        ->and(StreamPasses::within($results, $mutation))->toEqual(StepTimes::none());
});

it('reads a pass that started and never ended, as the stop cut it short, as running to the mutation step\'s end', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $mutation = StepTime::of(Step::Mutation, Seconds::of(20.0), Seconds::of(446.0), 250);

    StreamPasses::started($results, Step::FirstPass, 250, 900.0);
    StreamPasses::ended($results, Step::FirstPass, 22.0, 250, 900.0);
    StreamPasses::started($results, Step::SecondPass, 240, 900.0);

    expect(StreamPasses::within($results, $mutation))->toEqual(StepTimes::of(
        StepTime::of(Step::FirstPass, Seconds::of(20.0), Seconds::of(22.0), 250)->bounded(Seconds::of(900.0)),
        StepTime::of(Step::SecondPass, Seconds::of(42.0), Seconds::of(424.0), 240)->bounded(Seconds::of(900.0)),
    ));
});

it('reads a run\'s own runs, listed as they start, as running from its second pass\'s end to the mutation step\'s end, and lists nothing else', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $mutation = StepTime::of(Step::Mutation, Seconds::of(20.0), Seconds::of(446.0), 250);

    StreamPasses::ended($results, Step::FirstPass, 22.0, 250, 900.0);
    StreamPasses::ended($results, Step::SecondPass, 30.0, 240, 900.0);
    StreamPasses::ownRunsStarted($results, 57, 900.0);
    StreamPasses::started($results, Step::Reading, 3, 900.0);

    expect(StreamPasses::within($results, $mutation))->toEqual(StepTimes::of(
        StepTime::of(Step::FirstPass, Seconds::of(20.0), Seconds::of(22.0), 250)->bounded(Seconds::of(900.0)),
        StepTime::of(Step::SecondPass, Seconds::of(42.0), Seconds::of(30.0), 240)->bounded(Seconds::of(900.0)),
        StepTime::of(Step::OwnRuns, Seconds::of(72.0), Seconds::of(394.0), 57)->bounded(Seconds::of(900.0)),
    ));
});

it('lists no own runs where a run\'s streams left none', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $mutation = StepTime::of(Step::Mutation, Seconds::of(0.0), Seconds::of(100.0));

    StreamPasses::ended($results, Step::SecondPass, 30.0, 4, 9.0);
    StreamPasses::ownRunsStarted($results, 0, 9.0);

    expect(StreamPasses::within($results, $mutation))->toEqual(StepTimes::of(
        StepTime::of(Step::SecondPass, Seconds::of(0.0), Seconds::of(30.0), 4)->bounded(Seconds::of(9.0)),
    ));
});
