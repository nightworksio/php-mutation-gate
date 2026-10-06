<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function array_key_exists;
use function array_values;
use function implode;
use function is_string;

use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Workforce;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;

use function sprintf;

/**
 * A run of the gate's own mutants through PHPUnit, as the PHPUnit runner
 * makes one (ADR-0023, decisions 8 to 10): the mutants of a request's files,
 * each judged by the tests that cover it under the memory cap, by the map the
 * request reads. A request judged by the whole suite that reuses the map
 * another job handed on reads that map; any other has PHPUnit run the tests
 * that judge it under coverage first. A run again reads the map the run it
 * follows read, makes only the mutants it is asked for, and hands each back
 * under its id, or unjudged where it made no mutant with that id.
 */
final readonly class Mutating
{
    /** Why a mutant run again is unjudged: the engine made no mutant with its id. */
    public const string NOT_FOUND_AGAIN = 'Run again, the gate made no mutant with this id.';

    /** What a map handed on is held under: the file it is read from. */
    private const string HANDED = "handed\n%s";

    /** What a map PHPUnit runs is held under: the command's arguments, then the pattern of what it withholds. */
    private const string RAN = "run\n%s\n%s";

    /** The map file a held run's command names; each run names its own, so the key names none. */
    private const string NO_MAP_FILE = '';

    public function __construct(
        private Project $project,
        private Shell $shell,
        private TestFiles $tests,
        private CapFiles $files,
        private HeldCoverage $held,
        private Engine|CannotJudge $engine,
    ) {
    }

    /** Every mutant the request asks for, or only those with these ids, each stopped at its limit in these bounds. */
    public function result(
        MutationRequest $request,
        LimitBounds $bounds,
        MutantIds|NotGiven $only,
    ): MutationResult|CannotJudge {
        $engine = $this->engine;

        if ($engine instanceof CannotJudge) {
            return $engine;
        }

        $override = Override::writtenFor($this->project);
        $invocation = is_string($override) ? new Invocation($this->project, $override) : $override;
        $map = $invocation instanceof Invocation ? $this->mapOf($request, $invocation) : $invocation;

        return match (true) {
            ! $invocation instanceof Invocation => $invocation,
            $map instanceof CannotJudge => $map,
            default => $this->capped($engine, $invocation, $map, $request, $bounds, $only),
        };
    }

    /**
     * These mutants run again: the request narrowed to their files and their
     * mutators, making only them, each handed back under its id.
     */
    public function again(MutationRequest $request, Mutants $mutants, LimitBounds $bounds): Mutants|CannotJudge
    {
        $files = [];
        $mutators = [];
        $ids = MutantIds::none();

        foreach ($mutants as $mutant) {
            $files[$mutant->location()->file()->value()] = $mutant->location()->file();
            $mutators[$mutant->mutator()] = $mutant->mutator();
            $ids = $ids->and(MutantIds::of($mutant->id()));
        }

        if ($files === []) {
            return Mutants::none();
        }

        $result = $this->result(
            $request->narrowedTo(
                Paths::of(...array_values($files)),
                $request->narrowing()->toMutators(Mutators::named(...array_values($mutators))),
            ),
            $bounds,
            $ids,
        );

        return $result instanceof CannotJudge ? $result : $this->matched($mutants, $result->mutants());
    }

    /** One mutant run again on its own, the request narrowed to its file and its mutator, with what was printed. */
    public function reproduced(
        Reproducible $mutant,
        MutationRequest $request,
        LimitBounds $bounds,
        Transcribing $printing,
    ): Reproduction|CannotJudge {
        $narrowed = $request->narrowedTo(
            Paths::of($mutant->file()),
            $request->narrowing()->toMutators(Mutators::named($mutant->mutator())),
        );
        $result = $this->result($narrowed, $bounds, MutantIds::of($mutant->id()));

        $unmade = Reason::that(self::NOT_FOUND_AGAIN);

        return $result instanceof CannotJudge
            ? $result
            : Reproduction::among($mutant->id(), $result->mutants(), $unmade, $printing->printed());
    }

    /**
     * The map a request reads: the shard's own a plan handed on, for a
     * request judged by the whole suite that reuses the plan's maps;
     * otherwise PHPUnit's run of the tests that judge it under coverage, never
     * seeing a variable it withholds. Each is read once for every run again
     * that reads the same.
     */
    private function mapOf(MutationRequest $request, Invocation $invocation): CoverageMap|CannotJudge
    {
        $coverage = new Coverage($this->project, $this->shell, $invocation);
        $handed = $request->coverage();

        if ($handed instanceof Handed && $request->judgedBy() instanceof WholeSuite) {
            $own = $handed->own();

            return $this->held->readFrom(
                sprintf(self::HANDED, $own->value()),
                static fn(): CoverageMap|CannotJudge => $coverage->of(CoverageRead::from($own)),
            );
        }

        $suite = $request->narrowing()->suite();
        $run = CoverageRun::of($request->judgedBy(), $this->project->ownPath(CoverageRun::OWN_DIRECTORY))
            ->withholding($request->withheld());
        $run = $suite instanceof SuiteName ? $run->inSuite($suite) : $run;
        $command = $invocation->coverage($run, self::NO_MAP_FILE);

        return $this->held->readFrom(
            sprintf(self::RAN, implode("\n", $command->arguments()), $run->withheld()->pattern()),
            static fn(): CoverageMap|CannotJudge => $coverage->of($run),
        );
    }

    /** The run of the mutants under the request's memory cap, whose directory is removed once the run is done. */
    private function capped(
        Engine $engine,
        Invocation $invocation,
        CoverageMap $map,
        MutationRequest $request,
        LimitBounds $bounds,
        MutantIds|NotGiven $only,
    ): MutationResult|CannotJudge {
        $scan = MemoryScan::in($this->project, $request->memory(), $this->files);

        if ($scan instanceof CannotJudge) {
            return $scan;
        }

        $judging = new MutantRun(
            $this->project,
            $this->shell,
            $invocation,
            $this->tests,
            $scan,
            $this->project->errorDisplay(),
        );
        $workforce = new Workforce($this->project, $this->shell, $invocation, $scan, $judging);
        $run = new MutationRun($this->project, $engine, $judging, $workforce);
        $result = ($only instanceof MutantIds ? $run->makingOnly($only) : $run)->of($request, $map, $bounds);
        $scan->remove();

        return $result;
    }

    /** Each mutant as the run made it again, under its id; or unjudged where the run made none with that id. */
    private function matched(Mutants $mutants, Mutants $again): Mutants
    {
        $found = [];
        $matched = [];

        foreach ($again as $mutant) {
            $found[$mutant->id()->key()] = $mutant;
        }

        foreach ($mutants as $mutant) {
            $id = $mutant->id()->key();
            $matched[] = array_key_exists($id, $found) ? $found[$id] : Mutant::of(
                $mutant->id(),
                $mutant->nativeId(),
                $mutant->location(),
                $mutant->mutation(),
                MutantStatus::Unjudged,
                Unmeasured::duration(),
            )->because(Reason::that(self::NOT_FOUND_AGAIN));
        }

        return Mutants::of(...$matched);
    }
}
