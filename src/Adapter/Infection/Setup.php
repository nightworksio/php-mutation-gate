<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function count;
use function is_string;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\NativeMarkers;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestsDirectory;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * The options the flows build the Infection adapter with: `timeout`, the
 * seconds each mutant is allowed at most, `timeouts.seconds`, 10 by default as
 * Infection's own; `nativeMarkers`, `refuse` or `allow`, `ignores.native`,
 * `refuse` by default; `tests`, the directories the tests live in, `tests`
 * by default; and `staticAnalysis`, `infection` or `gate`, who runs static
 * analysis over the mutants, `infection` by default.
 */
final readonly class Setup
{
    /** The option that says who runs static analysis over the mutants, which the flows write. */
    public const string STATIC_ANALYSIS = 'staticAnalysis';
    private const float TIMEOUT = 10.0;

    private const string TESTS = 'tests';

    private const string MARKERS = 'nativeMarkers';

    private function __construct(
        private Paths $tests,
        private Seconds $cap,
        private bool $nativeMarkersAllowed,
        private StaticAnalysis $analysis,
    ) {
    }

    public static function of(Options $options): self|Invalid
    {
        $tests = $options->paths(Key::of(self::TESTS));
        $timeout = $options->number(Key::of('timeout'));
        $allowed = self::allowedIn($options->text(Key::of(self::MARKERS)));
        $analysis = self::analysisIn($options->text(Key::of(self::STATIC_ANALYSIS)));

        return match (true) {
            $tests instanceof Problem => Invalid::because($tests),
            $timeout instanceof Problem => Invalid::because($timeout),
            $allowed instanceof Problem => Invalid::because($allowed),
            $analysis instanceof Problem => Invalid::because($analysis),
            default => new self(
                $tests instanceof Paths && count($tests) > 0 ? $tests : Paths::of(TestsDirectory::conventional()),
                Seconds::of($timeout instanceof NotGiven ? self::TIMEOUT : $timeout),
                $allowed,
                $analysis,
            ),
        };
    }

    public function tests(): Paths
    {
        return $this->tests;
    }

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
