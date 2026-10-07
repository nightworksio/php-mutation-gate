<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function count;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\NativeMarkers;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\TighterOptions;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Test\TestsDirectory;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;

use function sprintf;

/**
 * The options the flows build the Infection adapter with: `timeout` and
 * `most`, the floor and the most of each mutant's limit, which the flows
 * write from `timeouts.seconds` and `timeouts.most`, their defaults where
 * none is written; `nativeMarkers`, `refuse` or `allow`, `ignores.native`,
 * `refuse` by default; `tests`, the directories the tests live in, which the
 * flows write from the PHPUnit config, `tests` by default; `staticAnalysis`,
 * `infection` or `gate`, who runs static analysis over the mutants,
 * `infection` by default; and `mutators`, the classes of the registered
 * mutators Infection makes mutants with beside its own, which the flows write
 * (ADR-0021), none by default.
 */
final readonly class Setup
{
    /** The option that says who runs static analysis over the mutants, which the flows write. */
    public const string STATIC_ANALYSIS = 'staticAnalysis';

    /** The option that holds `timeouts.seconds`, which the flows write. */
    public const string TIMEOUT = 'timeout';

    /** The option that holds `timeouts.most`, Infection's own `timeout`, which the flows write. */
    public const string MOST = 'most';

    /** The option that holds the registered mutators' classes, which the flows write. */
    public const string MUTATORS = 'mutators';

    /** The option that holds the directories the tests are in, which the flows write from the PHPUnit config. */
    public const string TESTS = 'tests';

    private const string MARKERS = 'nativeMarkers';

    private function __construct(
        private Paths $tests,
        private LimitBounds $bounds,
        private bool $nativeMarkersAllowed,
        private StaticAnalysis $analysis,
        private Bridges $bridges,
    ) {
    }

    public static function of(Options $options): self|Invalid
    {
        $tests = $options->paths(Key::of(self::TESTS));
        $timeout = $options->number(Key::of(self::TIMEOUT));
        $most = $options->number(Key::of(self::MOST));
        $tighter = TighterOptions::read($options);
        $allowed = self::allowedIn($options->text(Key::of(self::MARKERS)));
        $analysis = self::analysisIn($options->text(Key::of(self::STATIC_ANALYSIS)));
        $mutators = $options->texts(Key::of(self::MUTATORS));

        return match (true) {
            $tests instanceof Problem => Invalid::because($tests),
            $timeout instanceof Problem => Invalid::because($timeout),
            $most instanceof Problem => Invalid::because($most),
            $tighter instanceof Problem => Invalid::because($tighter),
            $allowed instanceof Problem => Invalid::because($allowed),
            $analysis instanceof Problem => Invalid::because($analysis),
            $mutators instanceof Problem => Invalid::because($mutators),
            default => new self(
                $tests instanceof Paths && count($tests) > 0 ? $tests : Paths::of(TestsDirectory::conventional()),
                LimitBounds::between(
                    $timeout instanceof NotGiven ? Triage::standard()->limit() : Seconds::of($timeout),
                    $most instanceof NotGiven ? Triage::standard()->most() : Seconds::of($most),
                )->tighterFor($tighter),
                $allowed,
                $analysis,
                self::bridgesTo($mutators instanceof Listed ? [...$mutators] : []),
            ),
        };
    }

    public function tests(): Paths
    {
        return $this->tests;
    }

    /** `timeouts.seconds` and `timeouts.most`, Infection's `timeout`: what each mutant's limit is kept between. */
    public function bounds(): LimitBounds
    {
        return $this->bounds;
    }

    public function allowsNativeMarkers(): bool
    {
        return $this->nativeMarkersAllowed;
    }

    /** Who runs static analysis over the mutants: Infection, unless the flows say the gate does. */
    public function analysis(): StaticAnalysis
    {
        return $this->analysis;
    }

    /** The bridges to the registered mutators the options name, or why Infection cannot make mutants with one. */
    public function bridges(): Bridges
    {
        return $this->bridges;
    }

    /** @param list<string> $classes */
    private static function bridgesTo(array $classes): Bridges
    {
        $enabled = Enabled::named(BuiltinRunner::Infection, ...$classes);

        return $enabled instanceof CannotJudge ? Bridges::refusing($enabled) : Bridges::to($enabled);
    }

    private static function analysisIn(string|NotGiven|Problem $analysis): StaticAnalysis|Problem
    {
        if (! is_string($analysis)) {
            return $analysis instanceof Problem ? $analysis : StaticAnalysis::Infection;
        }

        return StaticAnalysis::tryFrom($analysis) ?? Problem::at(
            self::STATIC_ANALYSIS,
            sprintf('expected "infection" or "gate", got "%s"', $analysis),
        );
    }

    private static function allowedIn(string|NotGiven|Problem $markers): bool|Problem
    {
        if (! is_string($markers)) {
            return $markers instanceof Problem ? $markers : false;
        }

        return match (NativeMarkers::tryFrom($markers)) {
            NativeMarkers::Allow => true,
            NativeMarkers::Refuse => false,
            null => Problem::at(self::MARKERS, sprintf('expected "refuse" or "allow", got "%s"', $markers)),
        };
    }
}
