<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_reverse;
use function array_values;
use function count;
use function implode;
use function ksort;
use function mb_str_split;
use function str_starts_with;
use function strcmp;
use function usort;

/**
 * Class names spelt backwards and in byte order, each with its place in the
 * list it came from, so the names that end in a name are found by searching
 * for the names that begin with it backwards, not by reading every name.
 */
final readonly class ClassNameEndings
{
    /** @param list<array{string, int}> $backwards each name spelt backwards, with its place, in byte order */
    private function __construct(private array $backwards)
    {
    }

    /** @param list<string> $names */
    public static function of(array $names): self
    {
        $backwards = [];

        foreach ($names as $place => $name) {
            $backwards[] = [self::backwards($name), $place];
        }

        usort($backwards, static fn(array $one, array $other): int => strcmp($one[0], $other[0]));

        return new self($backwards);
    }

    /**
     * The places of the names that end in any of these, each once, in the
     * order of the list they came from.
     *
     * @param  list<string> $endings
     * @return list<int>
     */
    public function placesEndingIn(array $endings): array
    {
        $places = [];

        foreach ($endings as $ending) {
            $start = self::backwards($ending);

            for ($at = $this->firstFrom($start); $this->begins($at, $start); $at++) {
                $places[$this->backwards[$at][1]] = $this->backwards[$at][1];
            }
        }

        ksort($places);

        return array_values($places);
    }

    /** A name spelt backwards, character by character. */
    private static function backwards(string $name): string
    {
        return implode('', array_reverse(mb_str_split($name)));
    }

    /** Whether the name spelt backwards at an index begins with a start, where there is one. */
    private function begins(int $at, string $start): bool
    {
        return $at < count($this->backwards) && str_starts_with($this->backwards[$at][0], $start);
    }

    /** The index of the first name spelt backwards that sorts at or after a start. */
    private function firstFrom(string $start): int
    {
        $low = 0;
        $high = count($this->backwards);

        while ($low < $high) {
            $middle = ($low + $high) >> 1;
            [$low, $high] = strcmp($this->backwards[$middle][0], $start) < 0 ? [$middle + 1, $high] : [$low, $middle];
        }

        return $low;
    }
}
