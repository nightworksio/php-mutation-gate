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
use NightWorksIO\MutationGate\Core\Mutant\PrintsDeclarations;
use NightWorksIO\MutationGate\Core\Mutant\ProvesBeforeRun;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\RunExtension;

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
final readonly class ShippedExtension implements RunExtension
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
        private PrintsDeclarations $printer,
    ) {
    }

    /**
     * The extension the settings beneath a runner's options ask for, from
     * the package's directory for this machine and PHP; none where
     * `outbreak.enabled` is off, no setting asks for it, or none ships for
     * this PHP.
     */
    public static function beneath(Options $options, string $shipped, PrintsDeclarations $printer): self|NotGiven
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

        return $path instanceof NotGiven ? $path : new self($path, $settings, $printer);
    }

    public function recordsLines(): bool
    {
        return $this->settings->lines();
    }

    public function stepFloor(): int|NotGiven
    {
        return $this->settings->steps() ? $this->settings->stepFloor() : NotGiven::value();
    }

    public function prover(string $directory): ProvesBeforeRun|NotGiven
    {
        return $this->settings->equivalence()
            ? new ProvenBeforeRun($this->compiler($directory), $this->printer)
            : NotGiven::value();
    }

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

    /** The compiler of a run whose results are in this directory, writing its programs beside them. */
    private function compiler(string $directory): OpArrays
    {
        $programs = sprintf(self::PROGRAMS, $directory);

        return new OpArrays(PHP_BINARY, $this->path, $programs, $this->settings->timeout()->seconds());
    }

    /** The step floor beneath a runner's options, where it holds one. */
    private static function floorOf(int|NotGiven|Problem $floor): int|Absent
    {
        return is_int($floor) ? $floor : new Absent();
    }
}
