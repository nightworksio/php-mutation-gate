<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function file_get_contents;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\SourceMarkers;

use function sprintf;

/**
 * Infection's own ignore markers (ADR-0008): `IN_SOURCE` in a comment of
 * the source, and `ignore` or `ignoreSourceCodeByRegex` under `mutators` in
 * its config. Each is found with the `ignores.entries` entry that replaces
 * it; whether a run may go ahead with them is the verdict's to decide. No
 * comment here spells the marker, or Infection would read it as one.
 */
final readonly class NativeMarkers
{
    public const string IN_SOURCE = '@infection-ignore-all';

    /** What a replacement names in place of the mutator, where a setting applies to more than one. */
    private const string ANY_MUTATOR = '<a mutator or family>';


    private const string REPLACES_IGNORE
        = '{"path": "<the file %s is in>", "mutator": "%s", "reason": "<why no test can tell>"}';

    private const string REPLACES_REGEX
        = '{"mutant": "<the id of each mutant %s matches>", "reason": "<why no test can tell>"}';

    /** Every marker in the PHP files these paths name, and in the project's own config. */
    public static function in(Project $project, OwnConfig $config, Paths $files): Markers
    {
        $markers = Markers::none();

        foreach (PhpFiles::in($project, $files) as $file) {
            $markers = $markers->merge(self::inSource($project, $file));
        }

        return $markers->merge(self::inConfig($config));
    }

    private static function inSource(Project $project, string $file): Markers
    {
        return SourceMarkers::in(
            $project->relative($file),
            Contents::of(sprintf('%s', file_get_contents($file))),
            self::IN_SOURCE,
        );
    }

    private static function inConfig(OwnConfig $config): Markers
    {
        $markers = Markers::none();

        foreach ($config->mutators()->patterns() as $found) {
            $mutator = $found->mutator() instanceof AnyMutator ? self::ANY_MUTATOR : $found->mutator();
            $replacement = $found->isOverSource()
                ? sprintf(self::REPLACES_REGEX, $found->pattern())
                : sprintf(self::REPLACES_IGNORE, $found->pattern(), $mutator);
            $where = sprintf('%s mutators.%s', $config->name(), $found->key());
            $markers = $markers->with(Marker::of($where, $found->pattern(), $replacement));
        }

        return $markers;
    }
}
