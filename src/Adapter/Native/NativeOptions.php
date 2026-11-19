<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native;

use function count;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BoundsOptions;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\Duplicates;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Mutants;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Test\TestsDirectory;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\Engine\Engine;

use function sprintf;

/**
 * What a native runner's options say, as the flows write them: `tests`,
 * the directories the tests live in, read from the framework's config, `tests`
 * by default; `timeout` and `most`, the floor and the most of each mutant's
 * limit, `timeouts.seconds` and `timeouts.most`, their own defaults where
 * none is written; and `mutators`, the classes of the mutators the gate makes
 * its mutants with (ADR-0023). Without a mutator, the runner can
 * make no mutant, and says why.
 *
 * @internal
 */
final readonly class NativeOptions
{
    /** The option that holds the mutators' classes, which the flows write. */
    public const string MUTATORS = 'mutators';

    /** The option that holds the directories the tests are in, which the flows write from the framework's config. */
    public const string TESTS = 'tests';

    private const string NO_MUTATORS
        = 'The %s runner makes its mutants with the default mutator set, and no extension registers one.';

    private function __construct(
        private Paths $tests,
        private LimitBounds $bounds,
        private Engine|CannotJudge $engine,
        private Duplicates $duplicates,
    ) {
    }

    /** The options of this runner. */
    public static function read(Options $options, BuiltinRunner $runner): self|Invalid
    {
        $tests = $options->paths(Key::of(self::TESTS));
        $bounds = BoundsOptions::read($options);
        $mutators = $options->texts(Key::of(self::MUTATORS));
        $duplicates = $options->text(Key::of(Mutants::DUPLICATES));
        $judged = is_string($duplicates) ? Duplicates::tryFrom($duplicates) : Duplicates::JudgeOnce;

        return match (true) {
            $tests instanceof Problem => Invalid::because($tests),
            $bounds instanceof Problem => Invalid::because($bounds),
            $mutators instanceof Problem => Invalid::because($mutators),
            $duplicates instanceof Problem => Invalid::because($duplicates),
            ! $judged instanceof Duplicates => Invalid::because(
                Problem::mismatch(Mutants::DUPLICATES, '"judge-once" or "judge-each"', $duplicates),
            ),
            default => new self(
                $tests instanceof Paths && count($tests) > 0 ? $tests : Paths::of(TestsDirectory::conventional()),
                $bounds,
                self::engineOf($runner, $mutators instanceof Listed ? [...$mutators] : []),
                $judged,
            ),
        };
    }

    /** `duplicates`: whether mutants that are one program are judged once (ADR-0137). */
    public function duplicates(): Duplicates
    {
        return $this->duplicates;
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
    private static function engineOf(BuiltinRunner $runner, array $classes): Engine|CannotJudge
    {
        $enabled = Enabled::named($runner, ...$classes);

        if ($enabled instanceof CannotJudge) {
            return $enabled;
        }

        return count($enabled) === 0
            ? CannotJudge::because(sprintf(self::NO_MUTATORS, $runner->value))
            : $enabled->engine();
    }
}
