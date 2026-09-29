<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnUncovered;
use NightWorksIO\MutationGate\Tests\Support\Mutations;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Event\Events\Test\Outcome\Uncovered;
use Pest\Mutate\Support\MutationTestResult;

afterEach(function (): void {
    Scratch::sweep();
});

it('records a mutant no test ran as the event arrives', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $test = Mutations::test('/p/src/Money.php', 'id-1', MutationTestResult::Uncovered);

    new OnUncovered(Mutations::recorder($results, '/c'))->notify(new Uncovered($test));

    expect(Mutations::recorded($results))->toBe([['event' => 'outcome', 'id' => 'id-1', 'status' => 'uncovered']]);
});
