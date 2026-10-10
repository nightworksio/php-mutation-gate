<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_any;
use function array_diff;
use function array_map;
use function array_values;

use ArrayIterator;

use function implode;
use function in_array;

use IteratorAggregate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

use Traversable;

/**
 * The suites the project's PHPUnit config declares, in its order.
 *
 * @implements IteratorAggregate<int, DeclaredSuite>
 */
final readonly class DeclaredSuites implements IteratorAggregate
{
    private const string NOT_DECLARED = '%s lists %s, which names no test suite. The PHPUnit config declares: %s.';

    private const string NONE_DECLARED
        = '%s lists %s, which names no test suite: the PHPUnit config declares none by name.';

    private const string NONE_LEFT = <<<'SAID'
        tests.holding lists every suite the PHPUnit config declares, so no test judges a unit nothing holds.
        List the suites whose tests judge every unit in tests.suites, or hold fewer.
        SAID;

    /** @param list<DeclaredSuite> $suites */
    private function __construct(private array $suites)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(DeclaredSuite ...$suites): self
    {
        return new self(array_values($suites));
    }

    /**
     * The suites `tests.suites` and `tests.holding` list (ADR-0002, decision
     * 8), where this config declares each: where `tests.suites` lists none,
     * every declared suite `tests.holding` leaves out judges every unit. Or
     * why not: a listed name it does not declare, or a holding list that
     * leaves no suite to judge every unit.
     */
    public function listing(JudgingSuites $listed): JudgingSuites|CannotJudge
    {
        $declared = array_map(static fn(DeclaredSuite $suite): string => $suite->name(), $this->suites);
        $holding = $listed->heldUnits();
        $judging = $this->undeclared($listed->everyUnit(), 'tests.suites', $declared);

        if ($judging instanceof CannotJudge || ! $holding instanceof Suites) {
            return $judging instanceof CannotJudge ? $judging : $listed;
        }

        $held = $this->undeclared($holding, 'tests.holding', $declared);

        if ($held instanceof CannotJudge || ! $listed->everyUnit()->isAll()) {
            return $held instanceof CannotJudge ? $held : $listed;
        }

        $names = array_map(static fn(SuiteName $name): string => $name->value(), [...$holding]);
        $left = array_values(array_diff($declared, $names));

        return $left === []
            ? CannotJudge::because(self::NONE_LEFT)
            : JudgingSuites::holding(Suites::listed(...$left), $holding);
    }

    /** Whether one of these suites holds a test's file: any file, where they are every suite. */
    public function hold(Path $file, Suites $suites): bool
    {
        if ($suites->isAll()) {
            return true;
        }

        $names = array_map(static fn(SuiteName $name): string => $name->value(), [...$suites]);

        return array_any(
            $this->suites,
            static fn(DeclaredSuite $suite): bool => in_array($suite->name(), $names, strict: true)
                && $suite->holds($file),
        );
    }

    /** Of some test files, those one of these suites holds: every one, where they are every suite. */
    public function among(Paths $files, Suites $suites): Paths
    {
        $among = Paths::none();

        foreach ($files as $file) {
            $among = $this->hold($file, $suites) ? $among->with($file) : $among;
        }

        return $among;
    }

    /** @return Traversable<int, DeclaredSuite> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->suites);
    }

    /**
     * Why a list names a suite this config does not declare, naming the first and those it declares; nothing where
     * it names none such.
     *
     * @param list<string> $declared
     */
    private function undeclared(Suites $suites, string $key, array $declared): CannotJudge|NotGiven
    {
        foreach ($suites as $suite) {
            if (! in_array($suite->value(), $declared, strict: true)) {
                return CannotJudge::because($declared === []
                    ? sprintf(self::NONE_DECLARED, $key, Fit::plain($suite->value()))
                    : sprintf(
                        self::NOT_DECLARED,
                        $key,
                        Fit::plain($suite->value()),
                        implode(', ', array_map(Fit::plain(...), $declared)),
                    ));
            }
        }

        return NotGiven::value();
    }
}
