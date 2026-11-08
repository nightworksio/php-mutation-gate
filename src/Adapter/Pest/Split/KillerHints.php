<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function file_get_contents;
use function in_array;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Identities;
use NightWorksIO\MutationGate\Adapter\Pest\Order\Plan;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Php\Functions;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use Pest\Mutate\MutationSuite;

use function sprintf;

/**
 * The test a split stream forks each mutant at first (ADR-0117): the likeliest
 * killer the kill history names for it, as each mutant's own run orders its
 * tests (ADR-0013), where a test that covers it is one.
 *
 * @internal
 */
final readonly class KillerHints
{
    /** @param array<string, TestIds> $killers each mutant's likely killers, likeliest first, by Pest's id */
    private function __construct(private array $killers)
    {
    }

    /** No likely killer of any mutant: every mutant forks at every covering test. */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * The likely killers of every mutant of a suite, from the kill history
     * the gate planned in this order directory; none where it named none.
     */
    public static function of(MutationSuite $suite, string|false $directory, Root $root): self
    {
        return is_string($directory) && $directory !== ''
            ? self::from(Plan::read($directory), $suite, $root)
            : self::none();
    }

    /**
     * The likeliest killer of a mutant among the tests that cover it, or ''
     * where the history names none of them.
     *
     * @param list<string> $covering
     */
    public function killerOf(string $mutant, array $covering): string
    {
        foreach (array_key_exists($mutant, $this->killers) ? $this->killers[$mutant] : [] as $test) {
            if (in_array($test->value(), $covering, true)) {
                return $test->value();
            }
        }

        return '';
    }

    private static function from(KillHistory $history, MutationSuite $suite, Root $root): self
    {
        $planned = [];

        foreach ($suite->repository->all() as $collection) {
            foreach ($collection->tests() as $test) {
                $planned[] = Recorder::madeOf($test->mutation);
            }
        }

        $planned = PlannedMutant::inOrder($planned);
        $ids = Identities::of($root, $planned);
        $functions = [];
        $killers = [];

        foreach ($planned as $at => $mutant) {
            $file = $mutant->file()->value();
            $functions[$file] = array_key_exists($file, $functions)
                ? $functions[$file]
                : Functions::in(Contents::of(sprintf('%s', file_get_contents($file))));
            $in = Enclosing::of($root->relative($file), $functions[$file]->around($mutant->start()));
            $killers[$mutant->id()] = $history->likelyKillers($ids[$at], $in);
        }

        return new self($killers);
    }
}
