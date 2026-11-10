<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

use function implode;

use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Outbreak as OutbreakSettings;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * `outbreak.so`, the extension the package ships for the PHP that runs the
 * gate and its tests (ADR-0130), and what the settings ask of it: to prove
 * mutants equivalent before they run, under `outbreak.equivalence`, to
 * count the forks of a split stream that change no state, under
 * `outbreak.forkStates` (H5), and to see a stream's test touch
 * nondeterminism, under `outbreak.recheckTouchedKills`.
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

    private function __construct(
        private string $path,
        private bool $equivalence,
        private bool $forkStates,
        private Seconds $timeout,
        private bool $recheckTouchedKills = false,
    ) {
    }

    /**
     * The extension the settings beneath a runner's options ask for, from
     * the package's directory for this machine and PHP; none where
     * `outbreak.enabled` is off, neither `outbreak.equivalence` nor
     * `outbreak.forkStates` asks for it, or none ships for this PHP.
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
        );
        $asked = $settings->equivalence() || $settings->forkStates() || $settings->recheckTouchedKills();
        $wanted = $settings->enabled() && $asked;
        $path = $wanted ? Bundled::extensionIn($shipped) : NotGiven::value();

        return $path instanceof NotGiven
            ? $path
            : new self(
                $path,
                $settings->equivalence(),
                $settings->forkStates(),
                $settings->timeout(),
                $settings->recheckTouchedKills(),
            );
    }

    /** The extension at this path, asked to prove equivalence, count fork states and recheck touched kills as said. */
    public static function at(
        string $path,
        bool $equivalence,
        bool $forkStates,
        Seconds $timeout,
        bool $recheckTouchedKills,
    ): self {
        return new self($path, $equivalence, $forkStates, $timeout, $recheckTouchedKills);
    }

    /**
     * The prover of a run whose results are in this directory, where
     * `outbreak.equivalence` asks for one; none where it does not.
     */
    public function prover(string $directory): ProvenBeforeRun|NotGiven
    {
        return $this->equivalence
            ? new ProvenBeforeRun(new OpArrays(
                PHP_BINARY,
                $this->path,
                sprintf(self::PROGRAMS, $directory),
                $this->timeout->seconds(),
            ))
            : NotGiven::value();
    }

    /**
     * The extension's path, which a stream loads where it counts fork states
     * or rechecks touched kills, and what it watches there; none where it
     * does neither.
     *
     * @return array{string, string}|NotGiven
     */
    public function forStreams(): array|NotGiven
    {
        $watched = [
            ...$this->forkStates ? [self::STATES] : [],
            ...$this->recheckTouchedKills ? [self::TOUCHES] : [],
        ];

        return $watched === [] ? NotGiven::value() : [$this->path, implode(',', $watched)];
    }
}
