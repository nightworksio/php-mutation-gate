<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function array_key_exists;
use function array_values;
use function count;

/**
 * Files, each with the files it names: those that declare a name it uses.
 * The one walk from some files to every file they need, in turn, that the
 * proof key's support and a mutant's narrowed run both take.
 */
final readonly class NamedFiles
{
    /** @param array<string, list<string>> $naming the files each file names, by its path */
    private function __construct(private array $naming)
    {
    }

    /** @param array<string, list<string>> $naming the files each file names, by its path */
    public static function of(array $naming): self
    {
        return new self($naming);
    }

    /** @return list<string> these files, and every file they name, transitively, each once, in the order reached */
    public function reachedFrom(string ...$from): array
    {
        $reached = [];

        foreach ($from as $file) {
            $reached[$file] = $file;
        }

        $queue = array_values($reached);
        $at = 0;

        while ($at < count($queue)) {
            foreach (array_key_exists($queue[$at], $this->naming) ? $this->naming[$queue[$at]] : [] as $named) {
                if (! array_key_exists($named, $reached)) {
                    $reached[$named] = $named;
                    $queue[] = $named;
                }
            }

            $at++;
        }

        return array_values($reached);
    }
}
