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

    /** A layer that sets nothing. */
    public static function none(): self
    {
        return new self(Parts::of(
            Setup::none(),
            Floors::none(),
            Reach::none(),
            Shards::none(),
            Ci::none(),
            Proofs::none(),
            Triage::none(),
            Ignores::none(),
            Reports::none(),
            Badge::none(),
            Pest::none(),
            StaticCheck::none(),
            Local::none(),
        ));
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
            Badge::standard(),
            Pest::standard(),
            StaticCheck::standard(),
            Local::standard(),
        ));
    }

    /** A layer that sets only what these parts set. */
    public static function of(Part ...$parts): self
    {
        $layer = self::none();

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

    public function badge(): Badge
    {
        return $this->parts->part(Badge::class);
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

}
