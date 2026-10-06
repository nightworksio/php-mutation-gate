<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Test\TestsDirectory;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;

/**
 * What the `phpunit` runner's options say, as the flows write them: `tests`,
 * the directories the tests live in, `tests` by default; `timeout` and
 * `most`, the floor and the most of each mutant's limit, `timeouts.seconds`
 * and `timeouts.most`, their own defaults where none is written; and `mutators`, the classes of the mutators the gate
 * makes its mutants with (ADR-0023, decision 8). Without a mutator, the
 * runner can make no mutant, and says why.
 */
final readonly class PhpUnitOptions
{
    /** The option that holds the least each mutant's run is allowed, `timeouts.seconds`, which the flows write. */
    public const string TIMEOUT = 'timeout';

    /** The option that holds the most each mutant's run is allowed, `timeouts.most`, which the flows write. */
    public const string MOST = 'most';

    /** The option that holds the mutators' classes, which the flows write. */
    public const string MUTATORS = 'mutators';

    private const string TESTS = 'tests';

    private const string NO_MUTATORS
        = 'The phpunit runner makes its mutants with the default mutator set, and no extension registers one.';

    private function __construct(private Paths $tests, private LimitBounds $bounds, private Engine|CannotJudge $engine)
    {
    }

    public static function read(Options $options): self|Invalid
    {
        $tests = $options->paths(Key::of(self::TESTS));
        $timeout = $options->number(Key::of(self::TIMEOUT));
        $most = $options->number(Key::of(self::MOST));
        $mutators = $options->texts(Key::of(self::MUTATORS));

        return match (true) {
            $tests instanceof Problem => Invalid::because($tests),
            $timeout instanceof Problem => Invalid::because($timeout),
            $most instanceof Problem => Invalid::because($most),
            $mutators instanceof Problem => Invalid::because($mutators),
            default => new self(
                $tests instanceof Paths && count($tests) > 0 ? $tests : Paths::of(TestsDirectory::conventional()),
                LimitBounds::between(
                    $timeout instanceof NotGiven ? Triage::standard()->limit() : Seconds::of($timeout),
                    $most instanceof NotGiven ? Triage::standard()->most() : Seconds::of($most),
                ),
                self::engineOf($mutators instanceof Listed ? [...$mutators] : []),
            ),
        };
    }

    public function tests(): Paths
    {
        return $this->tests;
    }

    /** What each mutant's limit is kept between. */
    public function bounds(): LimitBounds
    {
        return $this->bounds;
    }

    /** The engine of the mutators the options name, or why there is none to mutate with. */
    public function engine(): Engine|CannotJudge
    {
        return $this->engine;
    }

    /** @param list<string> $classes */
    private static function engineOf(array $classes): Engine|CannotJudge
    {
        $enabled = Enabled::named(BuiltinRunner::PhpUnit, ...$classes);

        if ($enabled instanceof CannotJudge) {
            return $enabled;
        }

        return count($enabled) === 0 ? CannotJudge::because(self::NO_MUTATORS) : $enabled->engine();
    }
}
