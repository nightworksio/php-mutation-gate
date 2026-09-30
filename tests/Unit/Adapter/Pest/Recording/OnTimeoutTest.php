<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnTimeout;
use NightWorksIO\MutationGate\Tests\Support\Mutations;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Event\Events\Test\Outcome\Timeout;
use Pest\Mutate\Support\MutationTestResult;

afterEach(function (): void {
    Scratch::sweep();
});

it('records a mutant Pest stopped at its time limit as the event arrives', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $test = Mutations::test('/p/src/Money.php', 'id-1', MutationTestResult::Timeout);

    new OnTimeout(Mutations::recorder($results, '/c'))->notify(new Timeout($test));

    expect(Mutations::recorded($results))->toBe([['event' => 'outcome', 'id' => 'id-1', 'status' => 'timeout']]);
});
