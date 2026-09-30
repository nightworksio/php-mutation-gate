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
 * `refuse` by default; and `tests`, the directories the tests live in,
 * `tests` by default.
 */
final readonly class Setup
{
    private const float TIMEOUT = 10.0;

    private const string TESTS = 'tests';

    private const string MARKERS = 'nativeMarkers';

    private function __construct(private Paths $tests, private Seconds $cap, private bool $nativeMarkersAllowed)
    {
    }

    public static function of(Options $options): self|Invalid
    {
        $tests = $options->paths(Key::of(self::TESTS));
        $timeout = $options->number(Key::of('timeout'));
        $allowed = self::allowedIn($options->text(Key::of(self::MARKERS)));

        return match (true) {
            $tests instanceof Problem => Invalid::because($tests),
            $timeout instanceof Problem => Invalid::because($timeout),
            $allowed instanceof Problem => Invalid::because($allowed),
            default => new self(
                $tests instanceof Paths && count($tests) > 0 ? $tests : Paths::of(TestsDirectory::conventional()),
                Seconds::of($timeout instanceof NotGiven ? self::TIMEOUT : $timeout),
                $allowed,
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
