<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Infection\Importable;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Adapter\Project\PhpUnitTrees;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Import\Import;
use NightWorksIO\MutationGate\Core\Import\SourceDifference;

use function sprintf;

/**
 * An Infection config taken over (ADR-0016, decision 1): the gate's config
 * it seeds over what zero-config found, what became of each of its keys,
 * and where the directories it names differ from `phpunit.xml`'s `<source>`.
 */
final readonly class Imported
{
    private const string DIRECTORIES = '%s\'s source.directories';

    private const string MISSING = '%s is not here to import from.';

    /**
     * The import of a file of the project's, over the layer zero-config
     * found, or why there is none. The trees zero-config found take an
     * imported floor or exclude where the file names no directories.
     *
     * @param Listed<DeclaredTree> $trees
     */
    public static function from(
        string $project,
        Path $file,
        Layer $found,
        Listed $trees,
        DateTimeImmutable $now,
    ): Import|CannotJudge {
        $text = Directory::at($project)->read($file);
        $importable = $text instanceof Contents ? Importable::read($file->value(), $text->text()) : $text;

        if (! $importable instanceof Importable) {
            return $importable instanceof CannotJudge
                ? $importable
                : CannotJudge::because(sprintf(self::MISSING, $file->value()));
        }

        $import = Import::of($found)->and($importable->imported(
            Project::at(Root::of($project), Paths::none(), Workspace::root()),
            $trees,
            $now,
        ));
        $included = PhpUnitTrees::in($project, Paths::none())->included();

        return SourceDifference::noted(
            $import,
            sprintf(self::DIRECTORIES, $file->value()),
            $importable->directories(),
            $included instanceof Paths ? $included : Paths::none(),
        );
    }
}
