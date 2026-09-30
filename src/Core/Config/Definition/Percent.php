<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_float;
use function is_int;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Score\Floor;

/**
 * A floor: a number from 0 to 100.
 *
 * @implements Shape<Floor>
 */
final readonly class Percent implements Shape
{
    private const int NONE = 0;

    private const int WHOLE = 100;

    public static function floor(): self
    {
        return new self();
    }

    public function read(Node $at): Reading
    {
        $number = Number::between(self::NONE, self::WHOLE)->read($at)->value();

        return is_int($number) || is_float($number)
            ? Reading::of(Floor::of($number))
            : Reading::refused($at->mismatch($this->expected()));
    }

    public function expected(): string
    {
        return 'a number from 0 to 100';
    }

    public function schema(): Json
    {
        return Number::between(self::NONE, self::WHOLE)->schema();
    }

    public function effects(): array
    {
        return [];
    }
}
