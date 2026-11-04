<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_values;

use NightWorksIO\MutationGate\Core\Format\Node;

/**
 * The `paths` entries that match a file, least specific first (ADR-0074): laid over the settings in that order,
 * they make the file's own, and what they set that affects results is the file's share of its proof key.
 */
final readonly class MatchedRules
{
    /** @param list<PathRule> $rules */
    private function __construct(private array $rules)
    {
    }

    public static function of(PathRule ...$rules): self
    {
        return new self(array_values($rules));
    }

    /** Whether no entry matches, so the file takes the settings as they are. */
    public function isNone(): bool
    {
        return $this->rules === [];
    }

    /** What every matched entry sets, each laid over the one before it. */
    public function layer(): Layer
    {
        $laid = Layer::of();

        foreach ($this->rules as $rule) {
            $laid = $laid->over($rule->rules());
        }

        return $laid;
    }

    /**
     * What the entries set that affects results, serialised canonically; empty where none matches. Files whose
     * keys are equal run alike, and a proof's key holds it (ADR-0007).
     */
    public function key(): string
    {
        $key = Canonical::of($this->layer()->written(ProjectRoot::origin()), Definition::effects());

        return $key === Node::NO_KEYS ? '' : $key;
    }

    /** What the entries set, serialised; empty where none matches. Files whose groups are equal run together. */
    public function group(): string
    {
        return $this->isNone() ? '' : $this->layer()->written(ProjectRoot::origin())->line();
    }

}
