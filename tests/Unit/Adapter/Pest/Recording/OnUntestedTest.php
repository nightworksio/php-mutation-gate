<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnUntested;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Tests\Support\Mutations;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Event\Events\Test\Outcome\Untested;
use Pest\Mutate\Support\MutationTestResult;

afterEach(function (): void {
    Scratch::sweep();
});

it('records a mutant every test passed with as the event arrives', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    $test = Mutations::test('/p/src/Money.php', 'id-1', MutationTestResult::Untested);

    new OnUntested(Mutations::recorder($results, '/c'))->notify(new Untested($test));

    expect(Mutations::recorded($results))->toBe([RecordLine::outcome('id-1', PestStatus::Untested)]);
});
