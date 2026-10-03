<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_key_exists;
use function array_map;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;
use NightWorksIO\MutationGate\Core\Test\Role;

/**
 * Of the test directories, what can judge a unit. That is the test files the
 * runner says can judge it, the support those name and the support that
 * names in turn, and, in every key, what runs when it is loaded, every test
 * file the coverage map does not know, the canary group, the files that
 * declare a registered mutator the config turns on, and what all of those
 * name. Support is found by the names each file declares, and matching
 * over-reads on purpose: a word that happens to match brings the file in.
 *
 * What each file names is looked up once, when the files are read, so
 * following it from any file is a walk over what it names.
 */
final readonly class Tests
{
    /**
     * @param array<string, TestFile>   $files  by path
     * @param NamedFiles                $naming the support each file names
     * @param Paths                     $always the files in every key
     * @param Paths                     $cases  every file of test cases
     */
    private function __construct(
        private array $files,
        private NamedFiles $naming,
        private Paths $always,
        private Paths $cases,
    ) {
    }

    /**
     * @param Paths $known  the test files the coverage map knows
     * @param Paths $always the other test files every key reads: the canary group's where the Pest patch is
     *                      on, and those that declare a registered mutator the config turns on (ADR-0021)
     */
    public static function of(TestFiles $files, Paths $known, Paths $always): self
    {
        $byPath = [];
        $declares = [];
        $mentions = [];
        $seeds = [];
        $cases = [];

        foreach ($files as $file) {
            $path = $file->fingerprint()->path();
            $byPath[$path->value()] = $file;
            $declares[$path->value()] = $file->role() === Role::Support ? $file->php()->declares() : [];
            $mentions[$path->value()] = $file->php()->names();

            if (self::isInEveryKey($file, $known)) {
                $seeds[] = $path;
            }

            if ($file->role() === Role::TestCase) {
                $cases[] = $path;
            }
        }

        $named = NamedFiles::byName($declares, $mentions);
        $unseeded = new self($byPath, $named, Paths::none(), Paths::none());

        return new self(
            $byPath,
            $named,
            $unseeded->reachedFrom(Paths::of(...$always, ...$seeds)),
            Paths::of(...$cases),
        );
    }

    /** Every file of test cases, each of which can judge a held unit, because any test can join a group. */
    public function testCases(): Paths
    {
        return $this->cases;
    }

    /** What of the test directories goes into every key. */
    public function inEveryKey(): Paths
    {
        return $this->always;
    }

    /** What else of the test directories goes into the key of a unit these test files judge. */
    public function readBy(Paths $judges): Paths
    {
        $read = [];

        foreach ($this->reachedFrom($judges) as $path) {
            if (! $this->always->has($path)) {
                $read[] = $path;
            }
        }

        return Paths::of(...$read);
    }

    public function digestOf(Path $path): Digest|Missing
    {
        return array_key_exists($path->value(), $this->files)
            ? $this->files[$path->value()]->fingerprint()->digest()
            : Missing::at($path);
    }

    private static function isInEveryKey(TestFile $file, Paths $known): bool
    {
        return $file->role() === Role::Loaded
            || ($file->role() === Role::TestCase && ! $known->has($file->fingerprint()->path()));
    }

    /** These files, and every support file they name, transitively, in the order they were reached. */
    private function reachedFrom(Paths $from): Paths
    {
        $values = array_map(static fn(Path $path): string => $path->value(), [...$from]);
        $reached = $this->naming->reachedFrom(...$values);

        return Paths::of(...array_map(Path::of(...), $reached));
    }
}
