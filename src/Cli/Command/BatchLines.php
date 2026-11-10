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
use NightWorksIO\MutationGate\Core\Local\LocalSwitch;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;

use function sprintf;

/**
 * What `watch` prints of a batch as it ends (ADR-0120): how many mutants it
 * judged, by status, and each survivor where it is, so a survivor shows
 * before the round's verdict does; each line of a quick round says so
 * (ADR-0146).
 *
 * @internal
 */
final readonly class BatchLines
{
    private const string JUDGED = 'Judged %d %s: %s.';

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
                    $said($quick ? self::QUICK . $line : $line);
                }
            })
            : new BatchEnds();
    }

    /** @return list<string> */
    public static function of(Mutants $mutants): array
    {
        if ($mutants->count() === 0) {
            return [];
        }

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
