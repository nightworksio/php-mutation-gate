<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnStartMutationSuite;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Tests\Support\Mutations;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Event\Events\TestSuite\StartMutationSuite;
use Pest\Mutate\Support\MutationTestResult;

afterEach(function (): void {
    Scratch::sweep();
});

it('records every mutant once Pest has made them all', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'Money.php', '<?php');
    $results = sprintf('%s/results.jsonl', $root);
    $suite = Mutations::suite(sprintf('%s/Money.php', $root), MutationTestResult::None);

    new OnStartMutationSuite(Mutations::recorder($results, '/c'))->notify(new StartMutationSuite($suite));

    expect(array_map(
        static fn(string $line): string => Node::decode($line)->field(RecordLine::EVENT)->text(),
        Mutations::recorded($results),
    ))->toBe([RecordEvent::Planned->value, RecordEvent::Made->value]);
});
