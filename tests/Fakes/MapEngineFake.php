<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function in_array;

use NightWorksIO\MutationGate\Core\Coverage\MapSlices;
use NightWorksIO\MutationGate\Core\Coverage\MapVouch;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Port\MapEngine;

/**
 * An engine that vouches for the texts it was told to and splits nothing, saying why, and remembers each
 * split it was asked for.
 */
final class MapEngineFake implements MapEngine
{
    private const string DECLINED = 'The fake engine splits nothing.';

    /** @var list<array{Path, MapSlices}> */
    public private(set) array $splits = [];

    /** @param list<string> $vouchedFor the texts it vouches for */
    public function __construct(private readonly array $vouchedFor = [])
    {
    }

    public function vouches(Contents $text): MapVouch
    {
        return in_array($text->text(), $this->vouchedFor, strict: true) ? MapVouch::Vouched : MapVouch::Unvouched;
    }

    public function split(Path $whole, MapSlices $slices): NotWritten
    {
        $this->splits[] = [$whole, $slices];

        return NotWritten::because(self::DECLINED);
    }
}
