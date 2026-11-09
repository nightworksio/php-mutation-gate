<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_all;
use function array_map;
use function dirname;
use function getenv;
use function implode;
use function is_string;

use NightWorksIO\MutationGate\Core\Config\LongFilter;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use PHPUnit\Runner\Version;

use function sprintf;
use function version_compare;

/**
 * A mutant's own run, read in Pest's parent process, whose covering tests are
 * too many for a `--filter` to pass: under `pest.longFilter: set`, selected
 * exactly by their ids, named one a line in a file beside the results that
 * PHPUnit reads with `--test-id-filter-file`, where the PHPUnit Pest runs
 * reads such a file and PHPUnit can read every id back. Otherwise it runs
 * every test it loads, as it does under `files` (ADR-0138).
 *
 * @internal
 */
final class LongFilterSet
{
    /** The file a run's ids are named in, beside the results, by the digest of the ids. */
    private const string FILE = '%s/ids-%s.txt';

    /**
     * What a mutant's run is started with to select its covering tests: its
     * filter where it passes, the file of their ids where `set` selects them
     * so, and nothing where neither does.
     *
     * @param  list<string> $covering
     * @return list<string>
     */
    public static function arguments(array $covering, string $filter): array
    {
        if (Selection::passable($filter)) {
            return [$filter];
        }

        $file = self::selectsExactly($covering) ? self::written($covering) : NotGiven::value();

        return $file instanceof NotGiven ? [] : [sprintf('%s=%s', PhpUnitOption::TestIdFilterFile->value, $file)];
    }

    /**
     * Whether a mutant's run selects exactly its covering tests: by its filter, or by the file of their ids.
     *
     * @param list<string> $covering
     */
    public static function selects(array $covering, string $filter): bool
    {
        return Selection::passable($filter) || self::selectsExactly($covering);
    }

    /**
     * Whether `set` selects these tests by their ids: it is on, PHPUnit reads
     * such a file, every id reads back from it, and there is a results file
     * to keep it beside.
     *
     * @param list<string> $covering
     */
    private static function selectsExactly(array $covering): bool
    {
        $results = getenv(GateVariable::Results->value);

        return getenv(GateVariable::LongFilter->value) === LongFilter::Set->value
            && is_string($results)
            && $results !== ''
            && $covering !== []
            && version_compare(Version::id(), PhpUnitOption::IDS_SINCE, '>=')
            && array_all($covering, PhpUnitOption::readsBack(...));
    }

    /**
     * The file these ids are named in beside the results, by their digest; none where it cannot be written.
     *
     * @param list<string> $covering
     */
    private static function written(array $covering): string|NotGiven
    {
        $results = (string) getenv(GateVariable::Results->value);
        $digest = Digest::sha256Of(implode(PhpUnitOption::LINE_END, $covering))->value();

        return IdsFile::written(
            TestIds::of(...array_map(TestId::of(...), $covering)),
            sprintf(self::FILE, dirname($results), $digest),
        );
    }
}
