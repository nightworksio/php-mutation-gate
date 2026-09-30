<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function count;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Extension\Options;

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

    private const string ALLOW = 'allow';

    private const string REFUSE = 'refuse';

    private const string TESTS = 'tests';

    private const string MARKERS = 'nativeMarkers';

    private function __construct(private Paths $tests, private Seconds $cap, private bool $nativeMarkersAllowed)
    {
    }

    public static function of(Options $options): self|Invalid
    {
        $read = Node::decode($options->json());

        try {
            return new self(
                self::testsIn($read->field(self::TESTS)),
                self::capIn($read->field('timeout')),
                self::allowedIn($read->field(self::MARKERS)),
            );
        } catch (NotInShape $shape) {
            return Invalid::because(Problem::at('runner', $shape->getMessage()));
        }
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

    /** @throws NotInShape */
    private static function testsIn(Node $tests): Paths
    {
        $paths = Paths::none();

        foreach ($tests->isPresent() ? $tests->items() : [] as $test) {
            $paths = $paths->with(Path::of($test->text()));
        }

        return count($paths) === 0 ? Paths::of(Path::of(self::TESTS)) : $paths;
    }

    /** @throws NotInShape */
    private static function capIn(Node $timeout): Seconds
    {
        return Seconds::of($timeout->isPresent() ? $timeout->number() : self::TIMEOUT);
    }

    /** @throws NotInShape */
    private static function allowedIn(Node $markers): bool
    {
        $value = $markers->isPresent() ? $markers->text() : self::REFUSE;

        return match ($value) {
            self::ALLOW => true,
            self::REFUSE => false,
            default => throw NotInShape::at($markers->at(), '"refuse" or "allow"'),
        };
    }
}
