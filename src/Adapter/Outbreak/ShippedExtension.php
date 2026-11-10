<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function implode;
use function is_int;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Outbreak as OutbreakSettings;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * `outbreak.so`, the extension the package ships for the PHP that runs the
 * gate and its tests (ADR-0130), and what the settings ask of it: to prove
 * mutants equivalent before they run, under `outbreak.equivalence`, to
 * count the forks of a split stream that change no state, under
 * `outbreak.forkStates` (H5), to see a stream's test touch
 * nondeterminism, under `outbreak.recheckTouchedKills`, to record the lines
 * a stream's tests execute, under `outbreak.lines`, and to count a stream's
 * steps, under `outbreak.steps` (ADR-0147).
 *
 * @internal
 */
final readonly class ShippedExtension
{
    /** Where a run's prover writes the programs it compiles, beside its results. */
    private const string PROGRAMS = '%s/outbreak-programs';

    /** What a stream's extension watches, as its observer reads it. */
    private const string STATES = 'states';

    private const string TOUCHES = 'touches';

    private const string LINES = 'lines';

    private const string STEPS = 'steps';

    private function __construct(
        private string $path,
        private OutbreakSettings $settings,
    ) {
    }

    /**
     * The extension the settings beneath a runner's options ask for, from
     * the package's directory for this machine and PHP; none where
     * `outbreak.enabled` is off, no setting asks for it, or none ships for
     * this PHP.
     */
    public static function beneath(Options $options, string $shipped): self|NotGiven
    {
        $beneath = $options->object(Key::of(OutbreakSettings::OPTION));
        $read = $beneath instanceof Options ? $beneath : Options::none();
        $settings = OutbreakSettings::of(
            enabled: $read->flag(Key::of(OutbreakSettings::ENABLED)) !== false,
            equivalence: $read->flag(Key::of(OutbreakSettings::EQUIVALENCE)) !== false,
            forkStates: $read->flag(Key::of(OutbreakSettings::FORK_STATES)) === true,
            recheckTouchedKills: $read->flag(Key::of(OutbreakSettings::RECHECK_TOUCHED_KILLS)) !== false,
            lines: $read->flag(Key::of(OutbreakSettings::LINES)) !== false,
            steps: $read->flag(Key::of(OutbreakSettings::STEPS)) !== false,
            stepFloor: self::floorOf($read->integer(Key::of(OutbreakSettings::STEP_FLOOR))),
        );
        $asked = $settings->equivalence()
            || $settings->forkStates()
            || $settings->recheckTouchedKills()
            || $settings->lines()
            || $settings->steps();
        $wanted = $settings->enabled() && $asked;
        $path = $wanted ? Bundled::extensionIn($shipped) : NotGiven::value();

        return $path instanceof NotGiven ? $path : new self($path, $settings);
    }

    /** Whether a stream that records its tests' lines records them with this extension. */
    public function recordsLines(): bool
    {
        return $this->settings->lines();
    }

    /** The least steps a child may take past its parent's share, where a stream counts steps; none where it does not. */
    public function stepFloor(): int|NotGiven
    {
        return $this->settings->steps() ? $this->settings->stepFloor() : NotGiven::value();
    }

    /**
     * The prover of a run whose results are in this directory, where
     * `outbreak.equivalence` asks for one; none where it does not.
     */
    public function prover(string $directory): ProvenBeforeRun|NotGiven
    {
        return $this->settings->equivalence()
            ? new ProvenBeforeRun(new OpArrays(
                PHP_BINARY,
                $this->path,
                sprintf(self::PROGRAMS, $directory),
                $this->settings->timeout()->seconds(),
            ))
            : NotGiven::value();
    }

    /**
     * The extension's path, which a stream loads where it counts fork
     * states, rechecks touched kills, records lines or counts steps, and
     * what it watches there; none where it does none. It records lines only
     * where the stream records them.
     *
     * @return array{string, string}|NotGiven
     */
    public function forStreams(bool $recording): array|NotGiven
    {
        $watched = [
            ...$this->settings->forkStates() ? [self::STATES] : [],
            ...$this->settings->recheckTouchedKills() ? [self::TOUCHES] : [],
            ...$recording && $this->settings->lines() ? [self::LINES] : [],
            ...$this->settings->steps() ? [self::STEPS] : [],
        ];

        return $watched === [] ? NotGiven::value() : [$this->path, implode(',', $watched)];
    }

    /** The step floor beneath a runner's options, where it holds one. */
    private static function floorOf(int|NotGiven|Problem $floor): int|Absent
    {
        return is_int($floor) ? $floor : new Absent();
    }
}
