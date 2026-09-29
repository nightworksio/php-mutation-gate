<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_key_exists;
use function count;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * Of the test directories, what can judge a unit. That is the test files the
 * runner says can judge it, the support those name and the support that
 * names in turn, and, in every key, what runs when it is loaded, every test
 * file the coverage map does not know, the canary group, and what all of
 * those name. Support is found by the names each file declares, and matching
 * over-reads on purpose: a word that happens to match brings the file in.
 */
final readonly class Tests
{
    /**
     * @param array<string, TestFile>   $files  by path
     * @param array<string, list<Path>> $byName each support file, by each name it declares
     * @param Paths                     $always the files in every key
     */
    private function __construct(private array $files, private array $byName, private Paths $always)
    {
    }

    /**
     * @param Paths $known    the test files the coverage map knows
     * @param Paths $canaries the canary group's test files where the Pest patch is on, and none where it is off
     */
    public static function of(TestFiles $files, Paths $known, Paths $canaries): self
    {
        $byPath = [];
        $byName = [];
        $seeds = $canaries;

        foreach ($files as $file) {
            $path = $file->fingerprint()->path();
            $byPath[$path->value()] = $file;
            $byName = self::withDeclarationsOf($file, $byName);
            $seeds = self::isInEveryKey($file, $known) ? $seeds->with($path) : $seeds;
        }

        $unseeded = new self($byPath, $byName, Paths::none());

        return new self($byPath, $byName, $unseeded->reachedFrom($seeds));
    }

    /** Every file of test cases, each of which can judge a held unit, because any test can join a group. */
    public function testCases(): Paths
    {
        $cases = Paths::none();

        foreach ($this->files as $file) {
            $cases = $file->role() === Role::TestCase ? $cases->with($file->fingerprint()->path()) : $cases;
        }

        return $cases;
    }

    /** What of the test directories goes into the key of a unit these test files judge. */
    public function readBy(Paths $judges): Paths
    {
        return Paths::of(...$this->always, ...$this->reachedFrom($judges));
    }

    public function digestOf(Path $path): Digest|Missing
    {
        return array_key_exists($path->value(), $this->files)
            ? $this->files[$path->value()]->fingerprint()->digest()
            : Missing::at($path);
    }

    /**
     * @param  array<string, list<Path>> $byName
     * @return array<string, list<Path>>
     */
    private static function withDeclarationsOf(TestFile $file, array $byName): array
    {
        foreach ($file->role() === Role::Support ? $file->php()->declares() : [] as $name) {
            $byName[$name][] = $file->fingerprint()->path();
        }

        return $byName;
    }

    private static function isInEveryKey(TestFile $file, Paths $known): bool
    {
        return $file->role() === Role::Loaded
            || ($file->role() === Role::TestCase && ! $known->has($file->fingerprint()->path()));
    }

    /** These files, and every support file they name, transitively, in the order they were reached. */
    private function reachedFrom(Paths $from): Paths
    {
        $queue = [...$from];
        $reached = $from;

        $at = 0;

        while ($at < count($queue)) {
            foreach ($this->supportNamedBy($queue[$at]) as $found) {
                $queue = $reached->has($found) ? $queue : [...$queue, $found];
                $reached = $reached->with($found);
            }

            $at++;
        }

        return $reached;
    }

    /** @return list<Path> */
    private function supportNamedBy(Path $file): array
    {
        $found = [];
        $names = array_key_exists($file->value(), $this->files) ? $this->files[$file->value()]->php()->names() : [];

        foreach ($names as $name) {
            $found = [...$found, ...(array_key_exists($name, $this->byName) ? $this->byName[$name] : [])];
        }

        return $found;
    }
}
