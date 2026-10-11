<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function array_is_list;
use function array_map;
use function array_merge;
use function array_pop;
use function array_slice;
use function explode;
use function file_get_contents;
use function implode;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function json_validate;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\WholeNumber;

use function rawurldecode;
use function rawurlencode;
use function sprintf;
use function unlink;

/**
 * What a mutant's own process records of itself, one to a line in a file of
 * that mutant's own beside the results file: that it had loaded the original
 * file before the mutant was in place, as the record Pest's own process
 * writes for it alone; the arguments Pest started it with, as that record, a
 * space and their JSON, encoded as a test is; and each test that failed or
 * errored, as that record,
 * a space, the test, encoded so a data set's name that holds a line break
 * stays on its line, a space, and where it stood (see Placed). The mutant's process
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

    /** What parts a line: the record before it, the test after, and where the test stood after that. */
    private const string SEPARATOR = ' ';

    /** A killer's line: the record, the test, and where it stood. */
    private const string KILLER = '%s %s %s%s';

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

    /** The line that says the process had loaded the original file before the mutant was in place. */
    public static function preloaded(): string
    {
        return sprintf('%s%s', RecordEvent::Preloaded->value, self::LINE);
    }

    /**
     * The line that holds the arguments Pest started the process with.
     *
     * @param list<string> $arguments
     */
    public static function arguments(array $arguments): string
    {
        return sprintf(
            '%s%s%s%s',
            RecordEvent::Arguments->value,
            self::SEPARATOR,
            rawurlencode(json_encode($arguments, JSON_THROW_ON_ERROR)),
            self::LINE,
        );
    }

    /** The line that says how many tests the process ran. */
    public static function ran(int $tests): string
    {
        return sprintf('%s%s%d%s', RecordEvent::Ran->value, self::SEPARATOR, $tests, self::LINE);
    }

    /**
     * The line that names a test that failed or errored, by the record Pest's
     * own process writes for it, and where it stood in the order the process
     * started its tests in.
     */
    public static function line(RecordEvent $event, string $test, Placed $placed): string
    {
        return sprintf(self::KILLER, $event->value, rawurlencode($test), $placed->written(), self::LINE);
    }

    /**
     * The record of each line of a file, for the mutated copy its mutant ran
     * on, in the order they were written, and the file removed. A last line
     * with no line break after it, as a process stopped while it wrote leaves
     * one, records nothing, nor does a line this class does not write. None
     * where there is no file.
     *
     * @return list<string>
     */
    public static function taken(string $file, string $mutated): array
    {
        if (! is_file($file)) {
            return [];
        }

        $text = file_get_contents($file);
        unlink($file);

        if (! is_string($text)) {
            return [];
        }
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
        $fields = explode(self::SEPARATOR, $line);
        $test = implode(self::SEPARATOR, array_slice($fields, 1, 1));
        $where = Placed::read(implode(self::SEPARATOR, array_slice($fields, 2)));
        $named = rawurldecode($test);

        return match (RecordEvent::tryFrom($fields[0])) {
            RecordEvent::Killed => $where instanceof Placed ? [RecordLine::killed($mutated, $named, $where)] : [],
            RecordEvent::Errored => $where instanceof Placed ? [RecordLine::errored($mutated, $named, $where)] : [],
            RecordEvent::Preloaded => [RecordLine::preloaded($mutated)],
            RecordEvent::Ran => WholeNumber::isDigits($test) ? [RecordLine::ran($mutated, (int) $test)] : [],
            RecordEvent::Arguments => self::startedWith($mutated, $named),
            default => [],
        };
    }

    /**
     * The arguments record a line's JSON holds, where it holds a list of
     * strings; none otherwise.
     *
     * @return list<string>
     */
    private static function startedWith(string $mutated, string $json): array
    {
        $decoded = json_validate($json) ? json_decode($json) : null;
        $words = [];

        foreach (is_array($decoded) && array_is_list($decoded) ? $decoded : [null] as $argument) {
            if (! is_string($argument)) {
                return [];
            }

            $words[] = $argument;
        }

        return [RecordLine::arguments($mutated, $words)];
    }
}
