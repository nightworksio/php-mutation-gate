<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\PrefixReplay;
use NightWorksIO\MutationGate\Adapter\Pest\ReplayVerdict;
use NightWorksIO\MutationGate\Adapter\Pest\WholeOrders;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * A replay of a kill in this file, reaching this far under this key.
 *
 * @param positive-int $reach
 */
function wholeReplay(string $file, int $reach, string $key): PrefixReplay
{
    return PrefixReplay::of(Path::of($file), sprintf('/m/%s', $key), ['pest'], Paths::none(), $reach, $key, Seconds::of(2.0));
}

it('replays on its own each replay with no keyed whole order, and each that shares its whole order with none', function (): void {
    $replays = ['a' => wholeReplay('src/A.php', 1, 'aaaaaaaaaaaa'), 'b' => wholeReplay('src/A.php', 2, 'bbbbbbbbbbbb'), 'c' => wholeReplay('src/A.php', 1, 'cccccccccccc')];
    $asked = [];

    $said = WholeOrders::of($replays, ['a' => Prefix::at(4), 'b' => Prefix::at(4), 'c' => Prefix::keyedAt(4, 'dddddddddddd')])
        ->said(static function (array $ran) use (&$asked): array {
            $asked[] = $ran;

            return [ReplayVerdict::Stands, ReplayVerdict::Failed];
        });

    expect($asked)->toEqual([array_values($replays)])
        ->and($said)->toBe(['a' => ReplayVerdict::Stands, 'b' => ReplayVerdict::Failed, 'c' => ReplayVerdict::NoTime]);
});

it('replays each whole order two replays of one file share once, to its end, side by side, and on their own those whose whole did not stand', function (): void {
    $replays = [
        'a1' => wholeReplay('src/A.php', 1, 'a1a1a1a1a1a1'),
        'a2' => wholeReplay('src/A.php', 2, 'a2a2a2a2a2a2'),
        'b1' => wholeReplay('src/B.php', 1, 'b1b1b1b1b1b1'),
        'b2' => wholeReplay('src/B.php', 3, 'b2b2b2b2b2b2'),
    ];
    $whole = Prefix::keyedAt(6, 'kkkkkkkkkkkk');
    $asked = [];

    $said = WholeOrders::of($replays, ['a1' => $whole, 'a2' => $whole, 'b1' => $whole, 'b2' => $whole])
        ->said(static function (array $ran) use (&$asked): array {
            $asked[] = count($ran);

            return [ReplayVerdict::Stands, ReplayVerdict::Failed];
        });

    expect($asked)->toBe([2, 2])
        ->and($said)->toBe([
            'a1' => ReplayVerdict::Stands,
            'a2' => ReplayVerdict::Stands,
            'b1' => ReplayVerdict::Stands,
            'b2' => ReplayVerdict::Failed,
        ]);
});
