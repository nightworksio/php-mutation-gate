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

it('replays the longest replay of each whole order two replays of one file share once, side by side, and on their own those whose longest did not stand', function (): void {
    $replays = [
        'a1' => wholeReplay('src/A.php', 1, 'a1a1a1a1a1a1'),
        'a3' => wholeReplay('src/A.php', 3, 'a3a3a3a3a3a3'),
        'a2' => wholeReplay('src/A.php', 2, 'a2a2a2a2a2a2'),
        'b1' => wholeReplay('src/B.php', 1, 'b1b1b1b1b1b1'),
        'b2' => wholeReplay('src/B.php', 2, 'b2b2b2b2b2b2'),
    ];
    $whole = Prefix::keyedAt(6, 'kkkkkkkkkkkk');
    $asked = [];

    $said = WholeOrders::of($replays, ['a1' => $whole, 'a3' => $whole, 'a2' => $whole, 'b1' => $whole, 'b2' => $whole])
        ->said(static function (array $ran) use (&$asked): array {
            $asked[] = array_map(static fn(PrefixReplay $replay): array => [$replay->reach(), $replay->key()], $ran);

            return [ReplayVerdict::Stands, ReplayVerdict::Failed];
        });

    expect($asked)->toBe([[[3, 'a3a3a3a3a3a3'], [2, 'b2b2b2b2b2b2']], [[1, 'b1b1b1b1b1b1'], [2, 'b2b2b2b2b2b2']]])
        ->and($said)->toBe([
            'a1' => ReplayVerdict::Stands,
            'a3' => ReplayVerdict::Stands,
            'a2' => ReplayVerdict::Stands,
            'b1' => ReplayVerdict::Stands,
            'b2' => ReplayVerdict::Failed,
        ]);
});

it('takes the first of the replays that reach furthest, allowed the longest limit of the group', function (): void {
    $short = PrefixReplay::of(Path::of('src/A.php'), '/m/short', ['pest'], Paths::none(), 1, 'aaaaaaaaaaaa', Seconds::of(9.0));
    $first = PrefixReplay::of(Path::of('src/A.php'), '/m/first', ['pest'], Paths::none(), 2, 'bbbbbbbbbbbb', Seconds::of(2.0));
    $second = PrefixReplay::of(Path::of('src/A.php'), '/m/second', ['pest'], Paths::none(), 2, 'cccccccccccc', Seconds::of(3.0));
    $whole = Prefix::keyedAt(4, 'kkkkkkkkkkkk');
    $asked = [];

    WholeOrders::of(['s' => $short, 'f' => $first, 'n' => $second], ['s' => $whole, 'f' => $whole, 'n' => $whole])
        ->said(static function (array $ran) use (&$asked): array {
            $asked[] = array_map(static fn(PrefixReplay $replay): array => [$replay->mutated(), $replay->reach(), $replay->limitText()], $ran);

            return array_fill(0, count($ran), ReplayVerdict::Stands);
        });

    expect($asked)->toBe([[['/m/first', 2, '9']]]);
});
