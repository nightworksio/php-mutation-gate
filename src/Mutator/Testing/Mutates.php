<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Testing;

use NightWorksIO\MutationGate\Mutator\Engine\Offered;
use NightWorksIO\MutationGate\Mutator\Engine\Source;
use NightWorksIO\MutationGate\Mutator\Engine\Unparsable;
use NightWorksIO\MutationGate\Mutator\Mutator;

/**
 * A mutator's changes to a snippet of PHP under each runner, as the bridges
 * the gate writes make them, without either runner installed (ADR-0021
 * decision 8). Each node is offered with its names resolved, in a
 * `resolvedName` attribute, and its `parent` set, as both runners offer it.
 */
final readonly class Mutates
{
    private function __construct(private Mutator $mutator, private Source $source)
    {
    }

    /** @throws NotParsed where the snippet is not PHP */
    public static function with(Mutator $mutator, string $php): self
    {
        $source = Source::parse($php);

        return $source instanceof Unparsable
            ? throw NotParsed::because($source->reason())
            : new self($mutator, $source);
    }

    /**
     * The changes Pest's bridge makes, which are the gate's own engine's too:
     * one per node the mutator handles, anywhere in the snippet.
     */
    public function underPest(): Changes
    {
        return $this->changes(Offered::Everywhere);
    }

    /**
     * The changes Infection's bridge makes: one per node the mutator handles
     * in a class method or on its signature, which are the only nodes
     * Infection offers.
     */
    public function underInfection(): Changes
    {
        return $this->changes(Offered::InClassMethods);
    }

    private function changes(Offered $offered): Changes
    {
        $changes = [];

        foreach ($this->source->edits($this->mutator, $offered) as $edit) {
            $changes[] = $edit->changed();
        }

        return Changes::of(...$changes);
    }
}
