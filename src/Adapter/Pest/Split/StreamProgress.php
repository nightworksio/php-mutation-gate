<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function count;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;

use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;
use function trim;
use function unlink;

/**
 * How many mutants a run's split streams have decided so far, before Pest
 * gives any of them its result: each one a child killed, ended or timed out,
 * as the streams' results files say, found by the stream directory a run
 * names beside its results file for the gate's log.
 *
 * @internal
 */
final readonly class StreamProgress
{
    /** The file beside a run's results naming its stream directory. */
    private const string NAMED = '%s.streams';

    /** Every stream's results in that directory. */
    private const string RESULTS = '%s/results-*.jsonl';

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

    /** How many mutants the streams of the run with these results decided so far; none where it names none. */
    public static function decided(string $results): int
    {
        $named = sprintf(self::NAMED, $results);
        $directory = is_file($named) ? trim((string) file_get_contents($named)) : '';
        $files = $directory === '' ? [] : glob(sprintf(self::RESULTS, $directory));
        $decided = [];

        foreach (is_array($files) ? $files : [] as $file) {
            $decided += self::decidedIn((string) file_get_contents($file));
        }

        return count($decided);
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
            $read = json_decode($line, associative: true);
            $stream = is_array($read) ? StreamRead::of($read) : NotGiven::value();
            $end = $stream instanceof StreamRead ? $stream->childEnd() : false;

            if ($stream instanceof StreamRead && $end instanceof ChildEnd && $end !== ChildEnd::Passed) {
                $decided[$stream->mutant] = true;
            }
        }

        return $decided;
    }
}
