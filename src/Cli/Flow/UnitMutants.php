<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;

use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Cost\MutantSites;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\NewestProofs;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Sample\Candidate;
use NightWorksIO\MutationGate\Core\Sample\Tally;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\MutantTriage;
use NightWorksIO\MutationGate\Core\Verdict\Scoring;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;

/**
 * How many mutants a unit is estimated to hold, for a sample
 * (ADR-0020): as many as its newest proof counts, as a verdict scores
 * them, or else as many as the gate's own engine makes of its file. A
 * held unit no proof records, or a file the engine cannot read, is
 * estimated by nothing.
 *
 * @internal
 */
final readonly class UnitMutants
{
    private function __construct(
        private NewestProofs $newest,
        private Engine|NotGiven $engine,
        private Adapters $adapters,
        private MutantTriage $triage,
        private Uncovered $uncovered,
    ) {
    }

    public static function of(Adapters $adapters, Settings $settings, NewestProofs $newest): self
    {
        return new self(
            $newest,
            $adapters->engine,
            $adapters,
            MutantTriage::under($settings->triage()->timeouts()),
            Uncovered::from($settings->floors()->uncovered()->value),
        );
    }

    /** A unit, with the mutants it is estimated to hold and its newest proof's tally; none where nothing says. */
    public function candidate(Unit $unit): Candidate|NotGiven
    {
        $proof = $this->newest->of($unit->path());

        if ($proof instanceof Proof) {
            $tally = $this->tallyOf($proof);

            return Candidate::of($unit, $tally->counted(), $tally);
        }

        $engine = $this->engine;
        $sites = $unit->isHeld() || $engine instanceof NotGiven ? NotGiven::value() : $this->sites($unit, $engine);

        return $sites instanceof MutantSites ? Candidate::of($unit, $sites->count(), NotGiven::value()) : $sites;
    }

    /** What the engine makes of a unit's file; none where it cannot read or parse it. */
    private function sites(Unit $unit, Engine $engine): MutantSites|NotGiven
    {
        $code = $this->adapters->project->read($unit->path());
        $sites = $code instanceof Contents ? $engine->sitesOf($unit->path(), $code) : NotGiven::value();

        return $sites instanceof MutantSites ? $sites : NotGiven::value();
    }

    /** A proof's mutants, killed of counted, as a verdict scores them. */
    private function tallyOf(Proof $proof): Tally
    {
        $killed = count($proof->kills());
        $counted = count($proof->kills());

        foreach ($proof->reported() as $mutant) {
            $scoring = $this->triage->judged($mutant)->scoring($this->uncovered);
            $killed += $scoring === Scoring::Killed ? 1 : 0;
            $counted += $scoring === Scoring::LeftOut ? 0 : 1;
        }

        return Tally::of($killed, $counted);
    }
}
