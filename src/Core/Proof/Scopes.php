<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_any;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Scopes in the order they were added, each once.
 *
 * @implements IteratorAggregate<int, Scope>
 */
final readonly class Scopes implements Countable, IteratorAggregate
{
    /** @param list<Scope> $scopes */
    private function __construct(private array $scopes)
    {
    }

    public static function of(Scope ...$scopes): self
    {
        $collected = new self([]);

        foreach ($scopes as $scope) {
            $collected = $collected->has($scope) ? $collected : new self([...$collected->scopes, $scope]);
        }

        return $collected;
    }

    public function has(Scope $scope): bool
    {
        return array_any($this->scopes, static fn(Scope $held): bool => $held->equals($scope));
    }

    public function count(): int
    {
        return count($this->scopes);
    }

    /** @return Traversable<int, Scope> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->scopes);
    }
}
