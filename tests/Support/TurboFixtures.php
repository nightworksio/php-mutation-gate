<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;
use function hash;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\NamedFiles;

use function sprintf;

/** Projects an accelerator's contract is run over: a name graph, digests, what every entry reads, and tests. */
final class TurboFixtures
{
    /** How many source files {@see wide()} holds. */
    private const int FILES = 300;

    /** How many test files {@see wide()} holds. */
    private const int TESTS = 60;

    /**
     * A project: its name graph, each file's digest, what every entry reads,
     * and each test file with the files its tests executed.
     *
     * @param  array<string, list<string>> $declares
     * @param  array<string, list<string>> $mentions
     * @param  array<array-key, string>    $digests
     * @param  list<string>                $always
     * @param  array<array-key, list<string>> $executed
     * @return array{NamedFiles, Fingerprints, Paths, list<array{Path, Paths}>}
     */
    public static function project(array $declares, array $mentions, array $digests, array $always, array $executed): array
    {
        $fingerprints = [];

        foreach ($digests as $path => $digest) {
            $fingerprints[] = Fingerprint::of(Path::of((string) $path), Digest::of($digest));
        }

        $entries = [];

        foreach ($executed as $test => $ran) {
            $entries[] = [Path::of((string) $test), self::paths(...$ran)];
        }

        return [NamedFiles::byName($declares, $mentions), Fingerprints::of(...$fingerprints), self::paths(...$always), $entries];
    }

    /**
     * Many files, each naming two others by a fixed rule, and tests that
     * executed one each.
     *
     * @return array{NamedFiles, Fingerprints, Paths, list<array{Path, Paths}>}
     */
    public static function wide(): array
    {
        $declares = [];
        $mentions = [];
        $digests = [];
        $executed = [];

        for ($file = 0; $file < self::FILES; $file++) {
            $path = self::source($file);
            $declares[$path] = [self::className($file)];
            $mentions[$path] = [self::className(($file * 7 + 3) % self::FILES), self::className(($file * 13 + 5) % self::FILES)];
            $digests[$path] = hash('sha256', $path);
        }

        for ($test = 0; $test < self::TESTS; $test++) {
            $path = sprintf('tests/Unit/File%dTest.php', $test);
            $mentions[$path] = [self::className($test * 5)];
            $digests[$path] = hash('sha256', $path);
            $executed[$path] = [self::source($test * 5)];
        }

        return self::project($declares, $mentions, $digests, ['tests/Pest.php'], $executed);
    }

    private static function paths(string ...$paths): Paths
    {
        return Paths::of(...array_map(Path::of(...), $paths));
    }

    private static function source(int $file): string
    {
        return sprintf('src/Area%d/File%d.php', $file % 7, $file);
    }

    private static function className(int $file): string
    {
        return sprintf('App\\C%d', $file);
    }
}
