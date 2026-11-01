<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Extension\Checks;

use function array_filter;
use function array_map;
use function array_unique;
use function count;
use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Extension\LibraryChange;
use NightWorksIO\MutationGate\Extension\RunnerLibrary;

use function sort;
use function sprintf;

/**
 * What a runner reports of the four mutants of `src/Money.php`: each with the
 * record every runner reports, an id of its own, how
 * long it took and the limit it was held to, the steps the run's time went
 * to, and the evidence of its kill.
 *
 * @internal the runner contract's own
 */
final readonly class RunnerMutants
{
    private const string UNTIMED = 'It does not say how long its %s mutant took.';

    private const string TIMED = 'It says how long its %s mutant took, which never ran.';

    private const string UNLIMITED = 'It gives its %s mutant no time limit.';

    private const string LIMITED = 'It gives its %s mutant a time limit, which only a timed-out one is held to.';

    private function __construct(private MutationResult $result, private RunnerLibrary $library)
    {
    }

    /** @return list<string> */
    public static function failures(MutationResult|CannotJudge $money, RunnerLibrary $library): array
    {
        if ($money instanceof CannotJudge) {
            return [sprintf('It cannot judge the mutants of src/Money.php: %s', $money->why())];
        }

        $mutants = new self($money, $library);

        return [
            ...$mutants->records(),
            ...$mutants->ids(),
            ...$mutants->measured(),
            ...$mutants->steps(),
            ...$mutants->evidence(),
        ];
    }

    /** @return list<string> */
    private function records(): array
    {
        $expected = Records::expected(
            $this->library,
            LibraryChange::Adds,
            LibraryChange::Large,
            LibraryChange::Unused,
            LibraryChange::Drains,
        );
        $reported = Records::of($this->result);

        return [
            ...Records::unless(
                $reported === $expected,
                sprintf(
                    'It reports the mutants of src/Money.php as %s, where every runner reports %s.',
                    implode('; ', $reported),
                    implode('; ', $expected),
                ),
            ),
            ...Records::unless(
                $this->result->skipped() === 0,
                sprintf('It skips %d mutants of src/Money.php with no record.', $this->result->skipped()),
            ),
        ];
    }

    /** @return list<string> */
    private function ids(): array
    {
        $ids = array_map(static fn(Mutant $mutant): string => $mutant->id()->value(), [...$this->result->mutants()]);

        return Records::unless(array_unique($ids) === $ids, 'It gives two mutants one id.');
    }

    /** @return list<string> */
    private function measured(): array
    {
        $failures = [];

        foreach ($this->result->mutants() as $mutant) {
            $ran = $this->library->measures() && $mutant->status() !== MutantStatus::Uncovered;
            $limited = $mutant->status() === MutantStatus::TimedOut;
            $duration = $mutant->duration();
            $limit = $mutant->limit();
            $failures = [
                ...$failures,
                ...Records::unless(
                    ($duration instanceof Seconds && $duration->seconds() > 0.0) === $ran,
                    sprintf($ran ? self::UNTIMED : self::TIMED, $mutant->status()->value),
                ),
                ...Records::unless(
                    ($limit instanceof Seconds && $limit->seconds() > 0.0) === $limited,
                    sprintf($limited ? self::UNLIMITED : self::LIMITED, $mutant->status()->value),
                ),
            ];
        }

        return $failures;
    }

    /** @return list<string> */
    private function steps(): array
    {
        $steps = [...$this->result->steps()];
        $since = array_map(static fn(StepTime $step): float => $step->since()->seconds(), $steps);
        $ordered = $since;
        sort($ordered);
        $mutation = array_filter($steps, static fn(StepTime $step): bool => $step->step() === Step::Mutation);
        $counted = array_map(static fn(StepTime $step): int => $step->count(), $mutation);
        $negative = array_filter($steps, static fn(StepTime $step): bool => $step->took()->seconds() < 0.0);

        return [
            ...Records::unless(
                [...$counted] === [count($this->result->mutants())],
                'It names no one step of running the mutants that counts every mutant it judged.',
            ),
            ...Records::unless(
                $since === $ordered,
                'It names the steps its time went to out of the order they started.',
            ),
            ...Records::unless($negative === [], 'It names a step that took less than no time.'),
        ];
    }

    /** @return list<string> */
    private function evidence(): array
    {
        $killed = [...Records::having($this->result, MutantStatus::Killed)];

        if (count($killed) !== 1) {
            return [sprintf('It kills %d mutants of src/Money.php, where its tests kill one.', count($killed))];
        }

        $evidence = $this->result->evidence()->of($killed[0]->id());
        $prefix = $evidence->prefix();

        return [
            ...Records::unless(
                $evidence->ended() instanceof NotGiven,
                'It says how a kill that names its killer ended.',
            ),
            ...Records::unless(
                ! $prefix instanceof Prefix || $prefix->position() >= 1,
                'It says a kill ended before the first of its tests.',
            ),
        ];
    }
}
