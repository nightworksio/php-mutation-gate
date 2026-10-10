<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

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
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
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
 * 2. The gate's installed version and source reference.
 * 3. The configuration as it affects results, serialised canonically.
 * 4. The runner's identity: its name, the version and reference of every
 *    package it drives, and the digest of the PHP it runs on; and the
 *    identity of the static analyser that checks its mutants, its name,
 *    version and config's digest, of the configuration it resolves with every
 *    file that references, or that none does (ADR-0020, decision 14).
 * 5. The digest of `vendor/composer/installed.json`.
 * 6. Every file outside the test directories, by its digest, less the
 *    exceptions.
 * 7. Every CI definition that runs the gate, as it runs.
 * 8. Of the test directories, what every key reads: what runs when it is
 *    loaded, every test file the coverage map does not know, the canary
 *    group, the files that declare a registered mutator the config turns
 *    on, and what those name, each file by its digest.
 * 9. Of the test directories, what else can judge the unit, each file by its
 *    digest.
 * 10. The unit: its path, what judges it, and each of its covered lines with
 *    the ids of the tests that cover it.
 *
 * Every field is written with its length before it, and every list with its
 * count, so no two sets of inputs hash alike. The first eight are the same
 * for every unit of a run: they are the run's base, and are hashed once.
 */
final readonly class ContentKeys
{
    public const string FORMAT = 'mutation-gate proof 6';

    private function __construct(
        private HashContext $everyKey,
        private Tests $tests,
        private Digest $mutation,
        private Fingerprints $code,
    ) {
    }

    /** @param string $config the settings that affect results, as their canonical form writes them (ADR-0007) */
    public static function of(
        Version $gate,
        string $config,
        Identity $runner,
        AnalyserIdentity|NoAnalyser $analyser,
        Digest $installed,
        Source $source,
        Tests $tests,
    ): self {
        $context = Digest::hashing();
        self::hashMutationReads($context, $gate, $config, $runner, $analyser, $installed);
        $mutation = hash_copy($context);
        hash_update($mutation, self::framed('definitions'));
        self::hashFingerprintsIn($mutation, $source->definitions());
        hash_update($mutation, self::testFiles('always', $tests->inEveryKey(), $tests));
        self::hashCodeIn($context, $source->files(), $source->ci());
        hash_update($context, self::testFiles('always', $tests->inEveryKey(), $tests));

        return new self(
            $context,
            $tests,
            Digest::finished($mutation),
            $source->outside(),
        );
    }

    /**
     * What every coverage entry's key reads (ADR-0023, decision 1): the first
     * five inputs, the files that define the runner, every file outside the
     * test directories that is no PHP source, and every CI definition that
     * runs the gate. A source file is read by the entries that execute it or
     * name it, not by every entry.
     *
     * @param string $config the settings that affect results, as their canonical form writes them (ADR-0007)
     */
    public static function coverageBaseOf(
        Version $gate,
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
     * The digests of the run's inputs a proof records its share of (ADR-0008,
     * decision 1): what decides a unit's mutant set besides its source, which
     * is the first five inputs, the files that define the runner and what of
     * the test directories every key reads; the source of each of these units;
     * and each file of test cases, with the support it reads outside what
     * every key reads.
     */
    public function digestsOf(Units $units): Digests
    {
        $digests = Digests::of($this->mutation);

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
        $named = [];
        $keys = [];
        $tested = [];
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
                $read[$set] = Digest::sha256Of(self::testFiles('tests', $this->tests->readBy($judges), $this->tests))
                    ->value();
            }

            [$key, $tested] = $this->keyReading($base, $read[$set], $judging->unit(), $coverage, $tested);
            $keys[] = Keys::none()->with($judging->unit()->path(), $key);
        }

        return Keys::none()->and(...$keys);
    }

    /** Fields as a key reads them, each written with its length in bytes before it. */
    /** Fields written each with its length before it, so no two lists of fields write alike. */
    public static function framed(string ...$fields): string
    {
        $framed = '';

        foreach ($fields as $field) {
            $framed .= sprintf("%d:%s\n", Bytes::length($field), $field);
        }

        return $framed;
    }

    /** The first five inputs, which decide a unit's mutant set besides its source. */
    private static function hashMutationReads(
        HashContext $context,
        Version $gate,
        string $config,
        Identity $runner,
        AnalyserIdentity|NoAnalyser $analyser,
        Digest $installed,
    ): void {
        hash_update($context, self::framed(self::FORMAT, 'gate', $gate->package(), $gate->version()));
        hash_update($context, self::framed($gate->reference()));
        hash_update($context, self::framed('config', $config, 'runner', $runner->runner()));
        self::hashVersionsIn($context, $runner);
        hash_update($context, self::framed($runner->platform()->value()));
        $checked = $analyser instanceof AnalyserIdentity
            ? [$analyser->analyser(), $analyser->version(), $analyser->config()->value()]
            : [StaticCheck::NONE];
        hash_update($context, self::framed('analyser', ...$checked));
        hash_update($context, self::framed('installed', $installed->value()));
    }

    /** The files that define the runner, every file outside the test directories that is no PHP, and the CI. */
    private static function hashCoverageReadsIn(HashContext $context, Source $source): void
    {
        hash_update($context, self::framed('coverage', 'definitions'));
        self::hashFingerprintsIn($context, $source->definitions());
        $other = Fingerprints::none();

        foreach ($source->files() as $file) {
            $other = $file->path()->isPhp() ? $other : $other->with($file);
        }

        self::hashCodeIn($context, $other, $source->ci());
    }

    /** Every file outside the test directories, and every CI definition that runs the gate. */
    private static function hashCodeIn(HashContext $context, Fingerprints $files, CiDefinitions $ci): void
    {
        hash_update($context, self::framed('files'));
        self::hashFingerprintsIn($context, $files);
        hash_update($context, self::framed('ci', sprintf('%d', count($ci))));

        foreach ($ci as $definition) {
            hash_update($context, self::framed($definition->path()->value(), $definition->asItRuns()));
        }
    }

    private static function hashVersionsIn(HashContext $context, Identity $runner): void
    {
        $versions = [];
        $packages = [];

        foreach ($runner->versions() as $version) {
            $versions[$version->package()] = $version;
            $packages[] = $version->package();
        }

        sort($packages, SORT_STRING);
        hash_update($context, self::framed(sprintf('%d', count($packages))));

        foreach ($packages as $package) {
            $version = $versions[$package];
            hash_update($context, self::framed($version->package(), $version->version(), $version->reference()));
        }
    }

    private static function hashFingerprintsIn(HashContext $context, Fingerprints $fingerprints): void
    {
        $digests = [];
        $paths = [];

        foreach ($fingerprints as $fingerprint) {
            $digests[$fingerprint->path()->value()] = $fingerprint->digest()->value();
            $paths[] = $fingerprint->path()->value();
        }

        sort($paths, SORT_STRING);
        hash_update($context, self::framed(sprintf('%d', count($paths))));

        foreach ($paths as $path) {
            hash_update($context, self::framed($path, $digests[$path]));
        }
    }

    /** A unit's source: its file, or every file inside its held path, each by its digest. */
    private function sourceOf(Unit $unit): Digest
    {
        $inside = Fingerprints::none();

        foreach ($unit->isHeld() ? $this->code : [] as $fingerprint) {
            $inside = $fingerprint->path()->within($unit->path()) ? $inside->with($fingerprint) : $inside;
        }

        $digest = $this->code->digestOf($unit->path());
        $inside = $digest instanceof Digest ? $inside->with(Fingerprint::of($unit->path(), $digest)) : $inside;
        $context = Digest::hashing();
        hash_update($context, self::framed('source', $unit->path()->value()));
        self::hashFingerprintsIn($context, $inside);

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
        $values = array_map(static fn(Path $path): string => $path->value(), [...$judges]);
        sort($values, SORT_STRING);

        return Digest::sha256Of(self::framed(...$values))->value();
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
        $read = self::framed($section, sprintf('%d', count($values)));

        foreach ($values as $value) {
            $digest = $tests->digestOf($paths[$value]);
            $read .= self::framed($value, $digest instanceof Digest ? $digest->value() : Missing::DIGESTED);
        }

        return $read;
    }

    /**
     * A unit's key, built from finished digests alone, so another
     * implementation can build it alike: the format with the base and what
     * of the test directories its judging test files read, then the unit and
     * each of its covered lines, in ascending order, with the digest of the
     * set of tests that ran it. Each set's digest is worked out once.
     *
     * @param  string                               $base   the digest of what every key reads
     * @param  string                               $read   the digest of what its judging test files read
     * @param  array<string, string>                $tested each set's digest, by the name the map gives it
     * @return array{Digest, array<string, string>}
     */
    private function keyReading(string $base, string $read, Unit $unit, CoverageMap $coverage, array $tested): array
    {
        $lines = $coverage->lineSets($unit->path());
        $fields = [
            self::FORMAT,
            $base,
            $read,
            'unit',
            $unit->path()->value(),
            $this->judgedBy($unit),
            sprintf('%d', count($lines)),
        ];

        foreach ($lines as $line => $set) {
            $name = $set->name();

            if (! array_key_exists($name, $tested)) {
                $ids = array_map(static fn(TestId $test): string => $test->value(), [...$lines->testsOf($set)]);
                $tested[$name] = Digest::sha256Of(self::framed(sprintf('%d', count($ids)), ...$ids))->value();
            }

            $fields[] = sprintf('%d', $line);
            $fields[] = $tested[$name];
        }

        return [Digest::sha256Of(self::framed(...$fields)), $tested];
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
