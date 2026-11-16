<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\OutbreakCalls;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('watches the state a mutant\'s call leaves, what a child\'s test touches, and records each state\'s digest', function (): void {
    $results = sprintf('%s/results.jsonl', Scratch::directory());

    expect(OutbreakCalls::asked(<<<'CODE'
        $states = new NightWorksIO\MutationGate\Adapter\Pest\Split\ForkStates($plan);
        $watch = $states->armed();
        $states->forked('m1', $watch);
        $states->forkedInto($watch);
        echo $states->touched() ? 'touched:' : 'untouched:';
        $states->childEnded('m1', 'T::a');
        $states->parentEnded('T::a');
        CODE, $results))
        ->toBe('touched:watch;touches;touched;digest 7;digest 7')
        ->and(substr_count((string) file_get_contents($results), '"d1"'))->toBe(2);
})->skip(fn(): bool => ! function_exists('pcntl_fork'), 'a split stream needs a PHP that forks');
