<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use function implode;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Unit\Unit;

use function sprintf;

/**
 * Whether the tests that hold a unit may judge it: they must cover every line
 * of it that the whole suite covers, and a unit of which they run nothing is
 * unreached as a whole.
 */
final readonly class GroupCoverage
{
    /** Why the holding tests cannot judge what they hold. */
    private const string MISSES = <<<'SAID'
        %s does not cover %s, so its mutants cannot be judged by it.
        Not reached: %s
        Add the test that runs them to the group.
        SAID;

    /** What the holding tests miss when they run none of what they hold. */
    private const string WHOLE = '%s, all of it';

    /** How a `#[Holds]` is written, to name it. */
    private const string ATTRIBUTE = "#[Holds('%s')]";

    /** A line the holding tests miss. */
    private const string LINE = '%s:%d';

    /** Whether the holding tests, whose run alone made the group's map, cover what the suite's map covers of a unit. */
    public static function of(Unit $held, CoverageMap $suite, CoverageMap $group): Covered|NotCovered
    {
        $missed = self::runsAny($group, $held->path())
            ? self::missed($held->path(), $suite, $group)
            : [sprintf(self::WHOLE, $held->path()->value())];

        $message = sprintf(self::MISSES, self::nameOf($held), $held->path()->value(), implode(', ', $missed));

        return $missed === [] ? Covered::by($held) : NotCovered::because($held, $message);
    }

    private static function runsAny(CoverageMap $map, Path $path): bool
    {
        foreach ($map->files() as $file) {
            if ($file->within($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every line of a path the suite covers and the group does not, as `path:line`.
     *
     * @return list<string>
     */
    private static function missed(Path $path, CoverageMap $suite, CoverageMap $group): array
    {
        $missed = [];

        foreach ($suite->files() as $file) {
            $covered = $file->within($path) ? $group->linesCovered($file) : Lines::none();

            foreach ($file->within($path) ? $suite->linesCovered($file) : [] as $line) {
                if (! $covered->has($line)) {
                    $missed[] = sprintf(self::LINE, $file->value(), $line->number());
                }
            }
        }

        return $missed;
    }

    private static function nameOf(Unit $held): string
    {
        $judge = $held->judgedBy();

        return $judge instanceof Group ? $judge->name() : sprintf(self::ATTRIBUTE, $held->path()->value());
    }
}
