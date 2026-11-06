<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Fix;

use function array_unique;
use function array_values;

use ArrayIterator;
use Closure;
use IteratorAggregate;
use Traversable;

/**
 * What `doctor --fix` does about each finding it can do something about
 * (ADR-0083), in the order doctor reports them; and the edit of each file
 * the repairs change, every change of one file applied in that order.
 *
 * @internal
 *
 * @implements IteratorAggregate<int, Repair>
 */
final readonly class Repairs implements IteratorAggregate
{
    /** @param list<Repair> $repairs */
    private function __construct(private array $repairs)
    {
    }

    public static function of(Repair ...$repairs): self
    {
        return new self(array_values($repairs));
    }

    /**
     * Each file the repairs change, once, from the text it holds now, leaving out a file they leave as it is.
     *
     * @param  Closure(string): string $read the text a file holds now, empty where there is none
     * @return list<Edit>
     */
    public function edits(Closure $read): array
    {
        $before = [];

        foreach ($this->files() as $file) {
            $before[$file] = $read($file);
        }

        $edits = [];

        foreach ($this->applied($before) as $file => $after) {
            $edit = Edit::of($file, $before[$file], $after);
            $edits = $edit->isNone() ? $edits : [...$edits, $edit];
        }

        return $edits;
    }

    /** @return Traversable<int, Repair> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->repairs);
    }

    /** @return list<string> each file a change changes, once, in the order they first change it */
    private function files(): array
    {
        $files = [];

        foreach ($this->repairs as $repair) {
            foreach ($repair->changes() as $change) {
                $files[] = $change->file();
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * Each file's text once every change of it is applied, in turn, to the text it held.
     *
     * @param  array<string, string> $before each file's text before, by the file
     * @return array<string, string>
     */
    private function applied(array $before): array
    {
        $texts = $before;

        foreach ($this->repairs as $repair) {
            foreach ($repair->changes() as $change) {
                $texts[$change->file()] = $change->applied($texts[$change->file()]);
            }
        }

        return $texts;
    }
}
