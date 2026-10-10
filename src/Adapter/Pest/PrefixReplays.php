<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_combine;
use function array_key_exists;
use function array_keys;
use function array_map;
use function dirname;
use function getmypid;
use function hash;
use function implode;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function sprintf;
use function strval;
use function unlink;

/**
 * The replays that vouch for the kills of narrowed own runs whose order is
 * known (ADR-0004): each starts the kill's own run again with the arguments
 * Pest started it with, in the order the plugin wrote for it, with the file
 * its mutant changes served unmutated through Pest's override as the run
 * served its copy, and stops after as many tests as the run ran up to its
 * last killer, within the seconds Pest allowed its mutant. What each says
 * of its kill (see ReplayVerdict) is read from the records it writes beside
 * the run's results. Each runs once for each key in a process, side by side
 * in the places of the request's pool.
 */
final readonly class PrefixReplays
{
    /** Where the parts of a replay's key are joined. */
    private const string BETWEEN = "\n";

    /** Where a replay writes its records, beside the run's results file, by the digest of its key. */
    private const string RESULTS = '%s.replay-%s';

    public function __construct(private Project $project, private Shell $shell, private Remembered $remembered)
    {
    }

    /** Every replay's records beside a results file, as a pattern `glob()` matches. */
    public static function everyBeside(string $results): string
    {
        return sprintf(self::RESULTS, $results, '*');
    }

    /**
     * What each replay says of its kill, in the order given: none had time
     * where no time is left, or once the time left has run out.
     *
     * @param  non-empty-list<PrefixReplay> $replays
     * @param  string                       $results the run's results file, beside which each replay writes
     * @return list<ReplayVerdict>
     */
    public function verdicts(array $replays, MutationRequest $request, Seconds|Unlimited $left, string $results): array
    {
        if ($left instanceof Seconds && $left->seconds() <= 0.0) {
            return array_map(static fn(): ReplayVerdict => ReplayVerdict::NoTime, $replays);
        }

        $invocation = Invocation::installedIn($this->project->vendor());
        $started = [];
        $keys = [];

        foreach ($replays as $at => $replay) {
            $served = ServedOriginal::of($this->project, dirname($results), $replay->file());

            if ($served instanceof CannotJudge) {
                continue;
            }

            $command = $served->onto(
                $invocation->replaying($replay->options(), $request->withheld())->within($replay->within($left)),
            );
            $keys[$at] = implode(self::BETWEEN, [
                $request->withheld()->pattern(),
                $served->key(),
                strval($replay->reach()),
                $replay->limitText(),
                ...$command->arguments(),
            ]);
            $started[$keys[$at]] = [$command, $replay, $served->copy()];
        }

        $said = $started === [] ? [] : $this->remembered->replays(
            array_keys($started),
            /**
             * @param  non-empty-list<string> $unknown
             * @return list<ReplayVerdict>
             */
            fn(array $unknown): array => $this->ran($unknown, $started, $request, $left, $results),
        );
        $byKey = $said === [] ? [] : array_combine(array_keys($started), $said);

        return array_map(
            static fn(int $at): ReplayVerdict => array_key_exists($at, $keys)
                ? $byKey[$keys[$at]]
                : ReplayVerdict::Unserved,
            array_keys($replays),
        );
    }

    /**
     * What the replays of these keys said, in their order.
     *
     * @param  non-empty-list<string>                      $unknown
     * @param  array<string, array{Command, PrefixReplay, string}> $started
     * @return list<ReplayVerdict>
     */
    private function ran(
        array $unknown,
        array $started,
        MutationRequest $request,
        Seconds|Unlimited $left,
        string $results,
    ): array {
        $commands = [];
        $files = [];

        foreach ($unknown as $key) {
            [$command, $replay] = $started[$key];
            $files[$key] = sprintf(self::RESULTS, $results, hash('xxh3', $key));

            if (is_file($files[$key])) {
                unlink($files[$key]);
            }

            $commands[] = $command->with([
                GateVariable::Results->value => $files[$key],
                GateVariable::OrderOf->value => $replay->mutated(),
                GateVariable::StopAfter->value => strval($replay->reach()),
                GateVariable::KillMatrix->value => $request->search()->matrix()->value,
                ...($request->search()->ordering()->putsKillersFirst()
                    ? [GateVariable::Order->value => $this->project->order()]
                    : []),
            ]);
        }

        $ends = [...$this->shell->sideBySide(
            WorkerSlots::of($request->pool()->processes(), strval(getmypid())),
            $left,
            ...$commands,
        )];

        $verdicts = [];

        foreach ($unknown as $at => $key) {
            [, $replay, $served] = $started[$key];
            $verdicts[] = array_key_exists($at, $ends)
                ? $this->verdictOf($ends[$at], ReplayRecord::in($files[$key], $served), $replay, $left)
                : ReplayVerdict::NoTime;
        }

        return $verdicts;
    }

    /** What one replay that ran says of its kill. */
    private function verdictOf(
        Ran $ran,
        ReplayRecord $record,
        PrefixReplay $replay,
        Seconds|Unlimited $left,
    ): ReplayVerdict {
        $order = $record->order();

        return match (true) {
            $ran->wasStopped() && $replay->bindsBefore($left) => ReplayVerdict::OverLimit,
            $ran->wasStopped() => ReplayVerdict::NoTime,
            $record->failed() => ReplayVerdict::Failed,
            $record->ran() !== $replay->reach() => ReplayVerdict::OtherCount,
            ! $ran->succeeded() => ReplayVerdict::FailedRun,
            ! is_string($order) || ! $replay->matches($order) => ReplayVerdict::OtherOrder,
            default => ReplayVerdict::Stands,
        };
    }
}
