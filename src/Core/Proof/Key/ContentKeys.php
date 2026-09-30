<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_key_exists;
use function array_map;
use function count;
use function hash_copy;
use function hash_update;

use HashContext;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Unit\Unit;

use function sort;
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
 *    package it drives, and the digest of the PHP it runs on.
 * 5. The digest of `vendor/composer/installed.json`.
 * 6. Every file outside the test directories, by its digest, less the
 *    exceptions.
 * 7. Every CI definition that runs the gate, as it runs.
 * 8. Of the test directories, what every key reads: what runs when it is
 *    loaded, every test file the coverage map does not know, the canary
 *    group, and what those name, each file by its digest.
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
    public const string FORMAT = 'mutation-gate proof 2';

    private const string MISSING = 'missing';

    private function __construct(private HashContext $everyKey, private Tests $tests)
    {
    }

    /** @param string $config the settings that affect results, as their canonical form writes them (ADR-0007) */
    public static function of(
        Version $gate,
        string $config,
        Identity $runner,
        Digest $installed,
        Source $source,
        Tests $tests,
    ): self {
        $context = Digest::hashing();
        self::hashEveryKeyReads($context, $gate, $config, $runner, $installed, $source);
        hash_update($context, self::testFiles('always', $tests->inEveryKey(), $tests));

        return new self($context, $tests);
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
        $keys = [];

        foreach ($units as $judging) {
            $judges = $this->judgesOf($judging);

            if ($judges instanceof Unkeyed) {
                $keys[] = Keys::none()->with($judging->unit()->path(), $judges);

                continue;
            }

            $set = $this->setOf($judges);

            if (! array_key_exists($set, $read)) {
                $read[$set] = self::testFiles('tests', $this->tests->readBy($judges), $this->tests);
            }

            $keys[] = Keys::none()->with(
                $judging->unit()->path(),
                $this->keyReading($read[$set], $judging->unit(), $coverage),
            );
        }

        return Keys::none()->and(...$keys);
    }

    private static function hashEveryKeyReads(
        HashContext $context,
        Version $gate,
        string $config,
        Identity $runner,
        Digest $installed,
        Source $source,
    ): void {
        hash_update($context, self::framed(self::FORMAT, 'gate', $gate->package(), $gate->version()));
        hash_update($context, self::framed($gate->reference()));
        hash_update($context, self::framed('config', $config, 'runner', $runner->runner()));
        self::hashVersionsIn($context, $runner);
        hash_update($context, self::framed($runner->platform()->value(), 'installed', $installed->value(), 'files'));
        self::hashFingerprintsIn($context, $source->files());
        hash_update($context, self::framed('ci', sprintf('%d', count($source->ci()))));

        foreach ($source->ci() as $definition) {
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

        sort($packages);
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

        sort($paths);
        hash_update($context, self::framed(sprintf('%d', count($paths))));

        foreach ($paths as $path) {
            hash_update($context, self::framed($path, $digests[$path]));
        }
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
        sort($values);

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

        sort($values);
        $read = self::framed($section, sprintf('%d', count($values)));

        foreach ($values as $value) {
            $digest = $tests->digestOf($paths[$value]);
            $read .= self::framed($value, $digest instanceof Digest ? $digest->value() : self::MISSING);
        }

        return $read;
    }

    /** A unit's key, from what of the test directories its judging test files read. */
    private function keyReading(string $read, Unit $unit, CoverageMap $coverage): Digest
    {
        $context = hash_copy($this->everyKey);
        hash_update($context, $read);
        $this->hashUnitRead($context, $unit, $coverage);

        return Digest::finished($context);
    }

    private function hashUnitRead(HashContext $context, Unit $unit, CoverageMap $coverage): void
    {
        $lines = $coverage->linesCovered($unit->path());
        hash_update($context, self::framed('unit', $unit->path()->value(), $this->judgedBy($unit)));
        hash_update($context, self::framed(sprintf('%d', count($lines))));

        foreach ($lines as $line) {
            $ids = array_map(
                static fn(TestId $test): string => $test->value(),
                [...$coverage->testsCovering($unit->path(), $line)],
            );
            sort($ids);
            hash_update($context, self::framed(sprintf('%d', $line->number()), sprintf('%d', count($ids)), ...$ids));
        }
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

    /** Fields as a key reads them, each written with its length in bytes before it. */
    private static function framed(string ...$fields): string
    {
        $framed = '';

        foreach ($fields as $field) {
            $framed .= sprintf("%d:%s\n", Bytes::length($field), $field);
        }

        return $framed;
    }
}
