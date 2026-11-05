<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Doctor;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\Fix\Edit;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;

/**
 * The files `doctor --fix` reads and writes (ADR-0083): a path the project
 * spells, from the project's directory, and one spelt from the file
 * system's root, such as the runner PHP's php.ini, from there. A file that
 * changed after it was read is left as it is.
 *
 * @internal
 */
final readonly class FixedFiles
{
    private const string CHANGED = '%s changed after doctor read it, so doctor --fix left it as it is.';

    public function __construct(private string $project)
    {
    }

    /** What a file holds now; nothing where it is not there or cannot be read. */
    public function read(string $file): string
    {
        $path = Path::of($file);
        $contents = $this->directoryOf($path)->read($path->fromBase());

        return $contents instanceof Contents ? $contents->text() : '';
    }

    /** How many of these edits were written; or why one could not be, the files before it written. */
    public function write(Edit ...$edits): int|CannotJudge
    {
        $written = 0;

        foreach ($edits as $edit) {
            $path = Path::of($edit->file());

            if ($this->read($edit->file()) !== $edit->before()) {
                return CannotJudge::because(sprintf(self::CHANGED, $edit->file()));
            }

            $wrote = $this->directoryOf($path)->write($path->fromBase(), Contents::of($edit->after()));

            if ($wrote instanceof CannotJudge) {
                return $wrote;
            }

            ++$written;
        }

        return $written;
    }

    private function directoryOf(Path $path): Directory
    {
        return Directory::at($path->isAbsolute() ? $path->base() : $this->project);
    }
}
