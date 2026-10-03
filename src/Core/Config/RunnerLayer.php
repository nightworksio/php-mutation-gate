<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_unique;
use function array_values;
use function implode;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\Withheld;

use function sprintf;

/**
 * What one layer of a config says of the runner (ADR-0004): the one it
 * chooses, the environment variables it adds to those the runner withholds
 * from the project's tests, and the memory each process that runs a mutant
 * may use. A layer may say either of the last two without choosing one.
 */
final readonly class RunnerLayer
{
    /** The runners the builder has a method of its own for. */
    private const array RUNNERS = [BuiltinRunner::Pest->value, BuiltinRunner::Infection->value];

    /** @param list<string> $withhold */
    private function __construct(
        private Choice|Absent $runner,
        private array $withhold,
        private MemoryCap|Absent $memory,
    ) {
    }

    public static function of(Choice|Absent $runner, Withheld|Absent $withhold, MemoryCap|Absent $memory): self
    {
        return new self($runner, $withhold instanceof Withheld ? [...$withhold] : [], $memory);
    }

    /** A later layer's choice and cap over this one's, and what both withhold. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->runner, $later->runner),
            array_values(array_unique([...$this->withhold, ...$later->withhold])),
            Absent::laid($this->memory, $later->memory),
        );
    }

    /** The runner the layer chooses, or none, for a later layer or zero-config to choose. */
    public function runner(): Choice|Absent
    {
        return $this->runner;
    }

    public function withhold(): Withheld
    {
        return Withheld::of(...$this->withhold);
    }

    /** The cap the layer sets, or 1G where it sets none. */
    public function memory(): MemoryCap
    {
        return $this->memory instanceof MemoryCap ? $this->memory : MemoryCap::standard();
    }

    /** The config with the runner in it: by itself as the adapter it chooses, or as an object with the rest. */
    public function written(Json $written): Json
    {
        $chosen = $this->runner instanceof Choice ? $this->runner->written() : Json::object();

        if ($this->withhold === [] && $this->memory instanceof Absent) {
            return $this->runner instanceof Choice ? $written->with(Member::of('runner', $chosen)) : $written;
        }

        $runner = $chosen instanceof Json ? $chosen : Json::object(Member::of('use', $chosen));
        $runner = $this->withhold === []
            ? $runner
            : $runner->with(Member::of('withhold', Json::items(...$this->withhold)));

        $runner = $this->memory instanceof MemoryCap
            ? $runner->with(Member::of('memory', $this->memory->written()))
            : $runner;

        return $written->with(Member::of('runner', $runner));
    }

    /**
     * The runner as the builder chooses it, with `->withholding()` where it
     * withholds anything and `->cappedAt()` where the layer caps its memory;
     * or, where the layer chooses none, those on the gate.
     */
    public function php(): PhpCalls
    {
        $withheld = sprintf('Withheld::of(%s)', PhpCalls::literals(...$this->withhold));
        $memory = $this->memory instanceof MemoryCap ? $this->memoryPhp($this->memory) : '';
        $also = [
            ...($this->withhold === [] ? [] : [sprintf('->withholding(%s)', $withheld)]),
            ...($memory === '' ? [] : [sprintf('->cappedAt(%s)', $memory)]),
        ];

        if ($this->runner instanceof Choice) {
            return PhpCalls::onGate(
                GateMethod::Runner,
                sprintf(
                    '%s%s',
                    PhpCalls::chosen($this->runner, AdapterBuilder::Runner, ...self::RUNNERS),
                    implode('', $also),
                ),
            );
        }

        $calls = $this->withhold === [] ? PhpCalls::none() : PhpCalls::onGate(GateMethod::Withholding, $withheld);

        return $memory === '' ? $calls : $calls->and(PhpCalls::onGate(GateMethod::CappedAt, $memory));
    }

    /** A memory cap as the builder writes it. */
    private function memoryPhp(MemoryCap $memory): string
    {
        $unit = $memory->unit();

        return $unit instanceof MemoryUnit
            ? sprintf('MemoryCap::of(%d, MemoryUnit::%s)', $memory->number(), $unit->name)
            : 'MemoryCap::none()';
    }
}
