<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Import;

use function array_diff_key;
use function array_keys;
use function array_map;
use function array_values;
use function implode;

use NightWorksIO\MutationGate\Core\Config\Layer;

use function sprintf;

/**
 * The gate's config as another tool's config seeds it (ADR-0016, decision
 * 1): the layer it becomes, what became of each of its keys, and what else a
 * person should know before deleting any of them.
 */
final readonly class Import
{
    private const string HEADING = 'What became of each key of %s:';

    private const string DELETE = 'Delete these keys from %s, since the gate no longer reads them there: %s.';

    private const string RUN = 'Run mutation-gate locally once.';

    private const string BASELINE
        = 'It writes the baseline at what the gate measures, and says if a tree is below the imported floor.';

    /**
     * @param list<Carried> $keys
     * @param list<string>  $notes
     */
    private function __construct(private Layer $layer, private array $keys, private array $notes)
    {
    }

    public static function none(): self
    {
        return new self(Layer::none(), [], []);
    }

    /** A layer of the gate's config, and what became of the keys it holds. */
    public static function of(Layer $layer, Carried ...$keys): self
    {
        return new self($layer, array_values($keys), []);
    }

    /** This import with another laid over it: its layer over this one's, its keys and notes after these. */
    public function and(self $later): self
    {
        return new self(
            $this->layer->over($later->layer),
            [...$this->keys, ...$later->keys],
            [...$this->notes, ...$later->notes],
        );
    }

    /** This import, with something a person should know that belongs to no one key. */
    public function noting(string $note): self
    {
        return new self($this->layer, $this->keys, [...$this->notes, $note]);
    }

    public function layer(): Layer
    {
        return $this->layer;
    }

    /**
     * What became of each key, then the notes, then the keys that can go from
     * the other tool's file: every key imported or dropped, unless part of it
     * stays.
     */
    public function report(string $file): string
    {
        $named = [];
        $staying = [];

        foreach ($this->keys as $key) {
            $named[$key->key()] = true;
            $staying = $key->fate()->deletes() ? $staying : [...$staying, $key->key() => true];
        }

        $deleted = array_keys(array_diff_key($named, $staying));

        return implode("\n", [
            sprintf(self::HEADING, $file),
            ...array_map(static fn(Carried $key): string => sprintf('  %s', $key->said($file)), $this->keys),
            ...$this->notes,
            ...($deleted === [] ? [] : [sprintf(self::DELETE, $file, implode(', ', $deleted))]),
            self::RUN,
            self::BASELINE,
        ]);
    }
}
