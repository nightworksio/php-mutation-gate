<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\MisreadSetting;
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
    /** @param array<class-string<Part>, Part> $parts */
    private function __construct(private array $parts)
    {
    }

    public function setup(): Setup
    {
        return $this->part(Setup::class);
    }

    /** A layer that sets nothing. */
    public static function none(): self
    {
        return new self([
            Setup::class => Setup::none(),
            Floors::class => Floors::none(),
            Reach::class => Reach::none(),
            Shards::class => Shards::none(),
            Ci::class => Ci::none(),
            Proofs::class => Proofs::none(),
            Triage::class => Triage::none(),
            Ignores::class => Ignores::none(),
            Reports::class => Reports::none(),
            Badge::class => Badge::none(),
            Pest::class => Pest::none(),
            Local::class => Local::none(),
        ]);
    }

    /** Every setting at the value it takes when every layer leaves it out. */
    public static function standard(): self
    {
        return new self([
            Setup::class => Setup::standard(),
            Floors::class => Floors::standard(),
            Reach::class => Reach::standard(),
            Shards::class => Shards::standard(),
            Ci::class => Ci::standard(),
            Proofs::class => Proofs::standard(),
            Triage::class => Triage::standard(),
            Ignores::class => Ignores::standard(),
            Reports::class => Reports::standard(),
            Badge::class => Badge::standard(),
            Pest::class => Pest::standard(),
            Local::class => Local::standard(),
        ]);
    }

    /** A layer that sets only what these parts set. */
    public static function of(Part ...$parts): self
    {
        $layer = self::none();

        foreach ($parts as $part) {
            $layer = $layer->over(new self([$part::class => $part]));
        }

        return $layer;
    }

    /** This layer with a later one laid over it. */
    public function over(self $later): self
    {
        $parts = $this->parts;

        foreach ($later->parts as $class => $part) {
            $parts[$class] = $parts[$class]->over($part);
        }

        return new self($parts);
    }

    /** What this layer sets, as a config file at this origin writes it. */
    public function written(PathOrigin $origin): Json
    {
        $written = Json::object();

        foreach ($this->parts as $part) {
            $written = $written->merged($part->written($origin));
        }

        return $written;
    }

    /** What this layer sets, as the PHP builder's calls. */
    public function php(PathOrigin $origin): PhpCalls
    {
        $calls = PhpCalls::none();

        foreach ($this->parts as $part) {
            $calls = $calls->and($part->php($origin));
        }

        return $calls;
    }

    public function floors(): Floors
    {
        return $this->part(Floors::class);
    }

    public function reach(): Reach
    {
        return $this->part(Reach::class);
    }

    public function shards(): Shards
    {
        return $this->part(Shards::class);
    }

    public function ci(): Ci
    {
        return $this->part(Ci::class);
    }

    public function proofs(): Proofs
    {
        return $this->part(Proofs::class);
    }

    public function triage(): Triage
    {
        return $this->part(Triage::class);
    }

    public function ignores(): Ignores
    {
        return $this->part(Ignores::class);
    }

    public function reports(): Reports
    {
        return $this->part(Reports::class);
    }

    public function badge(): Badge
    {
        return $this->part(Badge::class);
    }

    public function pest(): Pest
    {
        return $this->part(Pest::class);
    }

    public function local(): Local
    {
        return $this->part(Local::class);
    }

    /**
     * @template P of Part
     *
     * @param  class-string<P> $class
     * @return P
     */
    private function part(string $class): Part
    {
        $part = $this->parts[$class];

        return $part instanceof $class
            ? $part
            : throw MisreadSetting::as($class, 'a part of a layer');
    }
}
