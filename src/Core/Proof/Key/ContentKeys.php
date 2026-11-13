<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_filter;
use function array_key_exists;
use function array_map;
use function count;
use function hash_copy;
use function hash_update;

use HashContext;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\NoAnalyser;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\StaticCheck;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

use function sort;
use function spl_object_id;
use function sprintf;

/**
 * The content key of each unit: one SHA-256 over everything its result could
 * depend on, in this order.
 *
 * 1. The key's format, {@see FORMAT}, which changes whenever what a key means
 *    changes, so an older proof is never read as a newer one.
 * 2. The gate's code a result depends on: {@see VerdictCode::SEMANTICS}, and
 *    the digest of the sets of {@see VerdictCode} its runner reads, with its
 *    mutator packages (ADR-0084).
 * 3. The configuration as it affects results, serialised canonically.
 * 4. The runner's identity: its name, the version and reference of every
 *    package it drives, and the digest of the PHP it runs on; and the
 *    identity of the static analyser that checks its mutants, its name,
 *    version and config's digest, of the configuration it resolves with every
 *    file that references, or that none does (ADR-0020).
 * 5. The digest of what Composer installed, less the gate's own packages,
 *    which item 2 reads.
 * 6. Every file outside the test directories, by its digest, less the
 *    exceptions and less the PHP files the coverage map shows some test
 *    executed, which item 11 reads unit by unit (see SourceReach). So a file
 *    no map shows run, the config, autoload and Composer files among them,
 *    resets every key, as do items 2 to 5, the PHP the runner runs on and
 *    its extensions among them.
 * 7. Every CI definition that runs the gate, as it runs: a GitHub Actions
 *    workflow by its shared keys and the jobs that run the gate (ADR-0058).
 * 8. Of the test directories, what every key reads: what runs when it is
 *    loaded, every test file the coverage map does not know, the canary
 *    group, the files that declare a registered mutator the config turns
 *    on, and what those name, each file by its digest.
 * 9. Of the test directories, what else can judge the unit, each file by its
 *    digest.
 * 10. The unit: its path, what judges it, and each of its covered lines with
 *    the ids of the tests that cover it.
 * 11. Of the PHP files item 6 leaves out, those the unit reaches (see
 *    SourceReach), each by its digest; every one where what it reaches
 *    cannot be traced: for a held unit, a unit no test covers, or a unit
 *    whose test files read files (see FileReader).
 *
 * Every field is written with its length before it, and every list with its
 * count, so no two sets of inputs hash alike. The first eight are the same
 * for every unit of a run: they are the run's base, and are hashed once.
 *
 * @internal
 */
final readonly class ContentKeys
{
    public const string FORMAT = 'mutation-gate proof 9';

    private function __construct(
        private HashContext $everyKey,
        private Tests $tests,
        private Digest $mutation,
        private Fingerprints $code,
        private SourceReach $reach,
        private Fingerprints $keyed,
        private Digest $reads,
    ) {
    }

    /** @param string $config the settings that affect results, as their canonical form writes them (ADR-0007) */
    public static function of(
        Digest $gate,
        string $config,
        Identity $runner,
        AnalyserIdentity|NoAnalyser $analyser,
        Digest $installed,
        Source $source,
        Tests $tests,
        SourceReach $reach,
    ): self {
        $context = Digest::hashing();
        self::hashMutationReads($context, $gate, $config, $runner, $analyser, $installed);
        $reads = Digest::finished(hash_copy($context));
        $mutation = hash_copy($context);
        hash_update($mutation, KeyFields::framed('definitions'));
        KeyFields::fingerprints($mutation, $source->definitions());
        hash_update($mutation, self::testFiles('always', $tests->inEveryKey(), $tests));
        self::hashCodeIn($context, $reach->guarded($source), $source->ci());
        hash_update($context, self::testFiles('always', $tests->inEveryKey(), $tests));

        return new self(
            $context,
            $tests,
            Digest::finished($mutation),
            $source->outside(),
            $reach,
            $source->files(),
            $reads,
        );
    }

    /**
     * The digest of the first five inputs alone, which every key of a base
     * holds and which reads no coverage map: where no proof shares it, no key
     * of the run can match one (ADR-0135).
     *
     * @param string $config the settings that affect results, as their canonical form writes them (ADR-0007)
     */
    public static function readsOf(
        Digest $gate,
        string $config,
        Identity $runner,
        AnalyserIdentity|NoAnalyser $analyser,
        Digest $installed,
    ): Digest {
        $context = Digest::hashing();
        self::hashMutationReads($context, $gate, $config, $runner, $analyser, $installed);

        return Digest::finished($context);
    }

    /**
     * What every coverage entry's key reads (ADR-0023): the first
     * five inputs, the files that define the runner, every file outside the
     * test directories that is no PHP source, and every CI definition that
     * runs the gate. A source file is read by the entries that execute it or
     * name it, not by every entry.
     *
     * @param string $config the settings that affect results, as their canonical form writes them (ADR-0007)
     */
    public static function coverageBaseOf(
        Digest $gate,
        string $config,
        Identity $runner,
        AnalyserIdentity|NoAnalyser $analyser,
        Digest $installed,
        Source $source,
    ): Digest {
        $context = Digest::hashing();
        self::hashMutationReads($context, $gate, $config, $runner, $analyser, $installed);
        self::hashCoverageReadsIn($context, $source);

        return Digest::finished($context);
    }

    /**
     * The digests of the run's inputs a proof records its share of
     * (ADR-0008): what decides a unit's mutant set besides its source, which
     * is the first five inputs, the files that define the runner and what of
     * the test directories every key reads; the source of each of these
     * units; and each file of test cases, with the support it reads outside
     * what every key reads. Beside them, the digest of the first five inputs
     * alone, which reads no coverage map, so a plan can tell without one that
     * no proof shares its base (ADR-0135).
     */
    public function digestsOf(Units $units): Digests
    {
        $digests = Digests::of($this->mutation)->withReads($this->reads);

        foreach ($units as $unit) {
            $digests = $digests->withSource($unit->path(), $this->sourceOf($unit));
        }

        foreach ($this->tests->testCases() as $file) {
            $read = Paths::of($file, ...$this->tests->readBy(Paths::of($file)));
            $digests = $digests->withTest($file, Digest::sha256Of(self::testFiles('tests', $read, $this->tests)));
        }

        return $digests;
    }

    /**
     * The base every key of the run is built on: the digest of the first
     * eight inputs, which are the same for every unit. A proof can only be
     * hit by a key of the base it was established at.
     */
    public function base(): Digest
    {
        return Digest::finished(hash_copy($this->everyKey));
    }

    /**
     * A unit's key, from the test files the runner says can judge it. A held
     * unit can be judged by every test file, whatever the runner says. Where
     * the runner cannot say, there is no key, and the unit runs unrecorded.
     */
    public function keyOf(Unit $unit, Paths|CannotJudge $judges, CoverageMap $coverage): Digest|Unkeyed
    {
        return $this->keysOf($coverage, Judging::of($unit, $judges))->keyOf($unit->path());
    }

    /**
     * Each of these units' keys, as {@see keyOf} gives them. What of the test
     * directories one set of judging test files reads is read once, however
     * many units that set judges.
     */
    public function keysOf(CoverageMap $coverage, Judging ...$units): Keys
    {
        $read = [];
        $testsOf = [];
        $named = [];
        $keys = [];
        $sets = SetDigests::of($coverage);
        $base = $this->base()->value();

        foreach ($units as $judging) {
            $judges = $this->judgesOf($judging);

            if ($judges instanceof Unkeyed) {
                $keys[] = Keys::none()->with($judging->unit()->path(), $judges);

                continue;
            }

            $object = spl_object_id($judges);
            $named[$object] = array_key_exists($object, $named) ? $named[$object] : $this->setOf($judges);
            $set = $named[$object];

            if (! array_key_exists($set, $read)) {
                $supporting = $this->tests->readBy($judges);
                $read[$set] = Digest::sha256Of(self::testFiles('tests', $supporting, $this->tests))->value();
                $testsOf[$set] = Paths::of(...$judges, ...$supporting, ...$this->tests->inEveryKey());
            }

            $sources = $this->sourcesOf($judging->unit(), $testsOf[$set], $coverage);
            $fields = [$read[$set], $sources, ...$judging->ruling()];
            $key = $this->keyReading($base, $fields, $judging->unit(), $coverage, $sets);
            $keys[] = Keys::none()->with($judging->unit()->path(), $key);
        }

        return Keys::none()->and(...$keys);
    }

    /**
     * The digest of the files outside the test directories a unit's key reads
     * on its own (item 11): what it reaches, or every file the map observes
     * where its tests read files, as what they read cannot be traced.
     */
    private function sourcesOf(Unit $unit, Paths $tests, CoverageMap $coverage): string
    {
        return $this->tests->readFiles($tests)
            ? $this->reach->readAll($this->keyed)
            : $this->reach->readBy($unit, $tests, $coverage, $this->keyed);
    }

    /** The first five inputs, which decide a unit's mutant set besides its source. */
    private static function hashMutationReads(
        HashContext $context,
        Digest $gate,
        string $config,
        Identity $runner,
        AnalyserIdentity|NoAnalyser $analyser,
        Digest $installed,
    ): void {
        $semantics = sprintf('%d', VerdictCode::SEMANTICS);
        hash_update($context, KeyFields::framed(self::FORMAT, 'gate', $semantics, $gate->value()));
        hash_update($context, KeyFields::framed('config', $config, 'runner', $runner->runner()));
        KeyFields::versions($context, $runner);
        hash_update($context, KeyFields::framed($runner->platform()->value()));
        $checked = $analyser instanceof AnalyserIdentity
            ? [$analyser->analyser(), $analyser->version(), $analyser->config()->value()]
            : [StaticCheck::NONE];
        hash_update($context, KeyFields::framed('analyser', ...$checked));
        hash_update($context, KeyFields::framed('installed', $installed->value()));
    }

    /** The files that define the runner, every file outside the test directories that is no PHP, and the CI. */
    private static function hashCoverageReadsIn(HashContext $context, Source $source): void
    {
        hash_update($context, KeyFields::framed('coverage', 'definitions'));
        KeyFields::fingerprints($context, $source->definitions());
        $other = array_filter([...$source->files()], static fn(Fingerprint $file): bool => ! $file->path()->isPhp());
        self::hashCodeIn($context, Fingerprints::of(...$other), $source->ci());
    }

    /** Every file outside the test directories, and every CI definition that runs the gate. */
    private static function hashCodeIn(HashContext $context, Fingerprints $files, CiDefinitions $ci): void
    {
        hash_update($context, KeyFields::framed('files'));
        KeyFields::fingerprints($context, $files);
        hash_update($context, KeyFields::framed('ci', sprintf('%d', count($ci))));

        foreach ($ci as $definition) {
            hash_update($context, KeyFields::framed($definition->path()->value(), $definition->asItRuns()));
        }
    }

    /** A unit's source: its file, or every file inside its held path, each by its digest. */
    private function sourceOf(Unit $unit): Digest
    {
        $inside = $unit->isHeld() ? array_filter(
            [...$this->code],
            static fn(Fingerprint $fingerprint): bool => $fingerprint->path()->within($unit->path()),
        ) : [];
        $digest = $this->code->digestOf($unit->path());
        $own = $digest instanceof Digest ? [Fingerprint::of($unit->path(), $digest)] : [];
        $context = Digest::hashing();
        hash_update($context, KeyFields::framed('source', $unit->path()->value()));
        KeyFields::fingerprints($context, Fingerprints::of(...$inside, ...$own));

        return Digest::finished($context);
    }

    /**
     * The test files that can judge a unit: every file of test cases for a
     * held one, and none where the runner cannot say.
     */
    private function judgesOf(Judging $judging): Paths|Unkeyed
    {
        $judges = $judging->judges();

        return match (true) {
            $judges instanceof CannotJudge => Unkeyed::because($judges->why()),
            $judging->unit()->isHeld() => $this->tests->testCases(),
            default => $judges,
        };
    }

    /** One value for a set of test files, whatever order they come in. */
    private function setOf(Paths $judges): string
    {
        $values = array_map(static fn(Path $path): string => $path->value(), [...$judges->inByteOrder()]);

        return Digest::sha256Of(KeyFields::framed(...$values))->value();
    }

    /** Files of the test directories, each by its digest, in byte order, framed as a key reads them. */
    private static function testFiles(string $section, Paths $files, Tests $tests): string
    {
        $paths = [];
        $values = [];

        foreach ($files as $path) {
            $paths[$path->value()] = $path;
            $values[] = $path->value();
        }

        sort($values, SORT_STRING);
        $read = KeyFields::framed($section, sprintf('%d', count($values)));

        foreach ($values as $value) {
            $digest = $tests->digestOf($paths[$value]);
            $read .= KeyFields::framed($value, $digest instanceof Digest ? $digest->value() : Missing::DIGESTED);
        }

        return $read;
    }

    /**
     * A unit's key, built from finished digests alone, so another
     * implementation can build it alike: the format with the base, what of
     * the test directories its judging test files read and the files outside
     * them it reaches, and what the `paths` entries that match it set, where
     * they set anything that affects results (ADR-0074), then the unit and
     * each of its covered lines, in ascending order, with the digest of the
     * set of tests that ran it. Each set's digest is worked out once.
     *
     * @param string       $base the digest of what every key reads
     * @param list<string> $read the digest of what its judging test files read, of the files outside them it
     *                           reaches, and what the `paths` entries matching it set
     */
    private function keyReading(string $base, array $read, Unit $unit, CoverageMap $coverage, SetDigests $sets): Digest
    {
        $lines = $coverage->lineSets($unit->path());
        $fields = [
            self::FORMAT,
            $base,
            ...$read,
            'unit',
            $unit->path()->value(),
            $this->judgedBy($unit),
            sprintf('%d', count($lines)),
        ];

        foreach ($lines as $line => $set) {
            $fields[] = sprintf('%d', $line);
            $fields[] = $sets->digestOf($set);
        }

        return Digest::sha256Of(KeyFields::framed(...$fields));
    }


    private function judgedBy(Unit $unit): string
    {
        $by = $unit->judgedBy();

        return match (true) {
            $by instanceof Group => sprintf('the group %s', $by->name()),
            $by instanceof Filter => sprintf('the filter %s', $by->pattern()),
            default => 'the whole suite',
        };
    }
}
