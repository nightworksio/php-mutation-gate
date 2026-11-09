<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_int;

use NightWorksIO\MutationGate\Core\Comment\Detail;
use NightWorksIO\MutationGate\Core\Comment\Lines;
use NightWorksIO\MutationGate\Core\Config\Absent;

/**
 * What one `comment.<cap>` object sets (ADR-0102): its `most`, and for the survivors their `lines` and `detail`.
 *
 * @internal
 */
final readonly class CapRead
{
    private function __construct(public int|Absent $most, public Lines|Absent $lines, public Detail|Absent $detail)
    {
    }

    public static function of(
        object|string|int|float|bool $most,
        object|string|int|float|bool $lines,
        object|string|int|float|bool $detail,
    ): self {
        return new self(
            is_int($most) ? $most : Absent::setting(),
            $lines instanceof Lines ? $lines : Absent::setting(),
            $detail instanceof Detail ? $detail : Absent::setting(),
        );
    }
}
