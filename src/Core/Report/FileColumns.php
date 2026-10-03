<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\Survivors;

/** Where on its lines each mutant is, for every file some mutants are in, each file's tokens read once. */
final readonly class FileColumns
{
    /** @param array<string, Columns> $columns by the file's path */
    private function __construct(private array $columns)
    {
    }

    public static function of(Survivors|JudgedMutants $mutants, Sources $sources): self
    {
        $columns = [];

        foreach ($mutants as $judged) {
            $file = $judged->mutant()->location()->file();
            $columns[$file->value()] = array_key_exists($file->value(), $columns)
                ? $columns[$file->value()]
                : Columns::in($sources->of($file));
        }

        return new self($columns);
    }

    /** The columns of a file; those of an empty file where none of the mutants is in it. */
    public function in(Path $file): Columns
    {
        return array_key_exists($file->value(), $this->columns)
            ? $this->columns[$file->value()]
            : Columns::in(Contents::of(''));
    }
}
