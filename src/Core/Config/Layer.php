<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * One layer of config, as a preset, a config file or the command line
 * writes it (ADR-0002), in parts listed in the order the configuration
 * reference lists their keys: each part holds what the layer sets, and
 * leaves the rest to an earlier layer or to its default. Layers are laid in
 * order, later winning, and the settings are the last one laid over every
 * default.
 */
final readonly class Layer
{
    private function __construct(private Parts $parts)
    {
    }

    public function setup(): Setup
    {
        return $this->parts->part(Setup::class);
    }

    /** Every setting at the value it takes when every layer leaves it out. */
    public static function standard(): self
    {
        return new self(Parts::of(
            Setup::standard(),
            Floors::standard(),
            Reach::standard(),
            Shards::standard(),
            Ci::standard(),
            Proofs::standard(),
            Triage::standard(),
            Ignores::standard(),
            Reports::standard(),
            Publishing::standard(),
            Pest::standard(),
            StaticCheck::standard(),
            Local::standard(),
            Contexts::standard(),
        ));
    }

    /** A layer that sets only what these parts set, and nothing where it is given none. */
    public static function of(Part ...$parts): self
    {
        $layer = new self(Parts::of(
            Setup::none(),
            Floors::none(),
            Reach::none(),
            Shards::none(),
            Ci::none(),
            Proofs::none(),
            Triage::none(),
            Ignores::none(),
            Reports::none(),
            Publishing::none(),
            Pest::none(),
            StaticCheck::none(),
            Local::none(),
            Contexts::none(),
        ));

        foreach ($parts as $part) {
            $layer = $layer->over(new self(Parts::of($part)));
        }

        return $layer;
    }

    /** This layer with a later one laid over it. */
    public function over(self $later): self
    {
        return new self($this->parts->over($later->parts));
    }

    /** What this layer sets, as a config file at this origin writes it. */
    public function written(PathOrigin $origin): Json
    {
        return $this->parts->written($origin);
    }

    /** What this layer sets, as the PHP builder's calls. */
    public function php(PathOrigin $origin): PhpCalls
    {
        return $this->parts->php($origin);
    }

    public function floors(): Floors
    {
        return $this->parts->part(Floors::class);
    }

    public function reach(): Reach
    {
        return $this->parts->part(Reach::class);
    }

    public function shards(): Shards
    {
        return $this->parts->part(Shards::class);
    }

    public function ci(): Ci
    {
        return $this->parts->part(Ci::class);
    }

    public function proofs(): Proofs
    {
        return $this->parts->part(Proofs::class);
    }

    public function triage(): Triage
    {
        return $this->parts->part(Triage::class);
    }

    public function ignores(): Ignores
    {
        return $this->parts->part(Ignores::class);
    }

    public function reports(): Reports
    {
        return $this->parts->part(Reports::class);
    }

    public function publishing(): Publishing
    {
        return $this->parts->part(Publishing::class);
    }

    public function pest(): Pest
    {
        return $this->parts->part(Pest::class);
    }

    public function staticCheck(): StaticCheck
    {
        return $this->parts->part(StaticCheck::class);
    }

    public function local(): Local
    {
        return $this->parts->part(Local::class);
    }

    /**
     * What this layer sets in a run in these contexts (ADR-0070): the rest of the layer, with the block of each
     * context laid over it in order, and no block left.
     */
    public function in(ContextChain $chain): self
    {
        $resolved = new self($this->parts->with(Contexts::none()));

        foreach ($chain as $context) {
            $resolved = $resolved->over($this->parts->part(Contexts::class)->block($context));
        }

        return $resolved;
    }
}
