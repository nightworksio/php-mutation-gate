<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_key_exists;
use function array_keys;
use function array_map;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Functions;
use NightWorksIO\MutationGate\Core\Php\Nameless;

/**
 * The named functions of the source files a kill history speaks of, as they
 * are on disk, each file read once: the function each mutant is in, and which
 * of the files still exist.
 */
final readonly class SourceFunctions
{
    /** @param array<string, Functions> $functions each file that exists, by its path */
    private function __construct(private array $functions)
    {
    }

    /** These files' functions; a file that is gone has none, and one that cannot be read stops the verdict. */
    public static function read(Directory $project, Paths $files): self|CannotJudge
    {
        $functions = [];

        foreach ($files as $file) {
            $contents = $project->read($file);

            if ($contents instanceof CannotJudge) {
                return $contents;
            }

            if ($contents instanceof Contents) {
                $functions[$file->value()] = Functions::in($contents);
            }
        }

        return new self($functions);
    }

    /** The named function a mutant is in; nameless code where its file is gone or it is in none. */
    public function around(Mutant $mutant): Enclosing|Nameless
    {
        $file = $mutant->location()->file();

        return array_key_exists($file->value(), $this->functions)
            ? Enclosing::of($file, $this->functions[$file->value()]->around($mutant->location()->start()))
            : Nameless::code();
    }

    /** The files that exist. */
    public function files(): Paths
    {
        return Paths::of(...array_map(Path::of(...), array_keys($this->functions)));
    }
}
