<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use Closure;
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
 * settings for it, judged by the tests given and allowed a limit as the cap,
 * which is no deadline for the run: a retry's, and one mutant reproduced with
 * what Infection printed. The run under coverage Infection reads first is not
 * part of what it printed.
 */
final readonly class Rerunning
{
    /**
     * @param Closure(OwnConfig, WholeSuite|Group|Filter, Withheld): (DiskPath|CannotJudge) $covered the
     *        directory PHPUnit has run these tests under coverage into
     */
    public function __construct(
        private Project $project,
        private Shell $shell,
        private bool $nativeMarkersAllowed,
        private Closure $covered,
    ) {
    }

    /** The mutants the retrial takes run again, and the rest as they were. */
    public function retry(
        Retrial $retrial,
        Mutants $mutants,
        Seconds $limit,
        WholeSuite|Group|Filter $judgedBy,
        Withheld $withheld,
    ): Mutants|CannotJudge {
        $runs = $retrial->runs($mutants);
        $prepared = $runs === [] ? $mutants : $this->prepared($judgedBy, $withheld);

        if (! $prepared instanceof Prepared) {
            return $prepared;
        }

        $again = Mutants::none();

        foreach ($runs as [$file, $mutator]) {
            $result = $this->ran($prepared, $file, $mutator, $limit, $judgedBy, $withheld, $this->shell);

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
        $prepared = $this->prepared($judgedBy, $withheld);
        $shell = Transcribing::over($this->shell);
        $result = $prepared instanceof Prepared
            ? $this->ran($prepared, $mutant->file(), $mutant->mutator(), $limit, $judgedBy, $withheld, $shell)
            : $prepared;

        return $result instanceof CannotJudge
            ? $result
            : Reproduction::among($mutant->id(), $result, Reason::that(Retrial::NOT_FOUND_AGAIN), $shell->printed());
    }

    /** The project's config, and the directory PHPUnit has run the judging tests under coverage into. */
    private function prepared(WholeSuite|Group|Filter $judgedBy, Withheld $withheld): Prepared|CannotJudge
    {
        $config = OwnConfig::in($this->project);

        if ($config instanceof CannotJudge) {
            return $config;
        }

        $coverage = ($this->covered)($config, $judgedBy, $withheld);

        return $coverage instanceof CannotJudge ? $coverage : new Prepared($config, $coverage);
    }

    private function ran(
        Prepared $prepared,
        Path $file,
        string $mutator,
        Seconds $limit,
        WholeSuite|Group|Filter $judgedBy,
        Withheld $withheld,
        Shell $shell,
    ): Mutants|CannotJudge {
        $request = MutationRequest::of(Paths::of($file), $judgedBy)
            ->onlyMutators(Mutators::named($mutator))
            ->withholding($withheld);
        $result = new MutationRun($this->project, $shell, $prepared->config, $this->nativeMarkersAllowed)
            ->of($request, $prepared->coverage, $limit);

        return $result instanceof CannotJudge ? $result : $result->mutants();
    }
}
