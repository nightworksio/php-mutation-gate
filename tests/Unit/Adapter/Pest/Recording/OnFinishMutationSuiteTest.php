<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnFinishMutationSuite;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Tests\Support\Mutations;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Event\Events\TestSuite\FinishMutationSuite;
use Pest\Mutate\Support\MutationTestResult;

afterEach(function (): void {
    Scratch::sweep();
});

it('records every mutant\'s final status once Pest has run them all', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'Money.php', '<?php');
    $results = sprintf('%s/results.jsonl', $root);
    $suite = Mutations::suite(sprintf('%s/Money.php', $root), MutationTestResult::Untested);

    new OnFinishMutationSuite(Mutations::recorder($results, '/c'))->notify(new FinishMutationSuite($suite));

    expect(Mutations::recorded($results))->toBe([
        RecordLine::finished('id-1', PestStatus::Untested, 0.0),
        RecordLine::end(),
    ]);
});
