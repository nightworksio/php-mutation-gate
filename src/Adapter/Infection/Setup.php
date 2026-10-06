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
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestsDirectory;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;

use function sprintf;

/**
 * The options the flows build the Infection adapter with: `timeout`, the
 * seconds each mutant is allowed at most, which the flows write from
 * `timeouts.most`, and its default where none is written; `nativeMarkers`, `refuse` or `allow`, `ignores.native`,
 * `refuse` by default; `tests`, the directories the tests live in, `tests`
 * by default; `staticAnalysis`, `infection` or `gate`, who runs static
 * analysis over the mutants, `infection` by default; and `mutators`, the
 * classes of the registered mutators Infection makes mutants with beside its
 * own, which the flows write (ADR-0021), none by default.
 */
final readonly class Setup
{
    /** The option that says who runs static analysis over the mutants, which the flows write. */
    public const string STATIC_ANALYSIS = 'staticAnalysis';

    /** The option that holds Infection's own `timeout`, `timeouts.most`, which the flows write. */
    public const string TIMEOUT = 'timeout';

    /** The option that holds the registered mutators' classes, which the flows write. */
    public const string MUTATORS = 'mutators';

    private const string TESTS = 'tests';

    private const string MARKERS = 'nativeMarkers';

    private function __construct(
        private Paths $tests,
        private Seconds $cap,
        private bool $nativeMarkersAllowed,
        private StaticAnalysis $analysis,
        private Bridges $bridges,
    ) {
    }

    public static function of(Options $options): self|Invalid
    {
        $tests = $options->paths(Key::of(self::TESTS));
        $timeout = $options->number(Key::of(self::TIMEOUT));
        $allowed = self::allowedIn($options->text(Key::of(self::MARKERS)));
        $analysis = self::analysisIn($options->text(Key::of(self::STATIC_ANALYSIS)));
        $mutators = $options->texts(Key::of(self::MUTATORS));

        return match (true) {
            $tests instanceof Problem => Invalid::because($tests),
            $timeout instanceof Problem => Invalid::because($timeout),
            $allowed instanceof Problem => Invalid::because($allowed),
            $analysis instanceof Problem => Invalid::because($analysis),
            $mutators instanceof Problem => Invalid::because($mutators),
            default => new self(
                $tests instanceof Paths && count($tests) > 0 ? $tests : Paths::of(TestsDirectory::conventional()),
                $timeout instanceof NotGiven ? Triage::standard()->most() : Seconds::of($timeout),
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

    /** `timeouts.most`: Infection's own `timeout`, its cap on a limit and its skip for tests that take as long. */
    public function cap(): Seconds
    {
        return $this->cap;
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
