<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Order\OnSeeding;
use NightWorksIO\MutationGate\Tests\Support\Orders;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Event\Events\TestSuite\StartMutationSuite;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes every mutant\'s order once they are all made', function (): void {
    [$seeder, $suite, $order] = Orders::project(mapped: true);

    new OnSeeding($seeder)->notify(new StartMutationSuite($suite));

    expect(Orders::seeded($order, '/tmp/mutations/m1'))->toContain('"defects"');
});
