<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_map;
use function count;
use function hash_copy;
use function hash_final;
use function hash_init;
use function hash_update;

use HashContext;

use function iterator_to_array;
use function mb_strlen;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Unit\Unit;

use function sort;
use function sprintf;
use function usort;

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
 * 7. The one CI definition that runs the gate, as it runs.
 * 8. Of the test directories, what can judge the unit, each file by its digest.
 * 9. The unit: its path, what judges it, and each of its covered lines with
 *    the ids of the tests that cover it.
 *
 * Every field is written with its length before it, and every list with its
 * count, so no two sets of inputs hash alike. The first seven are the same
 * for every unit of a run, and are hashed once.
 */
final readonly class ContentKeys
{
    public const string FORMAT = 'mutation-gate proof 1';

    private const string ALGORITHM = 'sha256';

    private const string MISSING = 'missing';

    private function __construct(private HashContext $everyKey, private Tests $tests)
    {
    }

    public static function of(
        Version $gate,
        Document $config,
        Identity $runner,
        Digest $installed,
        Source $source,
        Tests $tests,
    ): self {
        $context = hash_init(self::ALGORITHM);

        foreach (self::everyKeyReads($gate, $config, $runner, $installed, $source) as $field) {
            hash_update($context, self::framed($field));
        }

        return new self($context, $tests);
    }

    /**
     * A unit's key, from the test files the runner says can judge it. A held
     * unit can be judged by every test file, whatever the runner says. Where
     * the runner cannot say, there is no key, and the unit runs unrecorded.
     */
    public function keyOf(Unit $unit, Paths|CannotJudge $judges, CoverageMap $coverage): Digest|Unkeyed
    {
        if ($judges instanceof CannotJudge) {
            return Unkeyed::because($judges->why());
        }

        $context = hash_copy($this->everyKey);

        $judging = $unit->isHeld() ? $this->tests->testCases() : $judges;

        foreach ([...$this->testsReadBy($judging), ...self::unitRead($unit, $coverage)] as $field) {
            hash_update($context, self::framed($field));
        }

        return Digest::of(hash_final($context));
    }

    /** @return list<string> */
    private static function everyKeyReads(
        Version $gate,
        Document $config,
        Identity $runner,
        Digest $installed,
        Source $source,
    ): array {
        return [
            self::FORMAT,
            'gate', $gate->package(), $gate->version(), $gate->reference(),
            'config', $config->json(),
            'runner', $runner->runner(), ...self::versionsIn($runner), $runner->platform()->value(),
            'installed', $installed->value(),
            'files', ...self::fingerprintsIn(iterator_to_array($source->files(), preserve_keys: false)),
            'ci', $source->ci()->path()->value(), $source->ci()->asItRuns(),
        ];
    }

    /** @return list<string> */
    private static function versionsIn(Identity $runner): array
    {
        $versions = iterator_to_array($runner->versions(), preserve_keys: false);
        usort($versions, static fn(Version $one, Version $other): int => $one->package() <=> $other->package());
        $fields = [sprintf('%d', count($versions))];

        foreach ($versions as $version) {
            $fields = [...$fields, $version->package(), $version->version(), $version->reference()];
        }

        return $fields;
    }

    /**
     * @param  list<Fingerprint> $fingerprints
     * @return list<string>
     */
    private static function fingerprintsIn(array $fingerprints): array
    {
        usort(
            $fingerprints,
            static fn(Fingerprint $one, Fingerprint $other): int => $one->path()->value() <=> $other->path()->value(),
        );
        $fields = [sprintf('%d', count($fingerprints))];

        foreach ($fingerprints as $fingerprint) {
            $fields = [...$fields, $fingerprint->path()->value(), $fingerprint->digest()->value()];
        }

        return $fields;
    }

    /** @return list<string> */
    private function testsReadBy(Paths $judges): array
    {
        $read = iterator_to_array($this->tests->readBy($judges), preserve_keys: false);
        usort($read, static fn(Path $one, Path $other): int => $one->value() <=> $other->value());
        $fields = ['tests', sprintf('%d', count($read))];

        foreach ($read as $path) {
            $digest = $this->tests->digestOf($path);
            $fields = [...$fields, $path->value(), $digest instanceof Digest ? $digest->value() : self::MISSING];
        }

        return $fields;
    }

    /** @return list<string> */
    private static function unitRead(Unit $unit, CoverageMap $coverage): array
    {
        $lines = $coverage->linesCovered($unit->path());
        $fields = ['unit', $unit->path()->value(), self::judgedBy($unit), sprintf('%d', count($lines))];

        foreach ($lines as $line) {
            $ids = array_map(
                static fn(TestId $test): string => $test->value(),
                iterator_to_array($coverage->testsCovering($unit->path(), $line), preserve_keys: false),
            );
            sort($ids);
            $fields = [...$fields, sprintf('%d', $line->number()), sprintf('%d', count($ids)), ...$ids];
        }

        return $fields;
    }

    private static function judgedBy(Unit $unit): string
    {
        $by = $unit->judgedBy();

        return match (true) {
            $by instanceof Group => sprintf('the group %s', $by->name()),
            $by instanceof Filter => sprintf('the filter %s', $by->pattern()),
            default => 'the whole suite',
        };
    }

    private static function framed(string $field): string
    {
        return sprintf("%d:%s\n", mb_strlen($field, '8bit'), $field);
    }
}
