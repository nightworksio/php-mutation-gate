<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\PrefixReplay;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/** A replay of one run, its kill's mutant allowed these seconds. */
function replayAllowed(Seconds|Unmeasured $limit): PrefixReplay
{
    return PrefixReplay::of(Path::of('src/Money.php'), '/m/abc', ['pest', '--bail'], Paths::none(), 1, 'abcdefabcdef', $limit);
}

it('takes the longer limit of two kills it vouches for, and the time left once either limit is unknown', function (Seconds|Unmeasured $first, Seconds|Unmeasured $second, Seconds|Unlimited $within): void {
    expect(replayAllowed($first)->alsoFor($second)->within(Unlimited::time()))->toEqual($within);
})->with([
    'both known' => [Seconds::of(2.0), Seconds::of(5.0), Seconds::of(5.0)],
    'the longer first' => [Seconds::of(5.0), Seconds::of(2.0), Seconds::of(5.0)],
    'the first unknown' => [Unmeasured::duration(), Seconds::of(2.0), Unlimited::time()],
    'the second unknown' => [Seconds::of(2.0), Unmeasured::duration(), Unlimited::time()],
]);

it('spells its limit for a key, and nothing where it is not known', function (): void {
    expect([replayAllowed(Seconds::of(2.5))->limitText(), replayAllowed(Unmeasured::duration())->limitText()])->toBe(['2.5', '']);
});
