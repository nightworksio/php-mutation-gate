<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Pruning\MutatorWindow;
use NightWorksIO\MutationGate\Core\Pruning\Survival;

use function sprintf;

/**
 * What a ledger learned of each mutator's newest judged mutants, as its
 * optional `survival` section holds it (ADR-0025, decision 4): by the
 * runner's name, then the mutator's, the outcomes of its window as a `0` for
 * each mutant killed and a `1` for each let through, oldest first, and the
 * id of the last mutant let through, where one ever was.
 *
 * Reading keeps each well-formed window and drops anything else, never
 * repairing it; a ledger without the section learned nothing, which
 * disables pruning, never a verdict, so the ledger's format does not count
 * it.
 *
 * @internal the shape of the `survival` section of the ledger file
 *
 * @phpstan-type Written array<string, array<string, array{outcomes: string, last?: string}>>
 */
final readonly class SurvivalRecord
{
    public const string SECTION = 'survival';

    private const string OUTCOMES = 'outcomes';

    private const string LAST = 'last';

    /** @return Written */
    public static function of(Survival $survival): array
    {
        $written = [];

        foreach ($survival as $runner => $windows) {
            foreach ($windows as $mutator => $window) {
                $last = $window->last();
                $written[$runner][$mutator] = [
                    self::OUTCOMES => $window->outcomes(),
                    ...$last instanceof NotGiven ? [] : [self::LAST => $last],
                ];
            }
        }

        return $written;
    }

    /** What a ledger's `survival` section holds; nothing where it holds none. */
    public static function read(Node $section): Survival
    {
        $survival = Survival::none();

        foreach (self::entriesOf($section) as $runner => $windows) {
            foreach (self::entriesOf($windows) as $mutator => $window) {
                foreach (self::windowIn(sprintf('%s', $mutator), $window) as $held) {
                    $survival = $survival->with(Name::of(sprintf('%s', $runner)), $held);
                }
            }
        }

        return $survival;
    }

    /** @return list<MutatorWindow> the window an entry holds, or none where it is malformed */
    private static function windowIn(string $mutator, Node $entry): array
    {
        try {
            $outcomes = $entry->field(self::OUTCOMES)->text();
            $last = $entry->field(self::LAST)->isPresent() ? $entry->field(self::LAST)->text() : NotGiven::value();
        } catch (NotInShape) {
            return [];
        }

        $window = MutatorWindow::written($mutator, $outcomes, $last);

        return $window instanceof MutatorWindow ? [$window] : [];
    }

    /** @return array<array-key, Node> */
    private static function entriesOf(Node $map): array
    {
        try {
            return $map->entries();
        } catch (NotInShape) {
            return [];
        }
    }
}
