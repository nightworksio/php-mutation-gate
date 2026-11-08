<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\KeptMap;
use NightWorksIO\MutationGate\Core\Coverage\MapLimits;
use NightWorksIO\MutationGate\Core\Coverage\MapVouch;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\VouchedMaps;

// A text the engine vouches for is built straight from it, into the map the
// careful reading builds; one it does not is read carefully (ADR-0118).

/**
 * What answers each text it is asked of so, keeping each text it was asked of.
 *
 * @param  ArrayObject<int, string>  $asked
 * @return Closure(string): MapVouch
 */
$asking = static fn(MapVouch $answer, ArrayObject $asked): Closure => static function (string $json) use ($answer, $asked): MapVouch {
    $asked->append($json);

    return $answer;
};
$written = static fn(CoverageMap|KeptMap|CannotJudge $read): string => match (true) {
    $read instanceof CoverageMap => VouchedMaps::written($read),
    $read instanceof KeptMap => VouchedMaps::written($read->map()),
    default => $read->why(),
};

it('decodes a handed map into the same map whether the engine vouches for it or not', function () use ($asking, $written): void {
    foreach (VouchedMaps::texts() as $name => [$json]) {
        /** @var ArrayObject<int, string> $asked */
        $asked = new ArrayObject();
        $vouched = CoverageMapFile::decode(Gzip::pack($json), HandedMaps::limits(), $asking(MapVouch::Vouched, $asked));
        $unvouched = CoverageMapFile::decode(Gzip::pack($json), HandedMaps::limits(), $asking(MapVouch::Unvouched, $asked));

        expect($vouched)->toBeInstanceOf(CoverageMap::class, $name)
            ->and($written($vouched))->toBe(VouchedMaps::carefully($json), $name)
            ->and($unvouched)->toEqual(CoverageMapFile::decode(Gzip::pack($json), HandedMaps::limits()), $name)
            ->and([...$asked])->toBe([$json, $json], $name);
    }
});

it('reads a kept map into the same map, measured where it was, whether the engine vouches for it or not', function () use ($asking, $written): void {
    $json = VouchedMaps::texts()['timed and untimed tests, covered and missed lines and methods'][0];
    /** @var ArrayObject<int, string> $asked */
    $asked = new ArrayObject();
    $vouched = CoverageMapFile::kept(Gzip::pack($json), MapLimits::standard(), $asking(MapVouch::Vouched, $asked));
    $careful = CoverageMapFile::kept(Gzip::pack($json), MapLimits::standard());

    expect($vouched)->toBeInstanceOf(KeptMap::class)
        ->and($written($vouched))->toBe($written($careful))
        ->and($vouched instanceof KeptMap ? [$vouched->measuredAt(), $vouched->keys()] : [])
        ->toEqual($careful instanceof KeptMap ? [$careful->measuredAt(), $careful->keys()] : [])
        ->and([...$asked])->toBe([$json]);
});

it('asks no engine of bytes that are no map, and cannot judge by them', function () use ($asking): void {
    /** @var ArrayObject<int, string> $asked */
    $asked = new ArrayObject();

    expect(CoverageMapFile::decode('not gzip', HandedMaps::limits(), $asking(MapVouch::Vouched, $asked)))
        ->toBeInstanceOf(CannotJudge::class)
        ->and(CoverageMapFile::decode(Gzip::pack('{"format":2}'), HandedMaps::limits(), $asking(MapVouch::Unvouched, $asked)))
        ->toBeInstanceOf(CannotJudge::class)
        ->and(CoverageMapFile::kept(Gzip::pack('{"format":2}'), MapLimits::standard(), $asking(MapVouch::Vouched, $asked)))
        ->toBeInstanceOf(CannotJudge::class)
        ->and([...$asked])->toBe(['{"format":2}']);
});
