<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use function array_filter;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;

use ArrayIterator;

use function count;
use function implode;

use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sort;
use function sprintf;

use Traversable;

/**
 * What static analysis's checks of survivors came to (ADR-0020, decisions 9
 * and 11): the time each analyser's checks took, and each survivor it left
 * unchecked, with why. The run warns once for each reason, with how many
 * survivors it left and the first of their files.
 *
 * @implements IteratorAggregate<int, UncheckedSurvivor>
 */
final readonly class SurvivorChecks implements IteratorAggregate
{
    /** How many of a reason's files its warning names; it counts the rest. */
    private const int NAMED = 3;

    private const string LEFT = 'Static analysis left %d %s unchecked, as %s: %s.';

    private const string ONE = 'survivor';

    private const string MANY = 'survivors';

    private const string MORE = '%s and %d more';

    /** @param list<UncheckedSurvivor> $unchecked */
    private function __construct(private AnalyserHistories $histories, private array $unchecked)
    {
    }

    public static function none(): self
    {
        return new self(AnalyserHistories::none(), []);
    }

    /** These checks, with what they taught of one analyser, replacing what they held of it. */
    public function timing(AnalyserHistory $history): self
    {
        return new self($this->histories->with($history), $this->unchecked);
    }

    /** These checks, and one survivor more left unchecked. */
    public function leaving(UncheckedSurvivor $survivor): self
    {
        return new self($this->histories, [...$this->unchecked, $survivor]);
    }

    /** These checks and another shard's, added. */
    public function plus(self $other): self
    {
        return new self($this->histories->plus($other->histories), [...$this->unchecked, ...$other->unchecked]);
    }

    /** What the checks taught of each analyser: their time alone, as checks after the tests teach no rate. */
    public function histories(): AnalyserHistories
    {
        return $this->histories;
    }

    /** One warning for each reason a survivor was left unchecked, in the order the reasons are declared. */
    public function warnings(): Warnings
    {
        $warnings = Warnings::none();

        foreach (Unchecked::cases() as $why) {
            $files = array_map(
                static fn(UncheckedSurvivor $survivor): string => $survivor->file()->value(),
                array_values(array_filter(
                    $this->unchecked,
                    static fn(UncheckedSurvivor $survivor): bool => $survivor->why() === $why,
                )),
            );
            $warnings = $files === [] ? $warnings : $warnings->with($this->warning($why, $files));
        }

        return $warnings;
    }

    /** @return Traversable<int, UncheckedSurvivor> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->unchecked);
    }

    /** @param non-empty-list<string> $files each unchecked survivor's file */
    private function warning(Unchecked $why, array $files): Warning
    {
        $distinct = array_values(array_unique($files));
        sort($distinct);
        $named = implode(', ', array_slice($distinct, 0, self::NAMED));
        $more = count($distinct) - self::NAMED;

        return Warning::that(sprintf(
            self::LEFT,
            count($files),
            count($files) === 1 ? self::ONE : self::MANY,
            $why->because(),
            $more > 0 ? sprintf(self::MORE, $named, $more) : $named,
        ));
    }
}
