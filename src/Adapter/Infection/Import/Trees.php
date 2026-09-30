<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection\Import;

use function array_any;
use function array_map;
use function implode;
use function is_dir;
use function is_file;

use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Config\Floors;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Import\Carried;
use NightWorksIO\MutationGate\Core\Import\Import;
use NightWorksIO\MutationGate\Core\Score\Floor as TreeFloor;

use function preg_match;
use function sprintf;

/**
 * Infection's `source` as the gate's `trees` (ADR-0016, decisions 1 and 3):
 * one tree per directory, each excluding what `source.excludes` names inside
 * it, each with the imported floor. Without `source.directories`, the trees
 * zero-config found take the floor and the excludes.
 */
final readonly class Trees
{
    private const string DIRECTORIES = 'source.directories';

    private const string EXCLUDES = 'source.excludes';

    private const string TREES = 'trees: %s, which replace the list the tree source finds';

    private const string EXCLUDED = '%s: the exclude %s';

    private const string REGEX = '%s is a regular expression, which no glob of the gate\'s can say';

    private const string NOWHERE = '%s names nothing in any tree';

    /** What Infection reads as a regular expression rather than a path: `/…/` or `{…}`. */
    private const string REGULAR = '#^(/.+/|\{.+\})$#';

    /** @param Listed<DeclaredTree>|Absent $found the trees zero-config found */
    public static function of(Node $settings, Project $project, Listed|Absent $found): Import
    {
        $source = $settings->field(Mapped::Source->value);
        $named = self::texts($source->field('directories'));
        $trees = $named === []
            ? array_map(Tree::found(...), $found instanceof Listed ? [...$found] : [])
            : array_map(static fn(string $path): Tree => Tree::named(Path::of($path)), $named);
        $import = $named === [] ? Import::none() : Import::of(
            Layer::none(),
            Carried::imported(self::DIRECTORIES, sprintf(self::TREES, implode(', ', $named))),
        );

        foreach (self::texts($source->field('excludes')) as $exclude) {
            [$trees, $carried] = self::excluding($project, $trees, $exclude);
            $import = $import->and(Import::of(Layer::none(), $carried));
        }

        $floor = Floor::of($settings);
        $excludes = array_any($trees, static fn(Tree $tree): bool => $tree->excludes());
        $changed = $named !== [] || $floor instanceof TreeFloor || $excludes;
        $declared = array_map(static fn(Tree $tree): DeclaredTree => $tree->declared($floor), $trees);

        return $changed ? Import::of(Layer::of(Floors::of(trees: Listed::of(...$declared))))->and($import) : $import;
    }

    /**
     * The trees, each excluding what an exclude names inside it, and what became of the exclude.
     *
     * @param  list<Tree>                  $trees
     * @return array{list<Tree>, Carried}
     */
    private static function excluding(Project $project, array $trees, string $exclude): array
    {
        if (preg_match(self::REGULAR, $exclude) === 1) {
            return [$trees, Carried::dropped(self::EXCLUDES, sprintf(self::REGEX, $exclude))];
        }

        $excluded = [];

        foreach ($trees as $at => $tree) {
            $glob = self::glob($project, $tree->path()->child(Path::of($exclude)));
            $trees[$at] = $glob === '' ? $tree : $tree->excluding($glob);
            $said = sprintf(self::EXCLUDED, $tree->path()->value(), $glob);
            $excluded = $glob === '' ? $excluded : [...$excluded, $said];
        }

        return [
            $trees,
            $excluded === []
                ? Carried::dropped(self::EXCLUDES, sprintf(self::NOWHERE, $exclude))
                : Carried::imported(self::EXCLUDES, implode('; ', $excluded)),
        ];
    }

    /** The glob that excludes a path: everything under a directory, or the file itself; none where it is neither. */
    private static function glob(Project $project, Path $path): string
    {
        $absolute = $project->absolute($path);

        return match (true) {
            is_dir($absolute) => sprintf('%s/**', $path->value()),
            is_file($absolute) => $path->value(),
            default => '',
        };
    }

    /** @return list<string> the text items of a list, none where it holds no list */
    private static function texts(Node $list): array
    {
        $texts = [];

        foreach (Lenient::items($list) as $item) {
            $text = Lenient::text($item);
            $texts = $text === '' ? $texts : [...$texts, $text];
        }

        return $texts;
    }
}
