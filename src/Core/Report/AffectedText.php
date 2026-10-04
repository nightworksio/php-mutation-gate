<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Format\Inert;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Reach\AffectedTest;
use NightWorksIO\MutationGate\Core\Reach\AffectedTests;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;

use function sprintf;

/**
 * The tests `affected` lists, as a pipe reads them (ADR-0020, decisions 1 and
 * 4): each test file, a line each or each ended by a NUL byte, which no path
 * holds; or each test id, a line each, where PHPUnit can read every one back
 * and every file listed holds some; and the reasons, a line each, for
 * standard error. A CI log reads what the gate prints, so no entry of a list
 * may read there as a command: making it inert would change it, so the list
 * is refused, naming the JSON, which holds every entry as a string. Each
 * reason is one plain line, made inert (Fit, Inert).
 */
final readonly class AffectedText
{
    /** What ends each file of `files0`: a byte no path holds. */
    private const string NUL = "\0";

    private const string NO_IDS
        = '`%s` holds no test the coverage map names, so --format=ids cannot select it: give --format=files.';

    private const string UNREADABLE
        = 'PHPUnit cannot read back the test id %s from --test-id-filter-file: give --format=files.';

    private const string LINE_BREAK
        = 'The test file %s holds a line break, so --format=files cannot list it a line each: give --format=files0.';

    private const string COMMAND = 'The test %s %s would read as a command in a CI log: give --format=json.';

    private const string OWN = '%s: %s';

    /** An entry of a list, and what ends it. */
    private const string ENDED = '%s%s';

    /** The listed test files, in a format that lists files; or why that format cannot list them. */
    public static function files(AffectedTests $tests, AffectedFormat $format): string|CannotJudge
    {
        $lines = $format !== AffectedFormat::Files0;
        $text = '';

        foreach ($tests->tests() as $test) {
            $path = $test->file()->value();

            if ($lines && ! PhpUnitOption::readsBack($path)) {
                return CannotJudge::because(sprintf(self::LINE_BREAK, JsonText::text($path)));
            }

            if (Inert::text($path) !== $path) {
                return CannotJudge::because(sprintf(self::COMMAND, 'file', JsonText::text($path)));
            }

            $text .= sprintf(self::ENDED, $path, $lines ? PhpUnitOption::LINE_END : self::NUL);
        }

        return $text;
    }

    /** The listed tests' ids, a line each; or why PHPUnit could not select them so. */
    public static function ids(AffectedTests $tests): string|CannotJudge
    {
        $text = '';

        foreach ($tests->tests() as $test) {
            $refused = self::idsRefused($test);

            if ($refused instanceof CannotJudge) {
                return $refused;
            }

            foreach ($test->tests() as $id) {
                $text .= sprintf(self::ENDED, $id->value(), PhpUnitOption::LINE_END);
            }
        }

        return $text;
    }

    /**
     * Every reason, a line each, plain and inert: each test file's own, after
     * its path; then each that lists no test; then each that lists every test.
     *
     * @return list<string>
     */
    public static function reasons(AffectedTests $tests): array
    {
        $lines = [];

        foreach ($tests->reached() as $test) {
            foreach ($test->reasons() as $reason) {
                $lines[] = self::line(sprintf(self::OWN, $test->file()->value(), $reason->text()));
            }
        }

        foreach ([$tests->nothingBecause(), $tests->everyBecause()] as $reasons) {
            foreach ($reasons as $reason) {
                $lines[] = self::line($reason->text());
            }
        }

        return $lines;
    }

    /**
     * Why a test file's ids cannot be listed: it holds none the map names, or
     * one PHPUnit cannot read back, or one a CI log would read as a command,
     * the last of them it holds where several are.
     */
    private static function idsRefused(AffectedTest $test): CannotJudge|NotGiven
    {
        $refused = count($test->tests()) === 0
            ? CannotJudge::because(sprintf(self::NO_IDS, Fit::plain($test->file()->value())))
            : NotGiven::value();

        foreach ($test->tests() as $id) {
            $value = $id->value();
            $quoted = JsonText::text($value);
            $refused = match (true) {
                ! PhpUnitOption::readsBack($value) => CannotJudge::because(sprintf(self::UNREADABLE, $quoted)),
                Inert::text($value) !== $value => CannotJudge::because(sprintf(self::COMMAND, 'id', $quoted)),
                default => $refused,
            };
        }

        return $refused;
    }

    /** Text as one plain line, which a CI log reads as no command. */
    private static function line(string $text): string
    {
        return Inert::text(Fit::plain($text));
    }
}
