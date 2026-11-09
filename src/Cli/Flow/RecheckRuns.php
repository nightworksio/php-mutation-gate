<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_key_exists;
use function array_map;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Recheck\Recheck;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Recheck\RecheckShare;
use NightWorksIO\MutationGate\Core\Recheck\ShareOf;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantTriage;

/**
 * The runs of a re-check of the last run's survivors (ADR-0020, ADR-0106):
 * each file of a share run once, the mutants of its survivors' mutators
 * alone, the files in the order taken until `survivorsFirst.budget` is
 * spent, a file it has no time left for not run; and each survivor taken
 * found again by the gate's id, which holds no line, gone where a run of its
 * file made it no more, or not reached where none ran its file.
 *
 * @internal
 */
final readonly class RecheckRuns
{
    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    /** What a share's files ran again, each within the time the budget leaves. */
    public function ran(TakenSurvivors $taken, ShareOf $share): RecheckShare|CannotJudge
    {
        $deadline = Deadline::after($this->setup->clock->now(), $this->settings->triage()->survivorsFirst()->budget());
        $ran = RecheckShare::none();

        foreach ($taken->share($share) as $file) {
            $one = $this->file($taken, $file, $deadline);

            if ($one instanceof CannotJudge) {
                return $one;
            }

            $ran = $ran->and($one);
        }

        return $ran;
    }

    /** Each survivor taken, paired by id with what the shares made, gone or not reached. */
    public function rechecked(TakenSurvivors $taken, RecheckShare $shares): Rechecked
    {
        $made = [];

        foreach ($shares->mutants() as $mutant) {
            $made[$mutant->id()->key()] = $mutant;
        }

        $triage = MutantTriage::under($this->settings->triage()->timeouts());
        $rechecks = [];

        foreach ($taken->survivors() as $before) {
            $key = $before->mutant()->id()->key();
            $rechecks[] = match (true) {
                ! $shares->ran()->has($before->mutant()->location()->file()) => Recheck::unreached($before),
                array_key_exists($key, $made) => Recheck::found(
                    $before,
                    JudgedMutant::of($made[$key], $triage->judged($made[$key]))->within($taken->reach()),
                ),
                default => Recheck::gone($before),
            };
        }

        return Rechecked::of($this->settings->floors()->uncovered(), ...$rechecks)->startedAs($this->setup->command);
    }

    /** One file's run, or the file not reached where the budget is spent. */
    private function file(TakenSurvivors $taken, Path $file, Deadline $deadline): RecheckShare|CannotJudge
    {
        $now = $this->setup->clock->now();

        if ($deadline->hasPassed($now)) {
            return RecheckShare::of(Paths::none(), Paths::of($file));
        }

        $made = $this->made($file, $taken->inFile($file), $taken->unit($file), $deadline->left($now));

        return $made instanceof CannotJudge ? $made : RecheckShare::of(Paths::of($file), Paths::none(), ...$made);
    }

    /**
     * The mutants one run of this file makes of these survivors' mutators,
     * judged by the tests that judge its unit, stopped when this much time has
     * passed. As a shard's run does, it reads the coverage the plan handed on,
     * the verdict's map of every unit the plan considered as its own, rather
     * than running the suite under coverage again for each file, and runs a
     * mutant on each core the runner uses.
     *
     * @param  list<JudgedMutant> $survivors
     * @return list<Mutant>|CannotJudge
     */
    private function made(Path $file, array $survivors, Unit $unit, Seconds $left): array|CannotJudge
    {
        $names = array_map(static fn(JudgedMutant $survivor): string => $survivor->mutant()->mutator(), $survivors);
        $request = RunRequest::of($this->adapters, $this->settings, Paths::of($file), $unit->judgedBy())
            ->across(
                Pool::of($this->adapters->processes(), $this->settings->runner()->workers())
                    ->pacedBy($this->settings->runner()->pace()),
            )
            ->reusingCoverage(Handed::maps(Workspace::verdictCoverage(), Workspace::coverage()));
        $result = $this->adapters->runner->mutate($request->narrowedTo(
            Paths::of($file),
            $request->narrowing()->toMutators(Mutators::named(...$names)),
        )->within($left));

        return $result instanceof CannotJudge ? $result : [...$result->mutants()];
    }
}
