<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_fill_keys;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_pop;
use function iterator_to_array;

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
     * @param array<string, TestFile>     $files  by path
     * @param array<string, list<string>> $byName each support file's path, by each name it declares
     * @param list<string>                $always the paths in every key
     */
    private function __construct(private array $files, private array $byName, private array $always)
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
        $seeds = self::valuesOf($canaries);

        foreach ($files as $file) {
            $path = $file->fingerprint()->path()->value();
            $byPath[$path] = $file;
            $byName = self::withDeclarationsOf($file, $byName);
            $seeds = self::isInEveryKey($file, $known) ? [...$seeds, $path] : $seeds;
        }

        $unseeded = new self($byPath, $byName, []);

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
        $read = [...$this->always, ...$this->reachedFrom(self::valuesOf($judges))];

        return Paths::of(...array_map(Path::of(...), $read));
    }

    public function digestOf(Path $path): Digest|Missing
    {
        return array_key_exists($path->value(), $this->files)
            ? $this->files[$path->value()]->fingerprint()->digest()
            : Missing::at($path);
    }

    /**
     * @param  array<string, list<string>> $byName
     * @return array<string, list<string>>
     */
    private static function withDeclarationsOf(TestFile $file, array $byName): array
    {
        foreach ($file->role() === Role::Support ? $file->php()->declares() : [] as $name) {
            $byName[$name][] = $file->fingerprint()->path()->value();
        }

        return $byName;
    }

    private static function isInEveryKey(TestFile $file, Paths $known): bool
    {
        return $file->role() === Role::Loaded
            || ($file->role() === Role::TestCase && ! $known->has($file->fingerprint()->path()));
    }

    /** @return list<string> */
    private static function valuesOf(Paths $paths): array
    {
        return array_map(
            static fn(Path $path): string => $path->value(),
            iterator_to_array($paths, preserve_keys: false),
        );
    }

    /**
     * These paths, and every support file they name, transitively.
     *
     * @param  list<string> $from
     * @return list<string>
     */
    private function reachedFrom(array $from): array
    {
        $seen = array_fill_keys($from, true);
        $pending = $from;

        while ($pending !== []) {
            foreach ($this->supportNamedBy(array_pop($pending)) as $found) {
                $pending = array_key_exists($found, $seen) ? $pending : [...$pending, $found];
                $seen[$found] = true;
            }
        }

        return array_map(strval(...), array_keys($seen));
    }

    /** @return list<string> */
    private function supportNamedBy(string $path): array
    {
        $found = [];

        foreach (array_key_exists($path, $this->files) ? $this->files[$path]->php()->names() : [] as $name) {
            $found = [...$found, ...(array_key_exists($name, $this->byName) ? $this->byName[$name] : [])];
        }

        return $found;
    }
}
