<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function basename;

use Closure;

use function count;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function implode;
use function is_string;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use Pest\Mutate\Mutation;

use function sprintf;
use function strval;

use Symfony\Component\Finder\SplFileInfo;

use function trim;

/**
 * The plan's one patched run that tables its files' change places (see
 * ChangeTable): it makes every mutant of each file it is told of, whole, side
 * by side and with no schema asked for, as a part makes a whole file, places
 * each among its file's, writes the table, and judges none.
 *
 * @internal
 */
final readonly class ChangeTableMade
{
    private const string LINE = "\n";

    /** Whether this run is the plan's run that tables its files' change places. */
    public static function isAsked(): bool
    {
        return strval(getenv(GateVariable::Tabling->value)) !== '';
    }

    /**
     * Makes and tables every mutant of each file the run is told of, and
     * writes the table where the run is told to; the code the run ends with.
     *
     * @param Closure(SplFileInfo, list<int>): array<int, Mutation> $making a file's mutants, as Pest makes them
     */
    public static function tabled(Closure $making): int
    {
        $listed = file_get_contents(strval(getenv(GateVariable::Tabling->value)));
        $paths = explode(self::LINE, is_string($listed) ? trim($listed) : '');
        $files = array_map(
            static fn(string $path): SplFileInfo => new SplFileInfo($path, '', basename($path)),
            array_values(array_filter($paths, static fn(string $path): bool => $path !== '')),
        );
        $made = SideBySideMutants::made(
            $files,
            static fn(): array => [],
            static fn(SplFileInfo $file, array $lines): array => Schemata::off(
                static fn(): array => $making($file, $lines),
            ),
        );
        $whole = array_map(static fn(SplFileInfo $file): array => $made->of($file, []), $files);
        $mutators = self::numbered($whole);
        $entries = [];

        foreach ($files as $at => $file) {
            $entries[ChangeTable::digestOf(strval($file->getRealPath()))] = self::entriesOf($whole[$at], $mutators);
        }

        return self::written($mutators, $entries) ? 0 : 1;
    }

    /**
     * @param array<string, int>        $mutators
     * @param array<int|string, string> $entries
     */
    private static function written(array $mutators, array $entries): bool
    {
        $files = [];

        foreach ($entries as $digest => $spots) {
            $files[] = Member::of(strval($digest), $spots);
        }

        $table = Json::object(
            Member::of(ChangeTable::SHAPE, ChangeTable::CURRENT),
            Member::of(ChangeTable::MADE_BY, ChangeTable::makers()),
            Member::of(ChangeTable::MUTATORS, Json::items(...array_keys($mutators))),
            Member::of(ChangeTable::FILES, Json::object(...$files)),
        );

        return file_put_contents(strval(getenv(GateVariable::Places->value)), $table->line()) !== false;
    }

    /**
     * Each mutator these mutants were made by, numbered in the order the
     * files' making first came to it.
     *
     * @param  list<list<Mutation>> $whole
     * @return array<string, int>
     */
    private static function numbered(array $whole): array
    {
        $mutators = [];

        foreach ($whole as $mutants) {
            foreach ($mutants as $mutant) {
                $mutators[$mutant->mutator] ??= count($mutators);
            }
        }

        return $mutators;
    }

    /**
     * Each of a whole file's mutants' spot and place.
     *
     * @param list<Mutation>     $mutants
     * @param array<string, int> $mutators
     */
    private static function entriesOf(array $mutants, array $mutators): string
    {
        $entries = [];
        $places = ChangePlaces::counted($mutants);

        foreach ($mutants as $at => $mutant) {
            $spot = ChangeTable::spotOf($mutant, $mutators[$mutant->mutator]);
            $entries[] = sprintf(ChangeTable::PLACED, $spot, $places[$at]);
        }

        return implode(ChangeTable::BETWEEN, $entries);
    }
}
