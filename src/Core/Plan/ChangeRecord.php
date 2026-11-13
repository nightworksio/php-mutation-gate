<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_filter;
use function array_keys;
use function array_map;
use function count;
use function in_array;

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;

use function sprintf;

/**
 * The change a plan's run considered, as the plan file holds it: `changed`,
 * the lines it added or modified by file; `untested`, those of them no test
 * runs, where there are any; `reach`, why it reached what it did; and
 * `widening`, the places in `reach` of the reasons that reach every unit of
 * a package, where there are any. A plan that considers no change holds none
 * of them.
 *
 * @internal the shape of the change fields of the plan file
 *
 * @phpstan-type Written array{
 *     changed?: array<string, list<int>>,
 *     untested?: array<string, list<int>>,
 *     reach?: list<string>,
 *     widening?: list<int>,
 * }
 */
final readonly class ChangeRecord
{
    private const string CHANGED = 'changed';

    private const string UNTESTED = 'untested';

    private const string REACH = 'reach';

    /** The places in `reach` of the reasons that reach every unit of a package. */
    private const string WIDENING = 'widening';

    /**
     * @return Written the lines a change added or modified, by file, those no test runs, and why it reached
     *                 what it did
     */
    public static function field(Considered $considered): array
    {
        $changed = self::linesOf($considered->changed());
        $untested = self::linesOf($considered->untested());

        return $changed === [] && count($considered->reach()) === 0 ? [] : [
            self::CHANGED => $changed,
            ...$untested === [] ? [] : [self::UNTESTED => $untested],
            self::REACH => array_map(static fn(Reason $reason): string => $reason->text(), [...$considered->reach()]),
            ...self::widening($considered->reach()),
        ];
    }

    /**
     * What a plan considered, reaching the change the plan file holds.
     *
     * @throws NotInShape
     */
    public static function onto(Considered $considered, Node $file): Considered
    {
        return $considered
            ->reaching(self::changesIn($file->field(self::CHANGED)), self::reachIn($file))
            ->untesting(self::changesIn($file->field(self::UNTESTED)));
    }

    /** @return array<string, list<int>> the lines of each file these changes name, by path */
    private static function linesOf(Changes $changes): array
    {
        $lines = [];

        foreach ($changes as $change) {
            $lines[$change->path()->value()] = array_map(
                static fn(Line $line): int => $line->number(),
                [...$change->lines()],
            );
        }

        return $lines;
    }

    /**
     * The places in a plan's reasons of those that reach every unit of a package, none where there are none.
     *
     * @return array{widening?: list<int>}
     */
    private static function widening(Reasons $reasons): array
    {
        $places = array_keys(array_filter([...$reasons], static fn(Reason $reason): bool => $reason->widens()));

        return $places === [] ? [] : [self::WIDENING => $places];
    }

    /** @throws NotInShape */
    private static function changesIn(Node $changed): Changes
    {
        $changes = Changes::none();

        foreach ($changed->isPresent() ? $changed->entries() : [] as $path => $numbers) {
            $lines = Lines::none();

            foreach ($numbers->items() as $number) {
                $lines = $lines->with(self::lineIn($number));
            }

            $changes = $changes->with(Change::modified(Path::of(sprintf('%s', $path)), $lines));
        }

        return $changes;
    }

    /** @throws NotInShape */
    private static function reachIn(Node $file): Reasons
    {
        $reasons = Reasons::of();
        $reach = $file->field(self::REACH);
        $widening = $file->field(self::WIDENING);
        $places = $widening->isPresent() ? $widening->integers() : [];

        foreach ($reach->isPresent() ? $reach->items() : [] as $at => $reason) {
            $read = Reason::that($reason->text());
            $reasons = $reasons->with(in_array($at, $places, strict: true) ? $read->widening() : $read);
        }

        return $reasons;
    }

    /** @throws NotInShape */
    private static function lineIn(Node $line): Line
    {
        $number = $line->integer();

        return $number > 0 ? Line::of($number) : throw NotInShape::at($line->at(), 'a line');
    }
}
