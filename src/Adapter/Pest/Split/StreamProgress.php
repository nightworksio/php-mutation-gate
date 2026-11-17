<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_keys;
use function count;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function max;

use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match;
use function sprintf;
use function str_contains;
use function trim;
use function unlink;

/**
 * How far a run's split streams have come, before Pest gives any of their
 * mutants its result: each mutant a child killed, ended or timed out, as the
 * streams' results files say, the latest pass whose streams wrote one, and
 * how many of its streams are still marked running (see ChildPlaces), found
 * by the stream directory a run names beside its results file for the
 * gate's log.
 *
 * @internal
 */
final readonly class StreamProgress
{
    /** The file beside a run's results naming its stream directory. */
    private const string NAMED = '%s.streams';

    /** Every stream's results in that directory. */
    private const string RESULTS = '%s/results-*.jsonl';

    /** A stream's results file, by its pass. */
    private const string OF_PASS = '~/results-(\d+)-\d+\.jsonl$~';

    /** What a line a child's end is written in holds, which no parent's line does. */
    private const string MUTANT = '"m":';

    /** The stream directory, named beside the run's results where the gate names a file. */
    public static function named(string|false $results, string $directory): void
    {
        if (is_string($results) && $results !== '') {
            file_put_contents(sprintf(self::NAMED, $results), $directory);
        }
    }

    /** The name beside the run's results removed, once the streams' verdicts are given. */
    public static function unnamed(string|false $results): void
    {
        $named = is_string($results) ? sprintf(self::NAMED, $results) : '';

        if ($named !== '' && is_file($named)) {
            unlink($named);
        }
    }

    /** How far the streams of the run with these results came; none where it names none. */
    public static function seen(string $results): StreamsSeen
    {
        $named = sprintf(self::NAMED, $results);
        $directory = is_file($named) ? trim((string) file_get_contents($named)) : '';
        $files = $directory === '' ? false : glob(sprintf(self::RESULTS, $directory));

        if (! is_array($files) || $files === []) {
            return StreamsSeen::none();
        }

        $decided = [];
        $passes = [];

        foreach ($files as $file) {
            $decided += self::decidedIn((string) file_get_contents($file));
            $passes[] = preg_match(self::OF_PASS, $file, $pass) === 1 ? (int) $pass[1] : 0;
        }

        $latest = max($passes);

        return new StreamsSeen(
            count($decided),
            $latest,
            count(array_keys($passes, $latest, strict: true)),
            ChildPlaces::runningIn($directory),
        );
    }

    /**
     * The mutants one stream's results say a child decided.
     *
     * @return array<string, true>
     */
    private static function decidedIn(string $text): array
    {
        $decided = [];

        foreach (explode("\n", $text) as $line) {
            $mutant = self::decidedBy($line);

            if ($mutant !== false) {
                $decided[$mutant] = true;
            }
        }

        return $decided;
    }

    /** The mutant a line says a child decided: killed, ended or timed out; false where it says none. */
    private static function decidedBy(string $line): string|false
    {
        $read = str_contains($line, self::MUTANT) ? json_decode($line, associative: true) : null;
        $stream = is_array($read) ? StreamRead::of($read) : NotGiven::value();
        $end = $stream instanceof StreamRead ? $stream->childEnd() : false;

        return $stream instanceof StreamRead && $end instanceof ChildEnd && $end !== ChildEnd::Passed
            ? $stream->mutant
            : false;
    }
}
