<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function array_key_exists;
use function file_get_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Printed;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\Source;

/**
 * The originals of the files a judging run's mutants change, each read,
 * printed and scanned once and kept while the run judges: the mutants of one
 * file are all judged against the same original.
 */
final class Originals
{
    /** @var array<string, Original|CannotJudge|NotGiven> each file's original, by its path */
    private array $read = [];

    public function __construct(private readonly Project $project)
    {
    }

    /** A file's original; why it cannot be printed; or none, where it cannot be read. */
    public function of(Path $file): Original|CannotJudge|NotGiven
    {
        if (! array_key_exists($file->value(), $this->read)) {
            $this->read[$file->value()] = $this->reading($file);
        }

        return $this->read[$file->value()];
    }

    private function reading(Path $file): Original|CannotJudge|NotGiven
    {
        $path = $this->project->absolute($file);
        $text = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($text)) {
            return NotGiven::value();
        }

        $printed = Printed::of(Contents::of($text), $file);

        return $printed instanceof Contents
            ? Original::of($printed, Source::read($file, Contents::of($text), test: false))
            : $printed;
    }
}
