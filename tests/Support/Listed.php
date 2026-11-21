<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;

use Closure;

use function explode;
use function intval;
use function is_array;

use NightWorksIO\MutationGate\Adapter\Process\LiveProcesses;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessListing;
use NightWorksIO\MutationGate\Core\Runner\RunningProcesses;
use NightWorksIO\MutationGate\Core\Runner\Signal;

use function preg_split;
use function sprintf;
use function trim;

/**
 * The processes running, as a test names them: each listing what a closure
 * answers, or always the same processes, named by ids alone, one process to
 * a line: its id, its parent and its group, the group its own id where left
 * out; each signal sent for real, or told to a closure in its place.
 */
final readonly class Listed implements RunningProcesses
{
    private const string ROW = "%d %d %d 0 00:00 sh\n";

    /**
     * @param Closure(): ProcessListing                  $listing
     * @param (Closure(int, Signal): bool)|NotGiven $sent    what takes each signal in place of the process
     */
    public function __construct(private Closure $listing, private Closure|NotGiven $sent = new NotGiven())
    {
    }

    /** These processes, at every listing. */
    public static function always(string $rows): self
    {
        return new self(static fn(): ProcessListing => self::of($rows));
    }

    /** A listing of these processes. */
    public static function of(string $rows): ProcessListing
    {
        $printed = '';

        foreach (explode("\n", trim($rows)) as $row) {
            $words = $row === '' ? false : preg_split('/\s+/', trim($row));
            $ids = is_array($words) ? array_map(intval(...), $words) : [];
            $printed .= $ids === [] ? '' : sprintf(self::ROW, $ids[0], $ids[1] ?? 0, $ids[2] ?? $ids[0]);
        }

        return ProcessListing::printed($printed);
    }

    public function listed(): ProcessListing
    {
        return ($this->listing)();
    }

    /**
     * The same processes, each signal told to this closure and not sent.
     *
     * @param Closure(int, Signal): bool $sent
     */
    public function sendingTo(Closure $sent): self
    {
        return new self($this->listing, $sent);
    }

    public function signalledUnspared(int $target, Signal $signal): bool
    {
        return $this->sent instanceof Closure ? ($this->sent)($target, $signal) : new LiveProcesses()->signalledUnspared($target, $signal);
    }
}
