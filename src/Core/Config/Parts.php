<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\MisreadSetting;
use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * The parts of one layer of config, each by its class, in the order the
 * configuration reference lists their keys: laid over another layer's part
 * by part, and written part by part. A layer reads its parts through this,
 * each by the accessor of its own.
 */
final readonly class Parts
{
    /** @param array<class-string<Part>, Part> $parts */
    private function __construct(private array $parts)
    {
    }

    public static function of(Part ...$parts): self
    {
        $keyed = [];

        foreach ($parts as $part) {
            $keyed[$part::class] = $part;
        }

        return new self($keyed);
    }

    /** These parts with a later layer's laid over them, each over the one of its class. */
    public function over(self $later): self
    {
        $parts = $this->parts;

        foreach ($later->parts as $class => $part) {
            $parts[$class] = $parts[$class]->over($part);
        }

        return new self($parts);
    }

    /** What these parts set, as a config file at this origin writes it. */
    public function written(PathOrigin $origin): Json
    {
        $written = Json::object();

        foreach ($this->parts as $part) {
            $written = $written->merged($part->written($origin));
        }

        return $written;
    }

    /** What these parts set, as the PHP builder's calls. */
    public function php(PathOrigin $origin): PhpCalls
    {
        $calls = PhpCalls::none();

        foreach ($this->parts as $part) {
            $calls = $calls->and($part->php($origin));
        }

        return $calls;
    }

    /**
     * @template P of Part
     *
     * @param  class-string<P> $class
     * @return P
     */
    public function part(string $class): Part
    {
        $part = $this->parts[$class];

        return $part instanceof $class
            ? $part
            : throw MisreadSetting::as($class, 'a part of a layer');
    }
}
