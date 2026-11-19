<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamSlowdown;
use NightWorksIO\MutationGate\Core\Config\SlowdownCarry;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('starts the next pass at the highest slowdown a pass\'s streams measured, faster or slower, and the shard\'s next run there too', function (): void {
    $results = sprintf('%s/results.json', Scratch::directory());
    $slowdown = StreamSlowdown::carried(SlowdownCarry::Shard, $results);
    $first = $slowdown->start();
    $slowdown->seen(2.5);
    $slowdown->seen(1.5);
    $slowdown->passed();
    $second = $slowdown->start();
    $slowdown->passed();
    $unseen = $slowdown->start();
    $slowdown->seen(1.5);
    $slowdown->passed();

    expect([$first, $second, $unseen, $slowdown->start(), StreamSlowdown::carried(SlowdownCarry::Shard, $results)->start()])
        ->toBe([1.0, 2.5, 2.5, 1.5, 1.5]);
});

it('carries the slowdown through one run alone, or nowhere, as splitStream.slowdownCarry says', function (): void {
    $results = sprintf('%s/results.json', Scratch::directory());
    $run = StreamSlowdown::carried(SlowdownCarry::Run, $results);
    $none = StreamSlowdown::carried(SlowdownCarry::None, $results);

    foreach ([$run, $none] as $carried) {
        $carried->seen(3.0);
        $carried->passed();
    }

    expect([$run->start(), $none->start(), StreamSlowdown::carried(SlowdownCarry::Shard, $results)->start()])
        ->toBe([3.0, 1.0, 1.0])
        ->and(new StreamSlowdown()->start())->toBe(1.0);
});

it('starts as fast as the map where what the shard kept is no number, and never faster', function (): void {
    $results = sprintf('%s/results.json', Scratch::directory());
    file_put_contents(sprintf('%s.slowdown', $results), 'slow');
    $unread = StreamSlowdown::carried(SlowdownCarry::Shard, $results)->start();
    file_put_contents(sprintf('%s.slowdown', $results), '0.4');

    expect([$unread, StreamSlowdown::carried(SlowdownCarry::Shard, $results)->start()])->toBe([1.0, 1.0]);
});
