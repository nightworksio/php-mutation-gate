<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

use function array_filter;
use function array_key_last;
use function array_values;
use function mb_strlen;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function usort;

/**
 * What a line of code costs to mutate, by path prefix: `costs.secondsPerLine`.
 * The longest prefix a path is under wins, and of two alike the later. A path
 * under none costs nothing, which the default's empty prefix never lets happen.
 */
final readonly class SecondsPerLine
{
    /** The default: every path, at a fifth of a second a line. */
    private const float STANDARD = 0.2;

    /** @param list<LineRate> $rates */
    private function __construct(private array $rates)
    {
    }

    public static function of(LineRate ...$rates): self
    {
        return new self(array_values($rates));
    }

    /** `{"": 0.2}` */
    public static function standard(): self
    {
        return new self([LineRate::of('', Seconds::of(self::STANDARD))]);
    }

    public function forPath(Path $path): Seconds
    {
        $covering = array_values(array_filter($this->rates, static fn(LineRate $rate): bool => $rate->covers($path)));

        if ($covering === []) {
            return Seconds::of(0.0);
        }

        usort(
            $covering,
            static fn(LineRate $one, LineRate $other): int => mb_strlen($one->prefix()) <=> mb_strlen($other->prefix()),
        );

        return $covering[array_key_last($covering)]->perLine();
    }
}
