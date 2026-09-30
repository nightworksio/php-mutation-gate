<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use Closure;

use function max;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * Mutants run again, each file with only one mutator and the project's
 * settings for it, allowed a limit as the cap: a retry's, as the invocation
 * that made them asked, reading the coverage it read, its runs together
 * ending by the request's deadline, and one mutant
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
        private bool $nativeMarkersAllowed,
        private Closure $covered,
        private Clock $clock = new WallClock(),
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
        Seconds $limit,
    ): Mutants|CannotJudge {
        $runs = $retrial->runs($mutants);
        $prepared = $runs === [] ? $mutants : $this->prepared($request);

        if (! $prepared instanceof Prepared) {
            return $prepared;
        }

        $again = Mutants::none();
        $started = $this->clock->nanoseconds();

        foreach ($runs as [$file, $mutator]) {
            $result = $this->ran($prepared, $this->timed($request, $started), $file, $mutator, $limit, $this->shell);

            if ($result instanceof CannotJudge) {
                return $result;
            }

            $again = Mutants::of(...$again, ...$result);
        }

        return $retrial->matched($mutants, $again);
    }

    public function reproduce(
        Reproducible $mutant,
        WholeSuite|Group|Filter $judgedBy,
        Seconds $limit,
        Withheld $withheld,
    ): Reproduction|CannotJudge {
        $request = MutationRequest::of(Paths::of($mutant->file()), $judgedBy)->withholding($withheld);
        $prepared = $this->prepared($request);
        $shell = Transcribing::over($this->shell);
        $result = $prepared instanceof Prepared
            ? $this->ran($prepared, $request, $mutant->file(), $mutant->mutator(), $limit, $shell)
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

    /** One file with one mutator, narrowed from the request, allowed the limit as the cap. */
    private function ran(
        Prepared $prepared,
        MutationRequest $request,
        Path $file,
        string $mutator,
        Seconds $limit,
        Shell $shell,
    ): Mutants|CannotJudge {
        $result = new MutationRun($this->project, $shell, $prepared->config, $this->nativeMarkersAllowed)
            ->of($request->narrowedTo(Paths::of($file), Mutators::named($mutator)), $prepared->coverage, $limit);

        return $result instanceof CannotJudge ? $result : $result->mutants();
    }
}
