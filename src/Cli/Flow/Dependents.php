<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_map;

use NightWorksIO\MutationGate\Core\Analysis\DependentCap;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\DeclarationHeaders;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Php\PhpFile;

/**
 * The files a mutant can break directly, which its check analyses again
 * against it (ADR-0020, decision 7). A mutant that changes what its file
 * declares can break every file that mentions a name the file declares, so
 * those are its dependents, as many as the cap allows; one that changes
 * only bodies breaks none. The project's name graph is read once, the first
 * time a mutant needs it; where it cannot be read, a mutant has none, and
 * its check sees only its own file.
 */
final class Dependents
{
    /** The graph once read, or why it could not be; nothing before a mutant needs it. */
    private NamedFiles|CannotJudge|NotGiven $graph;

    public function __construct(private readonly NameGraph $names, private readonly DependentCap $cap)
    {
        $this->graph = NotGiven::value();
    }

    /** The dependents of a mutant of this file, read with the original's text beside the mutant's. */
    public function of(Path $file, Contents $original, Contents $mutant): Paths
    {
        if (DeclarationHeaders::in($original)->same(DeclarationHeaders::in($mutant))) {
            return Paths::none();
        }

        if ($this->graph instanceof NotGiven) {
            $this->graph = $this->names->read();
        }

        if ($this->graph instanceof CannotJudge) {
            return Paths::none();
        }

        $mentioning = $this->graph->mentioning(...NamedFiles::declaredIn(PhpFile::read($original)));
        $others = [];

        foreach (array_map(Path::of(...), $mentioning) as $dependent) {
            $others = $dependent->equals($file) ? $others : [...$others, $dependent];
        }

        return $this->cap->of(Paths::of(...$others));
    }
}
