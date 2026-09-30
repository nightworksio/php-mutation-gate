<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_string;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/** A duration, written `90s`, `15m` or `1h30m`. */
final readonly class Duration implements Node
{
    public static function written(): self
    {
        return new self();
    }

    public function read(mixed $value, string $at): Reading
    {
        $duration = is_string($value) ? Seconds::parse($value) : $value;

        return $duration instanceof Seconds
            ? Reading::of($duration, $value)
            : Reading::mismatch($at, $this->expected(), $value);
    }

    public function expected(): string
    {
        return 'a duration such as 90s, 15m or 1h30m';
    }

    public function schema(): array
    {
        return ['type' => 'string', 'pattern' => '^(?=.)(?:[0-9]+h)?(?:[0-9]+m)?(?:[0-9]+s)?$'];
    }

    public function effects(): array
    {
        return [];
    }
}
