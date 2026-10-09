<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Mago;

use function array_filter;
use function array_map;
use function explode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\ChildProcess;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function sprintf;

/** The files Mago analyses, as `mago list-files -0` prints them, each ended by a NUL. */
final readonly class FileList
{
    private const string UNLISTED = 'Mago could not list the files it analyses (%s).';

    /**
     * The files a listing printed; or why it cannot say: it did not run, it
     * failed, or it was stopped at a check's limit, which does not finish
     * the check.
     */
    public static function read(ChildProcess|CannotJudge $listed, Seconds|Unlimited $limit): Paths|CannotJudge
    {
        return match (true) {
            $listed instanceof ChildProcess && $listed->wasStopped() && $limit instanceof Seconds
                => CheckLimit::from($limit)->unfinished(),
            $listed instanceof CannotJudge || $listed->exit() !== 0 => CannotJudge::because(sprintf(
                self::UNLISTED,
                $listed instanceof CannotJudge ? $listed->why() : $listed->said(),
            )),
            default => Paths::of(...array_map(
                Path::of(...),
                array_filter(explode("\0", $listed->output()), static fn(string $file): bool => $file !== ''),
            )),
        };
    }
}
