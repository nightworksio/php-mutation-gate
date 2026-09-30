<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Order\Seeder;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Tests\Support\Orders;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Event\Facade;

use function Pest\version;

afterEach(function (): void {
    Scratch::sweep();
});

it('orders nothing unless the adapter names an order directory and a results file, outside a mutant\'s process', function (): void {
    $root = Root::of('/p');

    $listening = static fn(string|false $directory, string|false $results, string|false $mutant): Seeder|Off
        => Seeder::listening($directory, $results, $mutant, new Facade(), $root);

    expect($listening(directory: false, results: '/r/results.jsonl', mutant: false))->toBe(Off::Ordering)
        ->and($listening('', '/r/results.jsonl', mutant: false))->toBe(Off::Ordering)
        ->and($listening('/o', results: false, mutant: false))->toBe(Off::Ordering)
        ->and($listening('/o', '', mutant: false))->toBe(Off::Ordering)
        ->and($listening('/o', '/r/results.jsonl', '/p/src/Money.php'))->toBe(Off::Ordering)
        ->and(Seeder::fromEnvironment())->toBe(Off::Ordering)
        ->and($listening('/o', '/r/results.jsonl', mutant: false))->toBeInstanceOf(Seeder::class);
});

it('writes each mutant\'s order: its function\'s likely killers first, and every covering test\'s time', function (): void {
    [$seeder, $suite, $order] = Orders::project(mapped: true);

    $seeder->seed($suite);

    expect(json_decode(Orders::seeded($order, '/tmp/mutations/m1'), associative: true))->toBe([
        'version' => sprintf('pest_%s', version()),
        'defects' => [Orders::TWICE => 8, Orders::ADDS => 7],
        'times' => [Orders::ADDS => 0.5],
    ])->and(json_decode(Orders::seeded($order, '/tmp/mutations/m2'), associative: true))->toBe([
        'version' => sprintf('pest_%s', version()),
        'defects' => [],
        'times' => ['P\Tests\MoneySpec::__pest_evaluable_it_rows with data set "(1)"' => 0.25],
    ]);
});

it('writes no order where it has no opening run\'s map, so each mutant runs in Pest\'s own order', function (): void {
    [$seeder, $suite, $order] = Orders::project(mapped: false);

    $seeder->seed($suite);

    expect(Orders::seeded($order, '/tmp/mutations/m1'))->toBe('');
});
