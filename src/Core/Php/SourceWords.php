<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_values;
use function ksort;

/**
 * The files of a codebase by the words their names end in (see
 * NameWords), so the reads of a value are looked for only in the
 * files that spell a word a read of it must (see Reads::wordsOf).
 *
 * @internal
 */
final readonly class SourceWords
{
    /**
     * @param list<Source>              $sources the codebase's files, in its order
     * @param array<string, list<int>> $places  the places in that list of the files that spell each word, ascending
     */
    private function __construct(private array $sources, private array $places)
    {
    }

    /** @param list<Source> $sources */
    public static function of(array $sources): self
    {
        $places = [];

        foreach ($sources as $place => $source) {
            foreach ($source->tokens()->words()->all() as $word) {
                $places[$word][] = $place;
            }
        }

        return new self($sources, $places);
    }

    /**
     * The files that spell any of these words, each once, in the codebase's order.
     *
     * @return list<Source>
     */
    public function spelling(string ...$words): array
    {
        $places = [];

        foreach ($words as $word) {
            $places += array_key_exists($word, $this->places) ? array_flip($this->places[$word]) : [];
        }

        ksort($places);

        return array_values(array_intersect_key($this->sources, $places));
    }
}
