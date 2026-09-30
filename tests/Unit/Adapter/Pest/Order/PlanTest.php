<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Order\Plan;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('hands the plugin the kill history it reads back', function (): void {
    $directory = Scratch::directory();
    $history = KillHistory::none()
        ->withMutant(MutantId::hash(Path::of('src/Money.php'), 'Plus', "-a\n+b", 0), Ranking::of(Kills::of(TestId::of('a'), 2)))
        ->withFunction(Enclosing::named(Path::of('src/Money.php'), 'add'), Ranking::of(Kills::of(TestId::of('b'), 1)));

    Plan::write($directory, $history);

    expect(Plan::read($directory))->toEqual($history)
        ->and(Plan::in('/o'))->toBe('/o/plan.json');
});

it('reads a plan that is not there, or not whole, as no history', function (): void {
    $directory = Scratch::directory();
    $none = Plan::read($directory);
    Scratch::write($directory, 'plan.json', '{"tests": 7, "killers": {}}');

    expect($none)->toEqual(KillHistory::none())
        ->and(Plan::read($directory))->toEqual(KillHistory::none());
});
