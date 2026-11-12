<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\ThisPackage;

use function sprintf;

/**
 * Whether another build of the gate judges this run (ADR-0140): the gate
 * runs from an archive while the project is the gate's own package, and so
 * the package's files run no mutant. A `reach.everything` glob that matches
 * only those files is left out of reach, and the plan names it.
 *
 * @internal
 */
final readonly class JudgedRun
{
    private const string LEFT_OUT = <<<'SAID'
        reach.everything `%s` reaches nothing by itself:
        its files are the gate's own, which the judge runs in their place (ADR-0140).
        SAID;

    /** @param Paths $package the directories and files of the gate's own package, none where nothing judges the run */
    private function __construct(private Paths $package)
    {
    }

    /** Whether this project, run from this archive, is judged by another build of the gate. */
    public static function of(Directory $project, OwnArchive|NotGiven $archive): self
    {
        $manifest = $archive instanceof OwnArchive ? self::manifest($project) : NotGiven::value();

        return new self(
            $manifest instanceof Manifest && $manifest->name() === ThisPackage::COMPOSER
                ? $manifest->autoloaded()->and(Paths::of(Path::of('bin')))
                : Paths::none(),
        );
    }

    /** Whether reach leaves out this glob: every file it can match is one of the gate's own package. */
    public function leavesOut(Glob $glob): bool
    {
        $base = $glob->base();

        foreach ($this->package as $own) {
            if ($base->within($own)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The plan's line for each of these globs reach leaves out.
     *
     * @param  iterable<Glob> $globs
     * @return list<string>
     */
    public function lines(iterable $globs): array
    {
        $lines = [];

        foreach ($globs as $glob) {
            if ($this->leavesOut($glob)) {
                $lines[] = sprintf(self::LEFT_OUT, $glob->value());
            }
        }

        return $lines;
    }

    private static function manifest(Directory $project): Manifest|NotGiven
    {
        $root = Path::root();
        $contents = $project->read(Manifest::fileIn($root));
        $manifest = $contents instanceof Contents ? Manifest::decode($contents, $root) : NotGiven::value();

        return $manifest instanceof Manifest ? $manifest : NotGiven::value();
    }
}
