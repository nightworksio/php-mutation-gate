<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection\Import;

use function file_get_contents;
use function is_dir;
use function is_file;

use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\ClassLocations;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;

use function sprintf;

/**
 * The file of a class, or the directory of a namespace, as the project's
 * `composer.json` autoloads it by psr-4 or psr-0, where it is there. Nothing
 * is loaded to find it.
 */
final readonly class ClassFiles
{
    private function __construct(private Project $project, private ClassLocations $locations)
    {
    }

    /** The class files of the project's own `composer.json`; with none readable, no class has a file. */
    public static function in(Project $project): self
    {
        $file = $project->absolute(Manifest::fileIn(Path::root()));
        $manifest = is_file($file)
            ? Manifest::decode(Contents::of(sprintf('%s', file_get_contents($file))), Path::root())
            : CannotJudge::because('no composer.json');

        $locations = $manifest instanceof Manifest
            ? $manifest->classLocations()
            : ClassLocations::of(Path::root(), Node::decode('{}'));

        return new self($project, $locations);
    }

    /** The first file the autoload would load the class from that is there, or why there is none. */
    public function of(string $class): Path|Unmapped
    {
        foreach ($this->locations->files($class) as $file) {
            if (is_file($this->project->absolute($file))) {
                return $file;
            }
        }

        return Unmapped::NoFile;
    }

    /** Everything under the first directory the autoload would load a namespace's classes from that is there. */
    public function under(string $namespace): Glob|Unmapped
    {
        foreach ($this->locations->directories($namespace) as $directory) {
            if (is_dir($this->project->absolute($directory))) {
                return Glob::of(sprintf('%s/**', $directory->value()));
            }
        }

        return Unmapped::NoDirectory;
    }
}
