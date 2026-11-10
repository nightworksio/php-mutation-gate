<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Workspace as GateDirectory;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\CacheClass;
use NightWorksIO\MutationGate\Core\Recheck\ShareOf;

use function sprintf;

/**
 * Where the gate keeps what one job hands the next: the plan, the coverage
 * map it used, each shard's own map, each shard's result, what each share of
 * a re-check of survivors made, and the last run's JSON report.
 *
 * @internal
 */
final readonly class Workspace
{
    public static function plan(): Path
    {
        return Path::of(sprintf('%s/plan.json', GateDirectory::root()->value()));
    }

    /** The directory of the coverage map the plan used, whole, which it hands every shard beside the shard's own. */
    public static function coverage(): Path
    {
        return Path::of(sprintf('%s/coverage', GateDirectory::root()->value()));
    }

    /** Where a shard's split streams record the lines each test executed, in a run planned without a fresh map. */
    public static function recording(ShardId $shard): Path
    {
        return Path::of(sprintf('%s/recorded/shard-%d', GateDirectory::root()->value(), $shard->number()));
    }

    /** Where a shard leaves the map its streams recorded, in a directory of results (ADR-0135). */
    public static function recorded(Path $results, ShardId $shard): Path
    {
        return Path::of(sprintf('%s/%d.recorded.json.gz', $results->value(), $shard->number()));
    }

    /** Where a shard leaves how its tests ran in its streams, in a directory of results (ADR-0135). */
    public static function streamedSuite(Path $results, ShardId $shard): Path
    {
        return Path::of(sprintf('%s/%d.suite.json', $results->value(), $shard->number()));
    }

    /**
     * The directory the verdict leaves how the suite ran in a run's streams
     * in, with the map they recorded, for a job that runs the suite to take
     * the streamed suites from (ADR-0135).
     */
    public static function suite(): Path
    {
        return Path::of(sprintf('%s/suite', GateDirectory::root()->value()));
    }

    /** The directory of the coverage map the plan hands one shard: the lines of that shard's files alone. */
    public static function shardCoverage(ShardId $shard): Path
    {
        return Path::of(sprintf('%s/coverage/shard-%d', GateDirectory::root()->value(), $shard->number()));
    }

    /**
     * The directory of the coverage map the plan hands the verdict: the lines
     * of every unit it considered, run, proved or carried, for the kill matrix.
     */
    public static function verdictCoverage(): Path
    {
        return Path::of(sprintf('%s/coverage/verdict', GateDirectory::root()->value()));
    }

    /** Where a run of some test files leaves the map that measures their tests again for the kept one. */
    public static function remeasuredCoverage(): Path
    {
        return Path::of(sprintf('%s/coverage/remeasured', GateDirectory::root()->value()));
    }

    /**
     * Where a run of one unit that a hold from the holding suites holds leaves
     * the whole suite's map, before the gate keeps each holding test on the
     * lines of what it holds alone (ADR-0005).
     */
    public static function suiteCoverage(): Path
    {
        return Path::of(sprintf('%s/coverage/suite', GateDirectory::root()->value()));
    }

    /** The directory of that map once each holding test is kept on the lines of what it holds, which the run reads. */
    public static function admittedCoverage(): Path
    {
        return Path::of(sprintf('%s/coverage/admitted', GateDirectory::root()->value()));
    }

    /**
     * The directory the map engine writes the map of a run's report into,
     * which the gate then writes where it was asked (ADR-0119).
     */
    public static function convertedCoverage(): Path
    {
        return Path::of(sprintf('%s/converted', GateDirectory::root()->value()));
    }

    /** Where the tests that hold a shard's units leave the map of their run on their own. */
    public static function heldCoverage(ShardId $shard): Path
    {
        return Path::of(sprintf('%s/held/shard-%d', GateDirectory::root()->value(), $shard->number()));
    }

    /** Where static analysis reads a survivor's mutated code, for its one check. */
    public static function checkedMutant(MutantId $mutant): Path
    {
        return Path::of(sprintf('%s/staticcheck/mutants/%s.php', GateDirectory::root()->value(), $mutant->value()));
    }

    /**
     * Where static analysis reads a survivor's original printed as its runner
     * prints its mutants, for the check that it analyses as the file does.
     */
    public static function checkedOriginal(MutantId $mutant): Path
    {
        return Path::of(sprintf('%s/staticcheck/originals/%s.php', GateDirectory::root()->value(), $mutant->value()));
    }

    /**
     * Where static analysis reads a survivor's original as it is written,
     * beside its print, for the check that the print analyses as the file
     * does read from there.
     */
    public static function checkedAsWritten(MutantId $mutant): Path
    {
        return Path::of(sprintf('%s/staticcheck/written/%s.php', GateDirectory::root()->value(), $mutant->value()));
    }

    /** Where survivors and their originals are compiled, to prove a survivor equivalent. */
    public static function equivalence(): Path
    {
        return Path::of(sprintf('%s/equivalence', GateDirectory::root()->value()));
    }

    /**
     * Where the packs of `outbreak cache` a run reads are, one for each class
     * of writer, which the plan's artifact hands on to the verdict (ADR-0131).
     */
    public static function cachePack(CacheClass $class): Path
    {
        return Path::of(sprintf('%s/cache/%s.pack', GateDirectory::root()->value(), $class->value));
    }

    /** Where a verdict leaves the entries of the proofs it wrote, of the class it writes as, for the store to take. */
    public static function cacheEntries(CacheClass $class): Path
    {
        return Path::of(sprintf('%s/cache/entries/%s.pack', GateDirectory::root()->value(), $class->value));
    }

    /** Where the Actions cache restored the packs of a class of writer, for `outbreak cache pull` to read. */
    public static function restoredPacks(CacheClass $class): Path
    {
        return Path::of(sprintf('%s/cache/restored/%s', GateDirectory::root()->value(), $class->value));
    }

    /** Where the verdict keeps the JSON report of the last run, which `digest` renders. */
    public static function report(): Path
    {
        return Path::of(sprintf('%s/report.json', GateDirectory::root()->value()));
    }

    public static function results(): Path
    {
        return Path::of(sprintf('%s/results', GateDirectory::root()->value()));
    }

    /** Where a shard leaves its result, in a directory of results. */
    public static function result(Path $results, ShardId $shard): Path
    {
        return Path::of(sprintf('%s/%d.json', $results->value(), $shard->number()));
    }

    /** Where a job of a run whose shards pull their work leaves an item's result, among results (ADR-0134). */
    public static function pulled(Path $results, ShardId $item, ShardId $job): Path
    {
        return Path::of(sprintf('%s/%d.by-%d.json', $results->value(), $item->number(), $job->number()));
    }

    /** Where the CI says how a shard's job ended, in a directory of results, where the shard left no result. */
    public static function ended(Path $results, ShardId $shard): Path
    {
        return Path::of(sprintf('%s/%d.ended.json', $results->value(), $shard->number()));
    }

    /** Where each share of a re-check of the last run's survivors leaves what it made (ADR-0106). */
    public static function survivors(): Path
    {
        return Path::of(sprintf('%s/survivors', GateDirectory::root()->value()));
    }

    /** Where one share of a re-check leaves what it made, in a directory of shares. */
    public static function survivorShare(Path $shares, ShareOf $share): Path
    {
        return Path::of(sprintf('%s/%d.json', $shares->value(), $share->share()));
    }
}
