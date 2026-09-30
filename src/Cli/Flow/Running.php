<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function max;

use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Hold\HeldMisses;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\TimeoutTriage;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * `run --plan`: one shard of a plan, on the commit the plan was made on. Each
 * held unit runs alone against the tests that hold it, then the shard's other
 * units against the whole suite, reading the coverage map the plan handed it. The
 * shard leaves every mutant's record, each unit's key and what it measured,
 * or the runner's cannot judge, for the verdict. Each mutant whose time ran
 * out and whose limit the configured cap decided runs once more with the cap
 * doubled, where the runner can raise it, and each keeps the time its judging
 * tests take on their own, from the handed map, for timeout triage.
 */
final readonly class Running
{
    private const string NO_HEAD = 'The commit HEAD is at cannot be read, so the plan cannot be checked against it. %s';

    /** A retried timeout's cap is the configured one doubled. */
    private const int DOUBLED = 2;

    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    /** The shard `--shard` names, or the one the CI's environment names where it names none. */
    public function run(Plan $plan, ShardId|Absent $named, Path $results): Written|CannotJudge
    {
        $head = $this->adapters->repository->head();
        $followed = $head instanceof CannotTell
            ? CannotJudge::because(sprintf(self::NO_HEAD, $head->why()))
            : $plan->forCheckout($head);
        $id = $named instanceof ShardId ? $named : $this->adapters->ci->shard($plan);

        return match (true) {
            $followed instanceof CannotJudge => $followed,
            $id instanceof CannotJudge => $id,
            default => $this->ranShard($followed, $id, $results),
        };
    }

    /** Every shard of a plan, one after another in this process, each leaving its result. */
    public function runAll(Plan $plan, Path $results): Written|CannotJudge
    {
        $written = Written::to($results->value());

        foreach ($plan as $shard) {
            $ran = $this->ranShard($plan, $shard->id(), $results);

            if ($ran instanceof CannotJudge) {
                return $ran;
            }
        }

        return $written;
    }

    private function ranShard(Plan $plan, ShardId $id, Path $results): Written|CannotJudge
    {
        $shard = $plan->shard($id);

        if ($shard instanceof CannotJudge) {
            return $shard;
        }

        $started = $this->setup->clock->now();
        $outcome = $this->mutated($shard, new Handoff($this->adapters->project)->read($id));
        $ended = $this->setup->clock->now();
        $spent = Seconds::of((float) $ended->format('U.u') - (float) $started->format('U.u'));
        $identity = $this->adapters->runner->identity($this->adapters->withheld);
        $result = ShardResult::of(
            $plan->digest(),
            $id,
            $this->keysOf($shard->units(), $plan->keys()),
            $outcome instanceof CannotJudge ? $outcome : $outcome->result,
            Measurement::of($spent, $identity instanceof CannotJudge ? '' : $identity->runner(), Instant::at($ended)),
        )
            ->withFlaky($outcome instanceof CannotJudge ? MutantIds::none() : $outcome->flaky)
            ->withMisses($outcome instanceof CannotJudge ? HeldMisses::none() : $outcome->misses);

        return $this->adapters->project->write(
            Workspace::result($results, $id),
            Contents::of(ShardResultFile::encode($result)),
        );
    }

    /**
     * Every invocation's mutants, timeouts retried and timed, with the
     * survivors a second run killed, of each unit but the held ones whose
     * holding tests miss lines of them; or the first cannot judge.
     */
    private function mutated(Shard $shard, CoverageMap|CannotJudge $map): Mutated|CannotJudge
    {
        $misses = new HeldCoverage($this->adapters)->misses($shard, $map);

        if ($misses instanceof CannotJudge) {
            return $misses;
        }

        $mutants = Mutants::none();
        $flaky = MutantIds::none();
        $skipped = 0;
        $retries = $this->settings->triage()->retries();

        foreach (HeldCoverage::kept($shard, $misses)->invocations() as $units) {
            $invoked = $this->invoked($this->requestFor($units, $shard->id()), $retries);

            if ($invoked instanceof CannotJudge) {
                return $invoked;
            }

            $retries -= $invoked->outOfTime;
            $mutants = Mutants::of(...$mutants, ...$invoked->result->mutants());
            $flaky = $flaky->and($invoked->flaky);
            $skipped += $invoked->result->skipped();
        }

        $timed = $map instanceof CoverageMap ? TimeoutTriage::timed($mutants, $map) : $mutants;

        return new Mutated(MutationResult::of($timed, $skipped), $flaky, $misses);
    }

    /** One invocation, its timeouts retried and its survivors run once more; or the first cannot judge. */
    private function invoked(MutationRequest $request, int $retries): Invoked|CannotJudge
    {
        $result = $this->adapters->runner->mutate($request);
        $retried = $result instanceof CannotJudge ? $result : $this->retried($result->mutants(), $request, $retries);
        $again = $retried instanceof Mutants ? $this->killedAgain($retried, $request) : $retried;

        return match (true) {
            $result instanceof CannotJudge => $result,
            $retried instanceof CannotJudge => $retried,
            $again instanceof CannotJudge => $again,
            default => new Invoked(
                MutationResult::of($retried, $result->skipped()),
                $again,
                count($this->capped($result->mutants())),
            ),
        };
    }

    /**
     * Timeout retry (ADR-0008): each mutant whose time ran out at the cap, up
     * to the retries left, run once more with the cap doubled, and the rest as
     * they were. Pest's limit cannot be raised, so Pest has no retry.
     */
    private function retried(Mutants $mutants, MutationRequest $request, int $retries): Mutants|CannotJudge
    {
        $identity = $this->adapters->runner->identity($this->adapters->withheld);
        $taken = array_slice($this->capped($mutants), 0, max(0, $retries));

        if ($taken === [] || ($identity instanceof Identity && $identity->runner() === Pest::RUNNER)) {
            return $mutants;
        }

        $again = $this->adapters->runner->retry(
            Mutants::of(...$taken),
            Seconds::of($this->settings->triage()->limit()->seconds() * self::DOUBLED),
            $request->judgedBy(),
            $request->withheld(),
        );

        return $again instanceof CannotJudge ? $again : $this->replaced($mutants, $again);
    }

    /**
     * The mutants whose time ran out at the configured cap: those whose
     * limit came from the runner's own formula would not change with it.
     *
     * @return list<Mutant>
     */
    private function capped(Mutants $mutants): array
    {
        $cap = $this->settings->triage()->limit()->seconds();

        return array_values(array_filter([...$mutants], static function (Mutant $mutant) use ($cap): bool {
            $limit = $mutant->limit();

            return $mutant->status()->ranOutOfTime() && $limit instanceof Seconds && $limit->seconds() >= $cap;
        }));
    }

    /** The mutants, each one run again replaced by what that run reported of it. */
    private function replaced(Mutants $mutants, Mutants $again): Mutants
    {
        $by = [];

        foreach ($again as $mutant) {
            $by[$mutant->id()->value()] = $mutant;
        }

        return Mutants::of(...array_map(
            static fn(Mutant $mutant): Mutant => array_key_exists($mutant->id()->value(), $by)
                ? $by[$mutant->id()->value()]
                : $mutant,
            [...$mutants],
        ));
    }

    /**
     * Survivor confirmation (ADR-0008): each survivor run once more, alone and
     * by the same tests, where `flaky.confirmSurvivors` asks for it. Those
     * killed the second time are flaky.
     */
    private function killedAgain(Mutants $mutants, MutationRequest $request): MutantIds|CannotJudge
    {
        $survivors = Mutants::of(...array_filter(
            [...$mutants],
            static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Survived,
        ));

        if (! $this->settings->triage()->confirmSurvivors() || count($survivors) === 0) {
            return MutantIds::none();
        }

        $again = $this->adapters->runner->retry(
            $survivors,
            $this->settings->triage()->limit(),
            $request->judgedBy(),
            $request->withheld(),
        );

        return $again instanceof CannotJudge ? $again : MutantIds::of(...array_map(
            static fn(Mutant $mutant): MutantId => $mutant->id(),
            array_filter([...$again], static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Killed),
        ));
    }

    /**
     * One invocation: a held unit alone by the tests that hold it, or files
     * by the whole suite, reading the map the plan handed the shard.
     */
    private function requestFor(Units $units, ShardId $shard): MutationRequest
    {
        $files = Paths::none();
        $judgedBy = WholeSuite::tests();

        foreach ($units as $unit) {
            $files = $files->with($unit->path());
            $judgedBy = $unit->judgedBy();
        }

        return MutationRequest::of($files, $judgedBy)
            ->reusingCoverage(Workspace::shardCoverage($shard))
            ->withholding($this->adapters->withheld);
    }

    private function keysOf(Units $units, Keys $planned): Keys
    {
        $keys = Keys::none();

        foreach ($units as $unit) {
            $keys = $keys->with($unit->path(), $planned->keyOf($unit->path()));
        }

        return $keys;
    }
}
