<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_filter;
use function array_key_exists;
use function count;
use function file_get_contents;
use function implode;
use function in_array;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ErrorDisplay;
use NightWorksIO\MutationGate\Core\Runner\Exhaustion;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function preg_match;
use function sprintf;
use function str_contains;
use function usort;

/**
 * Every mutant of a run, read from Infection's JSON log (`logs.json`) and,
 * for the skipped mutants it only counts, its text log. The logs must add
 * up: each count in `stats` equals the mutants listed under it, and
 * `totalMutantsCount` equals the sum of the counts. A mutant Infection
 * ignored by a pattern of its own config counts only where `ignores.native`
 * allows such markers. Whether Infection exited with success decides
 * nothing: a finished run is judged by its logs. A mutant its tests killed
 * carries the tests that killed it; one killed by static analysis, a
 * timeout or an error carries none. A mutant killed or errored whose output
 * holds PHP's fatal error for exactly the gate's memory cap is out of memory
 * (ADR-0004, decision 9). Under a cap, one whose process PHPUnit says ended
 * mid-test where PHP's errors were visibly hidden, as the project's PHPUnit
 * config sets `display_errors` or as PHPUnit's own word says, is out of memory
 * with no limit known, which memory triage never counts as a kill.
 *
 * @phpstan-type Found array{
 *     status: MutantStatus,
 *     file: string,
 *     line: int,
 *     mutator: string,
 *     diff: string,
 *     killers: TestIds,
 *     limit: MemoryCap|NotGiven,
 * }
 */
final readonly class Results
{
    /** What PHPUnit prints after any end of its process mid-test: a fatal error, shown or hidden, or `exit`. */
    private const string ENDED = '/Fatal error: Premature end of PHP process when running [^\n]+\.$/m';

    /** What PHPUnit 12.5 prints instead where `display_errors` was off as the test started. */
    private const string HIDDEN
        = "Fatal error: Premature end of PHPUnit's PHP process. Use display_errors=On to see the error message.";

    /** The field of a log entry that holds what the mutant's process printed. */
    private const string OUTPUT = 'processOutput';

    /** The statuses of a mutant its limit decided, which are the ones that carry it. */
    private const array TIMED = [MutantStatus::TimedOut, MutantStatus::Skipped];

    private const string STATS = 'stats';

    private const string MUTATOR = 'mutator';

    private const string NO_LOG = "Infection wrote no log, so no mutant it ran has a result. Infection said:\n%s";

    private const string OUT_OF_MEMORY
        = "Infection ran out of the %s memory cap in its own process, so it wrote no log. %s Infection said:\n%s";

    private const string NOT_IN_SHAPE = "Infection's log is not in the shape the gate reads: %s";

    private const string UNEVEN = "Infection's log counts %d under %s but lists %d.";

    private const string UNEVEN_SKIPPED = 'Infection counts %d skipped mutants but its text log names %d.';

    private const string UNEVEN_TOTAL = 'Infection counts %d mutants in all, but its counts add up to %d.';

    private const string IGNORED
        = 'Infection ignored %d mutants by an ignoreSourceCodeByRegex pattern, which ignores.native refuses.';

    private const string DOES_NOT_ADD_UP = "Infection's logs do not add up, so the gate cannot judge this run. %s";

    public static function read(
        Project $project,
        Ran $ran,
        TextLog $text,
        Limits $limits,
        MemoryCap $cap,
        ErrorDisplay|NotGiven $display,
        bool $nativeMarkersAllowed,
    ): MutationResult|CannotJudge {
        $log = $project->own(Invocation::JSON);
        $found = is_file($log)
            ? self::parsed(sprintf('%s', file_get_contents($log)), $text, $cap, $display, $nativeMarkersAllowed)
            : self::unlogged($ran, $cap);

        return $found instanceof CannotJudge
            ? $found
            : MutationResult::of(self::mutants($project, $found, $text, $limits), 0);
    }

    /** Why a run that wrote no log cannot be judged: Infection's own process out of the memory cap, or what it said. */
    private static function unlogged(Ran $ran, MemoryCap $cap): CannotJudge
    {
        return Exhaustion::isOf(Exhaustion::in($ran->output()), $cap)
            ? CannotJudge::because(sprintf(self::OUT_OF_MEMORY, $cap->written(), Exhaustion::ADVICE, $ran->output()))
            : CannotJudge::because(sprintf(self::NO_LOG, $ran->output()));
    }

    /** @return list<Found>|CannotJudge */
    private static function parsed(
        string $json,
        TextLog $text,
        MemoryCap $cap,
        ErrorDisplay|NotGiven $display,
        bool $nativeMarkersAllowed,
    ): array|CannotJudge {
        try {
            $log = Node::decode($json);
            $problem = self::problemIn($log, $text, $nativeMarkersAllowed);

            return $problem === ''
                ? self::found($log, $text, $cap, $display)
                : CannotJudge::because(sprintf(self::DOES_NOT_ADD_UP, $problem));
        } catch (NotInShape $shape) {
            return CannotJudge::because(sprintf(self::NOT_IN_SHAPE, $shape->getMessage()));
        }
    }

    /**
     * What keeps the logs from being read as they are: a count that does not
     * match its list, or mutants ignored by markers that are refused.
     *
     * @throws NotInShape
     */
    private static function problemIn(Node $log, TextLog $text, bool $nativeMarkersAllowed): string
    {
        $stats = $log->field(self::STATS);
        $problems = [];
        $sum = 0;

        foreach (LogList::cases() as $list) {
            $counted = $stats->field($list->count())->integer();
            $listed = count($log->field($list->value)->items());
            $sum += $counted;
            $problems[] = $counted === $listed ? '' : sprintf(self::UNEVEN, $counted, $list->count(), $listed);
        }

        $skipped = $stats->field('skippedCount')->integer();
        $named = $text->count(TextLog::SKIPPED);
        $total = $stats->field('totalMutantsCount')->integer();
        $ignored = $stats->field(LogList::Ignored->count())->integer();

        $problems[] = $skipped === $named ? '' : sprintf(self::UNEVEN_SKIPPED, $skipped, $named);
        $problems[] = $total === $sum + $skipped ? '' : sprintf(self::UNEVEN_TOTAL, $total, $sum + $skipped);
        $problems[] = $ignored === 0 || $nativeMarkersAllowed ? '' : sprintf(self::IGNORED, $ignored);

        return implode(' ', array_filter($problems, static fn(string $problem): bool => $problem !== ''));
    }

    /**
     * @return list<Found>
     *
     * @throws NotInShape
     */
    private static function found(Node $log, TextLog $text, MemoryCap $cap, ErrorDisplay|NotGiven $display): array
    {
        $found = [];

        foreach (LogList::cases() as $list) {
            foreach ($log->field($list->value)->items() as $entry) {
                $found[] = self::entry($list, $entry, $cap, $display);
            }
        }

        foreach ($text->under(TextLog::SKIPPED) as $skipped) {
            $found[] = [
                ...$skipped,
                'status' => MutantStatus::Skipped,
                'killers' => TestIds::none(),
                'limit' => NotGiven::value(),
            ];
        }

        return $found;
    }

    /**
     * A mutant of a list, out of memory where its output says so, and then
     * with no tests that killed it and with the cap where PHP's fatal error
     * names it.
     *
     * @return Found
     *
     * @throws NotInShape
     */
    private static function entry(LogList $list, Node $entry, MemoryCap $cap, ErrorDisplay|NotGiven $display): array
    {
        $mutator = $entry->field(self::MUTATOR);
        $output = Lenient::text($entry->field(self::OUTPUT));
        $outOfMemory = $list->mayRunOutOfMemory() && self::outOfMemory($output, $cap, $display);
        $named = $outOfMemory && Exhaustion::isOf(Exhaustion::in($output), $cap);

        return [
            'status' => $outOfMemory ? MutantStatus::OutOfMemory : $list->status(),
            'file' => $mutator->field('originalFilePath')->text(),
            'line' => $mutator->field('originalStartLine')->integer(),
            'mutator' => $mutator->field('mutatorName')->text(),
            'diff' => $entry->field('diff')->text(),
            'killers' => $list->namesKillers() && ! $outOfMemory ? KillingTests::in($output) : TestIds::none(),
            'limit' => $named ? $cap : NotGiven::value(),
        ];
    }

    /**
     * Whether a mutant whose process printed this ran out of the memory cap:
     * exactly the cap, as PHP's fatal error says, or, under a cap, a process
     * PHPUnit says ended mid-test where PHP's errors were visibly hidden.
     */
    private static function outOfMemory(string $output, MemoryCap $cap, ErrorDisplay|NotGiven $display): bool
    {
        $configured = $display instanceof ErrorDisplay && $display !== ErrorDisplay::Stdout;
        $hidden = str_contains($output, self::HIDDEN) || ($configured && preg_match(self::ENDED, $output) === 1);

        return Exhaustion::isOf(Exhaustion::in($output), $cap) || ($cap->caps() && $hidden);
    }

    /**
     * The mutants, by file and then by line, each with the gate's id, the
     * native id the text log gives it, its family and, for one that timed out
     * or was skipped, its limit, and for one out of memory, the cap where
     * its output named it.
     *
     * @param list<Found> $found
     */
    private static function mutants(
        Project $project,
        array $found,
        TextLog $text,
        Limits $limits,
    ): Mutants {
        usort(
            $found,
            static fn(array $one, array $other): int
                => [$one['file'], $one['line']] <=> [$other['file'], $other['line']],
        );
        $mutants = Mutants::none();
        $seen = [];

        foreach ($found as $mutant) {
            $file = $project->relative($mutant['file']);
            $first = MutantId::hash($file, $mutant['mutator'], $mutant['diff'], 0)->value();
            $occurrence = array_key_exists($first, $seen) ? $seen[$first] + 1 : 0;
            $seen[$first] = $occurrence;
            $line = Line::of($mutant['line']);

            $recorded = Mutant::of(
                MutantId::hash($file, $mutant['mutator'], $mutant['diff'], $occurrence),
                $text->idOf($mutant['file'], $mutant['line'], $mutant['mutator'], $mutant['diff']),
                Location::of($file, $line, Unreported::line()),
                Mutation::of($mutant['mutator'], Families::of($mutant['mutator']), $mutant['diff']),
                $mutant['status'],
                Unmeasured::duration(),
            )->killedBy($mutant['killers']);
            $mutants = $mutants->with(match (true) {
                in_array($mutant['status'], self::TIMED, strict: true)
                    => $recorded->withLimit($limits->at($file, $line)),
                $mutant['limit'] instanceof MemoryCap => $recorded->withLimit($mutant['limit']),
                default => $recorded,
            });
        }

        return $mutants;
    }
}
