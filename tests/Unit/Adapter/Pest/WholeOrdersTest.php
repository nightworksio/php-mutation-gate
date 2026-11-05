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
