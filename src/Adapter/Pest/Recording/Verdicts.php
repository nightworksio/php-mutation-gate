<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function array_filter;
use function array_flip;
use function array_key_exists;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use Pest\Mutate\MutationTest;

use function rename;
use function sprintf;
use function usleep;

/**
 * The gate's verdicts on the mutants a patched Pest planned (ADR-0020):
 * once the plugin has written every planned mutant, it waits for this file
 * beside the results, in which the gate names the mutated copy of each
 * mutant static analysis rejected, one to a line, and none where it
 * rejected none. Pest then runs none of them: each is tested without a
 * run, and the gate reads it as killed by static analysis. Beside it, the
 * gate names the copy of each mutant another subsumes, which Pest runs after
 * the others (ADR-0079).
 *
 * @internal
 */
final class Verdicts
{
    private const string LINE = "\n";

    private const string HELD = '%s.held';

    /** @var array<string, int> the mutated copies the gate rejected, as keys */
    private static array $rejected = [];

    /** @var array<string, int> the mutated copies the gate holds back, as keys */
    private static array $held = [];

    /** Where a run's verdicts are written, beside its results. */
    public static function beside(string $results): string
    {
        return sprintf('%s.verdicts', $results);
    }

    /** Where the copies the gate holds back are named, beside its verdicts. */
    public static function heldBeside(string $verdicts): string
    {
        return sprintf(self::HELD, $verdicts);
    }

    /**
     * Writes the mutated copies the gate holds back, then those it rejected
     * to the file, and names the file. Each is written whole beside, then
     * moved in, so the plugin, which reads them once the verdicts are there,
     * never reads part of either.
     *
     * @param list<string> $rejected
     * @param list<string> $held
     */
    public static function write(string $file, array $rejected, array $held): string
    {
        self::whole(self::heldBeside($file), $held);
        self::whole($file, $rejected);

        return $file;
    }

    /**
     * Waits for the gate's verdicts in this file, this long at most; then
     * keeps the copies it rejected and those it holds back, none where it
     * never wrote the file.
     */
    public static function await(string $file, Seconds $within): void
    {
        Polling::wait($within, static fn(): bool => is_file($file), usleep(...));
        self::$rejected = self::read($file);
        self::$held = self::read(self::heldBeside($file));
    }

    /** Whether the gate rejected the mutant that serves this mutated copy. */
    public static function rejects(string $mutated): bool
    {
        return array_key_exists($mutated, self::$rejected);
    }

    /**
     * Pest's mutation tests in the order they run: each the gate holds back
     * after the others, each part in the order Pest gave.
     *
     * @param  list<MutationTest> $tests
     * @return list<MutationTest>
     */
    public static function ordered(array $tests): array
    {
        $isHeld = static fn(MutationTest $test): bool => array_key_exists(
            $test->mutation->modifiedSourcePath,
            self::$held,
        );

        return [
            ...array_filter($tests, static fn(MutationTest $test): bool => ! $isHeld($test)),
            ...array_filter($tests, $isHeld),
        ];
    }

    /** @param list<string> $copies */
    private static function whole(string $file, array $copies): void
    {
        $part = sprintf('%s.part', $file);
        file_put_contents($part, implode(self::LINE, $copies));
        rename($part, $file);
    }

    /** @return array<string, int> the copies a file names, as keys; none where there is no such file */
    private static function read(string $file): array
    {
        $text = is_file($file) ? file_get_contents($file) : null;

        return is_string($text)
            ? array_flip(array_filter(explode(self::LINE, $text), static fn(string $line): bool => $line !== ''))
            : [];
    }
}
