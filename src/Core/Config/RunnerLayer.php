<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;
use function array_unique;
use function array_values;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Workers;

use function sprintf;

/**
 * What one layer of a config says of the runner (ADR-0004): the one it
 * chooses, the environment variables it adds to those the runner withholds
 * from the project's tests, and the memory each process that runs a mutant
 * may use. A layer may say either of the last two without choosing one.
 */
final readonly class RunnerLayer
{
    /** @param list<string> $withhold */
    private function __construct(
        private Choice|Absent $runner,
        private array $withhold,
        private MemoryCap|Absent $memory,
        private Workers|Absent $workers,
    ) {
    }

    public static function of(
        Choice|Absent $runner,
        Withheld|Absent $withhold,
        MemoryCap|Absent $memory,
        Workers|Absent $workers,
    ): self {
        return new self($runner, $withhold instanceof Withheld ? [...$withhold] : [], $memory, $workers);
    }

    /** A later layer's choice and cap over this one's, and what both withhold. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->runner, $later->runner),
            array_values(array_unique([...$this->withhold, ...$later->withhold])),
            Absent::laid($this->memory, $later->memory),
            Absent::laid($this->workers, $later->workers),
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

    /** How the layer has each mutant's run start, or forking where it says nothing (ADR-0023, decision 14). */
    public function workers(): Workers
    {
        return $this->workers instanceof Workers ? $this->workers : Workers::Fork;
    }

    /** The config with the runner in it: by itself as the adapter it chooses, or as an object with the rest. */
    public function written(Json $written): Json
    {
        $chosen = $this->runner instanceof Choice ? $this->runner->written() : Json::object();
        $also = $this->alsoWritten();

        if ($also === []) {
            return $this->runner instanceof Choice ? $written->with(Member::of('runner', $chosen)) : $written;
        }

        $runner = $chosen instanceof Json ? $chosen : Json::object(Member::of('use', $chosen));

        foreach ($also as $member) {
            $runner = $runner->with($member);
        }

        return $written->with(Member::of('runner', $runner));
    }

    /**
     * The runner as the builder chooses it, with `->withholding()` where it
     * withholds anything, `->cappedAt()` where the layer caps its memory and
     * `->inWorkers()` where it says how workers start; or, where the layer
     * chooses none, those on the gate.
     */
    public function php(): PhpCalls
    {
        $also = $this->alsoPhp();

        if ($this->runner instanceof Choice) {
            $called = '';

            foreach ($also as [$method, $argument]) {
                $called = sprintf('%s->%s(%s)', $called, $method->value, $argument);
            }

            return PhpCalls::onGate(
                GateMethod::Runner,
                sprintf('%s%s', PhpCalls::chosen($this->runner, AdapterBuilder::Runner, ...$this->builtins()), $called),
            );
        }

        $calls = PhpCalls::none();

        foreach ($also as [$method, $argument]) {
            $calls = $calls->and(PhpCalls::onGate($method, $argument));
        }

        return $calls;
    }

    /** @return list<Member> each setting the layer writes beside the runner's own */
    private function alsoWritten(): array
    {
        return [
            ...($this->withhold === [] ? [] : [Member::of('withhold', Json::items(...$this->withhold))]),
            ...($this->memory instanceof MemoryCap ? [Member::of('memory', $this->memory->written())] : []),
            ...($this->workers instanceof Workers ? [Member::of('workers', $this->workers->value)] : []),
        ];
    }

    /** @return list<array{GateMethod, string}> each call besides the runner's own, and its argument as PHP writes it */
    private function alsoPhp(): array
    {
        return [
            ...($this->withhold === []
                ? []
                : [[GateMethod::Withholding, sprintf('Withheld::of(%s)', PhpCalls::literals(...$this->withhold))]]),
            ...($this->memory instanceof MemoryCap ? [[GateMethod::CappedAt, $this->memoryPhp($this->memory)]] : []),
            ...($this->workers instanceof Workers
                ? [[GateMethod::InWorkers, sprintf('Workers::%s', $this->workers->name)]]
                : []),
        ];
    }

    /** @return list<string> the runners the builder has a method of its own for: every one this package builds in */
    private function builtins(): array
    {
        return array_map(static fn(BuiltinRunner $runner): string => $runner->value, BuiltinRunner::cases());
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
