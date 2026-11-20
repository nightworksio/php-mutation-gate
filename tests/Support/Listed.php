<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;

use Closure;

use function explode;
use function intval;
use function is_array;

use NightWorksIO\MutationGate\Core\Runner\ProcessListing;
use NightWorksIO\MutationGate\Core\Runner\RunningProcesses;

use function preg_split;
use function sprintf;
use function trim;

/**
 * The processes running, as a test names them: each listing what a closure
 * answers, or always the same processes, named by ids alone, one process to
 * a line: its id, its parent and its group, the group its own id where left
 * out.
 */
final readonly class Listed implements RunningProcesses
{
    private const string ROW = "%d %d %d 0 00:00 sh\n";

    /** @param Closure(): ProcessListing $listing */
    public function __construct(private Closure $listing)
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
}
