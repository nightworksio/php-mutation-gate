<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function array_key_exists;

use Closure;

use function implode;
use function ksort;

use NightWorksIO\MutationGate\Cli\Flow\BatchEnds;
use NightWorksIO\MutationGate\Cli\Flow\Invoked;
use NightWorksIO\MutationGate\Core\Config\LocalWork;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Local\LocalSwitch;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Watch\MutantEvent;

use function sprintf;

/**
 * What `watch` prints of a batch as it ends (ADR-0120, ADR-0146): one block
 * per unit, how many of its mutants the batch judged, by status, and each
 * survivor where it is, so a survivor shows before the round's verdict does;
 * each line of a quick round says so. With `--events`, one line of JSON per
 * judged mutant instead (see MutantEvent).
 *
 * @internal
 */
final readonly class BatchLines
{
    private const string JUDGED = '%s: judged %d %s: %s.';

    private const string COUNTED = '%d %s';

    private const string SURVIVOR = '  survived: %s:%d %s';

    private const string ONE = 'mutant';

    private const string MANY = 'mutants';

    private const string QUICK = '[quick] ';

    /**
     * What each batch judged, said line by line as it ends, each labelled
     * where the round is quick, where `local.streamBatches` says so; nothing
     * told otherwise.
     *
     * @param Closure(string): void $said
     */
    public static function streamed(LocalWork $work, Closure $said, bool $quick = false): BatchEnds
    {
        return $work->isOn(LocalSwitch::StreamBatches)
            ? BatchEnds::telling(static function (Invoked $invoked) use ($said, $quick): void {
                foreach (self::of($invoked->result->mutants()) as $line) {
                    $said($quick ? sprintf('%s%s', self::QUICK, $line) : $line);
                }
            })
            : new BatchEnds();
    }

    /**
     * Each mutant a batch judged, said as one event line as the batch ends.
     *
     * @param Closure(string): void $said
     */
    public static function events(Closure $said, bool $quick): BatchEnds
    {
        return BatchEnds::telling(static function (Invoked $invoked, Digests|Undigested $digests) use (
            $said,
            $quick,
        ): void {
            foreach ($invoked->result->mutants() as $mutant) {
                $file = $mutant->location()->file();
                $digest = $digests instanceof Digests ? $digests->sourceOf($file) : NotGiven::value();
                $event = MutantEvent::of($mutant, $quick, $digest instanceof Digest ? $digest : NotGiven::value());

                if ($event instanceof MutantEvent) {
                    $said($event->line());
                }
            }
        });
    }

    /**
     * One block per unit: how many of its mutants were judged, by status, and each survivor.
     *
     * @return list<string>
     */
    public static function of(Mutants $mutants): array
    {
        $units = [];

        foreach ($mutants as $mutant) {
            $file = $mutant->location()->file()->value();
            $units[$file] = [...array_key_exists($file, $units) ? $units[$file] : [], $mutant];
        }

        $lines = [];

        foreach ($units as $file => $judged) {
            $lines = [...$lines, ...self::block($file, Mutants::of(...$judged))];
        }

        return $lines;
    }

    /** @return list<string> how many of a unit's mutants were judged, by status, and each survivor */
    private static function block(string $unit, Mutants $mutants): array
    {
        $counted = [];

        foreach ($mutants as $mutant) {
            $status = $mutant->status()->value;
            $counted[$status] = (array_key_exists($status, $counted) ? $counted[$status] : 0) + 1;
        }

        ksort($counted);
        $statuses = [];

        foreach ($counted as $status => $count) {
            $statuses[] = sprintf(self::COUNTED, $count, $status);
        }

        return [sprintf(
            self::JUDGED,
            $unit,
            $mutants->count(),
            $mutants->count() === 1 ? self::ONE : self::MANY,
            implode(', ', $statuses),
        ), ...self::survivors($mutants)];
    }

    /** @return list<string> each survivor where it is, and its mutator */
    private static function survivors(Mutants $mutants): array
    {
        $survivors = [];

        foreach ($mutants as $mutant) {
            $survivors = $mutant->status() === MutantStatus::Survived
                ? [...$survivors, sprintf(
                    self::SURVIVOR,
                    $mutant->location()->file()->value(),
                    $mutant->location()->start()->number(),
                    $mutant->mutator(),
                )]
                : $survivors;
        }

        return $survivors;
    }
}
