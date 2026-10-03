<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function array_map;
use function array_merge;
use function array_pad;
use function array_pop;
use function explode;
use function file_get_contents;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\File\Digest;

use function rawurldecode;
use function rawurlencode;
use function sprintf;
use function unlink;

/**
 * The tests that failed or errored in a mutant's own process, one to a line
 * in a file of that mutant's own beside the results file: the record Pest's
 * own process writes for it, a space, and the test, encoded so a data set's
 * name that holds a line break stays on its line. The mutant's process
 * writes it; Pest's own process takes it once the mutant has ended and writes
 * each line as its record. So the results file has one writer, which runs no
 * mutated code: in a mutant's own process the plugin's own classes can be the
 * mutant, as they are in the gate's run on this package, and a record they
 * wrote could be one the adapter refuses.
 */
final readonly class KillerFile
{
    /** The file beside a results file: the results file's name, then the digest of the mutated copy. */
    private const string FILE = '%s.%s.killers';

    private const string LINE = "\n";

    /** What parts a line: the record before it, the test after. */
    private const string SEPARATOR = ' ';

    /**
     * Where the process of the mutant Pest serves a mutated copy for writes
     * its killers, beside a results file.
     *
     * @return non-empty-string
     */
    public static function beside(string $results, string $mutated): string
    {
        return sprintf(self::FILE, $results, Digest::sha256Of($mutated)->value());
    }

    /**
     * Every mutant's killer file beside a results file, as a pattern `glob()` matches.
     *
     * @return non-empty-string
     */
    public static function everyBeside(string $results): string
    {
        return sprintf(self::FILE, $results, '*');
    }

    /** The line that names a test that failed or errored, by the record Pest's own process writes for it. */
    public static function line(RecordEvent $event, string $test): string
    {
        return sprintf('%s%s%s%s', $event->value, self::SEPARATOR, rawurlencode($test), self::LINE);
    }

    /**
     * The record of each test a file names, for the mutated copy its mutant
     * ran on, in the order they were written, and the file removed. A last
     * line with no line break after it, as a process stopped while it wrote
     * leaves one, names none, nor does a line that names no test as failed
     * or errored. None where there is no file.
     *
     * @return list<string>
     */
    public static function taken(string $file, string $mutated): array
    {
        $text = is_file($file) ? file_get_contents($file) : false;

        if (! is_string($text)) {
            return [];
        }

        unlink($file);
        $lines = explode(self::LINE, $text);
        array_pop($lines);

        return array_merge(...array_map(static fn(string $line): array => self::recordOf($line, $mutated), $lines));
    }

    /**
     * The record a line names, or none.
     *
     * @return list<string>
     */
    private static function recordOf(string $line, string $mutated): array
    {
        [$mark, $test] = array_pad(explode(self::SEPARATOR, $line, 2), 2, '');

        return match (RecordEvent::tryFrom($mark)) {
            RecordEvent::Killed => [RecordLine::killed($mutated, rawurldecode($test))],
            RecordEvent::Errored => [RecordLine::errored($mutated, rawurldecode($test))],
            default => [],
        };
    }
}
