<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use Closure;

use function max;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\Laps;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * Mutants run again, each file with only one mutator and the project's
 * settings for it, each mutant's limit within the bounds a run again asks
 * for, as the invocation that made them asked, reading the coverage it read,
 * its runs together ending by the request's deadline, and one mutant
 * reproduced by the tests given, with what Infection printed. The run under
 * coverage Infection reads first is not part of what it printed.
 */
final readonly class Rerunning
{
    /**
     * @param Closure(OwnConfig, MutationRequest): (DiskPath|CannotJudge) $covered the directory holding the
     *        coverage a request reads: the map handed on, or PHPUnit's own run of its judging tests
     */
    public function __construct(
        private Project $project,
        private Shell $shell,
        private CapFiles $files,
        private bool $nativeMarkersAllowed,
        private StaticAnalysis $analysis,
        private Closure $covered,
        private PatchState $state,
        private Clock $clock = new WallClock(),
        private Bridges $bridges = new Bridges(),
    ) {
    }

    /**
     * The mutants the retrial takes run again, as the invocation that made
     * them asked, and the rest as they were.
     */
    public function retry(
        Retrial $retrial,
        MutationRequest $request,
        Mutants $mutants,
        LimitBounds $bounds,
    ): Mutants|CannotJudge {
        $runs = $retrial->runs($mutants);
        $prepared = $runs === [] ? $mutants : $this->prepared($request);

        if (! $prepared instanceof Prepared) {
            return $prepared;
        }

        $again = Mutants::none();
        $started = $this->clock->nanoseconds();

        foreach ($runs as [$file, $mutator]) {
            $result = $this->ran($prepared, $this->timed($request, $started), $file, $mutator, $bounds, $this->shell);

            if ($result instanceof CannotJudge) {
                return $result;
            }

            $again = Mutants::of(...$again, ...$result);
        }

        return $retrial->matched($mutants, $again);
    }

    public function reproduce(
        Reproducible $mutant,
        MutationRequest $request,
        LimitBounds $bounds,
    ): Reproduction|CannotJudge {
        $request = $request->narrowedTo(
            Paths::of($mutant->file()),
            $request->narrowing()->toMutators(Mutators::named($mutant->mutator())),
        );
        $prepared = $this->prepared($request);
        $shell = Transcribing::over($this->shell);
        $result = $prepared instanceof Prepared
            ? $this->ran($prepared, $request, $mutant->file(), $mutant->mutator(), $bounds, $shell)
            : $prepared;

        return $result instanceof CannotJudge
            ? $result
            : Reproduction::among($mutant->id(), $result, Reason::that(Retrial::NOT_FOUND_AGAIN), $shell->printed());
    }

    /**
     * The request, timed by what is left of its deadline since the first of
     * its runs started, so the runs one after another end by it together; as
     * it was without one.
     */
    private function timed(MutationRequest $request, int $started): MutationRequest
    {
        $deadline = $request->deadline();
        $spent = $this->clock->nanoseconds() - $started;

        return $deadline instanceof Seconds
            ? $request->within(Seconds::of(max(0, $deadline->nanoseconds() - $spent) / Seconds::NANOSECONDS))
            : $request;
    }

    /** The project's config, and the directory holding the coverage the request reads. */
    private function prepared(MutationRequest $request): Prepared|CannotJudge
    {
        $config = OwnConfig::in($this->project);

        if ($config instanceof CannotJudge) {
            return $config;
        }

        $coverage = ($this->covered)($config, $request);

        return $coverage instanceof CannotJudge ? $coverage : new Prepared($config, $coverage);
    }

    /** One file with one mutator, narrowed from the request, each mutant's limit within the bounds. */
    private function ran(
        Prepared $prepared,
        MutationRequest $request,
        Path $file,
        string $mutator,
        LimitBounds $bounds,
        Shell $shell,
    ): Mutants|CannotJudge {
        $run = new MutationRun(
            $this->project,
            $shell,
            $this->files,
            $prepared->config,
            $this->nativeMarkersAllowed,
            $this->analysis,
            $this->state,
            $this->bridges,
        );
        $narrowing = $request->narrowing()->toMutators(Mutators::named($mutator));
        $narrowed = $request->narrowedTo(Paths::of($file), $narrowing);
        // The flow times a run again whole, so its steps go untold, on a clock its deadline does not read.
        $laps = Laps::fromNanoseconds(new WallClock()->nanoseconds(...));
        $result = $run->of($narrowed, $prepared->coverage, $bounds, $laps);

        return $result instanceof CannotJudge ? $result : $result->mutants();
    }
}
