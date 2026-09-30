<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use function array_key_exists;
use function array_values;
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
 *
 * What each file names is looked up once, when the files are read, so
 * following it from any file is a walk over what it names.
 */
final readonly class Tests
{
    /**
     * @param array<string, TestFile>   $files  by path
     * @param array<string, list<Path>> $naming the support each file names, each once, by the file's path
     * @param Paths                     $always the files in every key
     * @param Paths                     $cases  every file of test cases
     */
    private function __construct(
        private array $files,
        private array $naming,
        private Paths $always,
        private Paths $cases,
    ) {
    }

    /**
     * @param Paths $known    the test files the coverage map knows
     * @param Paths $canaries the canary group's test files where the Pest patch is on, and none where it is off
     */
    public static function of(TestFiles $files, Paths $known, Paths $canaries): self
    {
        $byPath = [];
        $byName = [];
        $seeds = [];
        $cases = [];

        foreach ($files as $file) {
            $path = $file->fingerprint()->path();
            $byPath[$path->value()] = $file;
            $byName = self::withDeclarationsOf($file, $byName);

            if (self::isInEveryKey($file, $known)) {
                $seeds[] = $path;
            }

            if ($file->role() === Role::TestCase) {
                $cases[] = $path;
            }
        }

        $naming = [];

        foreach ($byPath as $file) {
            $naming[$file->fingerprint()->path()->value()] = self::supportNamedBy($file, $byName);
        }

        $unseeded = new self($byPath, $naming, Paths::none(), Paths::none());

        return new self(
            $byPath,
            $naming,
            $unseeded->reachedFrom(Paths::of(...$canaries, ...$seeds)),
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

    /**
     * The support a file names, each once, in the order it names them.
     *
     * @param  array<string, list<Path>> $byName each support file, by each name it declares
     * @return list<Path>
     */
    private static function supportNamedBy(TestFile $file, array $byName): array
    {
        $found = [];

        foreach ($file->php()->names() as $name) {
            foreach (array_key_exists($name, $byName) ? $byName[$name] : [] as $support) {
                $found += [$support->value() => $support];
            }
        }

        return array_values($found);
    }

    /** These files, and every support file they name, transitively, in the order they were reached. */
    private function reachedFrom(Paths $from): Paths
    {
        $queue = [...$from];
        $reached = [];

        foreach ($queue as $path) {
            $reached[$path->value()] = $path;
        }

        $at = 0;

        while ($at < count($queue)) {
            $named = array_key_exists($queue[$at]->value(), $this->naming) ? $this->naming[$queue[$at]->value()] : [];

            foreach ($named as $found) {
                if (! array_key_exists($found->value(), $reached)) {
                    $reached[$found->value()] = $found;
                    $queue[] = $found;
                }
            }

            $at++;
        }

        return Paths::of(...array_values($reached));
    }
}
