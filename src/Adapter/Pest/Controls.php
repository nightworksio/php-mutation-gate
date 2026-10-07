<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_combine;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;

use Closure;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function sprintf;

/**
 * The doubtful kills of a narrowed run, each with the control of its run
 * again with every test file (see Control): the tests that cover it, as
 * Pest's filter selects them, or the tests that judge the run where that
 * filter is too long to pass, as Pest then runs them. They are read off the
 * run's coverage before the run again replaces it. A kill the run again
 * confirms stands only where its control passes; otherwise its tests fail
 * with the file unmutated as well, so they cannot tell the mutant from the
 * original, and it is unjudged.
 */
final readonly class Controls
{
    /** Why a kill confirmed again is unjudged where its control fails. */
    public const string FAILS_UNMUTATED
        = 'Killed, but its covering tests fail as well with the file unmutated, served as its mutant was.';

    /** Why a kill confirmed again is unjudged where its control cannot be read off the run's coverage. */
    private const string UNREAD = 'Killed, but its covering tests cannot be told, so nothing vouches for the kill: %s';

    /**
     * @param array<string, Control> $controls each doubtful kill's control, by its gate id
     * @param string                 $unread   why no kill has a control, where the coverage cannot be read
     */
    private function __construct(private Mutants $doubtful, private array $controls, private string $unread = '')
    {
    }

    /**
     * The doubtful kills' controls, the coverage read only where there are
     * any; none where it cannot be read, and then every kill confirmed again
     * is unjudged, saying why.
     *
     * @param Closure(): (Covering|CannotJudge) $coverage the coverage the run's mutants were selected by
     */
    public static function of(
        Project $project,
        Mutants $doubtful,
        WholeSuite|Group|Filter $judgedBy,
        Closure $coverage,
    ): self {
        if (count($doubtful) === 0) {
            return new self($doubtful, []);
        }

        $covering = $coverage();

        if ($covering instanceof CannotJudge) {
            return new self($doubtful, [], sprintf(self::UNREAD, $covering->why()));
        }

        $controls = [];

        foreach ($doubtful as $mutant) {
            $location = $mutant->location();
            $file = DiskPath::of($project->absolute($location->file()));
            $selection = Selection::of($covering->testsCovering($file, $location->start(), $location->last()));
            $controls[$mutant->id()->key()] = Control::of(
                $location->file(),
                Paths::none(),
                $selection->fits() ? $selection->filter() : $judgedBy,
            );
        }

        return new self($doubtful, $controls);
    }

    public function doubtful(): Mutants
    {
        return $this->doubtful;
    }

    /** The mutants run again, each kill whose control fails unjudged. */
    public function applied(
        Mutants $again,
        AloneRuns $runs,
        MutationRequest $request,
        Seconds|Unlimited $left,
        string $results,
    ): Mutants {
        $killed = [];

        foreach ($again as $mutant) {
            $key = $mutant->id()->key();
            $killed += $mutant->status() === MutantStatus::Killed && array_key_exists($key, $this->controls)
                ? [$key => $this->controls[$key]]
                : [];
        }

        $passed = $killed === []
            ? []
            : array_combine(array_keys($killed), $runs->pass(array_values($killed), $request, $left, $results));
        $unvouched = Reason::that($this->unread === '' ? self::FAILS_UNMUTATED : $this->unread);

        return Mutants::of(...array_map(
            static fn(Mutant $mutant): Mutant => self::stands($mutant, $passed)
                ? $mutant
                : Interpretation::unjudged($mutant, $unvouched),
            [...$again],
        ));
    }

    /**
     * Whether a mutant run again stands as it was found: anything but a kill,
     * and a kill whose control passed.
     *
     * @param array<string, bool> $passed whether each kill's control passed, by its gate id
     */
    private static function stands(Mutant $mutant, array $passed): bool
    {
        $key = $mutant->id()->key();

        return $mutant->status() !== MutantStatus::Killed || (array_key_exists($key, $passed) && $passed[$key]);
    }
}
