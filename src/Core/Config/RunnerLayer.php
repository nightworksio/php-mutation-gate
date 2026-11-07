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
use NightWorksIO\MutationGate\Core\Runner\PathRuleMode;
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
    /** @param list<string>|Absent $withhold */
    private function __construct(
        private Choice|Absent $runner,
        private array|Absent $withhold,
        private MemoryCap|Absent $memory,
        private Workers|Absent $workers,
        private PathRuleMode|Absent $pathRules,
        private RunnerPacing $pacing,
    ) {
    }

    public static function of(
        Choice|Absent $runner,
        Withheld|Absent $withhold,
        MemoryCap|Absent $memory,
        Workers|Absent $workers,
        PathRuleMode|Absent $pathRules,
        RunnerPacing $pacing,
    ): self {
        return new self(
            $runner,
            $withhold instanceof Withheld ? [...$withhold] : $withhold,
            $memory,
            $workers,
            $pathRules,
            $pacing,
        );
    }

    /** A later layer's choice and cap over this one's, and what both withhold. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->runner, $later->runner),
            $this->withhold instanceof Absent && $later->withhold instanceof Absent
                ? $later->withhold
                : array_values(array_unique([...$this->withheld(), ...$later->withheld()])),
            Absent::laid($this->memory, $later->memory),
            Absent::laid($this->workers, $later->workers),
            Absent::laid($this->pathRules, $later->pathRules),
            $this->pacing->over($later->pacing),
        );
    }

    /** The runner the layer chooses, or none, for a later layer or zero-config to choose. */
    public function runner(): Choice|Absent
    {
        return $this->runner;
    }

    public function withhold(): Withheld
    {
        return Withheld::of(...$this->withheld());
    }

    /** The cap the layer sets, or 1G where it sets none. */
    public function memory(): MemoryCap
    {
        return $this->memory instanceof MemoryCap ? $this->memory : MemoryCap::standard();
    }

    /** How the layer has each mutant's run start, or forking where it says nothing (ADR-0023). */
    public function workers(): Workers
    {
        return $this->workers instanceof Workers ? $this->workers : Workers::Fork;
    }

    /** How a shard applies what `paths` sets, or by partitioning its units where the layer says nothing (ADR-0074). */
    public function pathRules(): PathRuleMode
    {
        return $this->pathRules instanceof PathRuleMode ? $this->pathRules : PathRuleMode::Partitioned;
    }

    /** `runner.recycleAfter`, `runner.warmSlack` and `runner.poll`: how the runner paces its work. */
    public function pacing(): RunnerPacing
    {
        return $this->pacing;
    }

    /** The config with the runner in it: by itself as the adapter it chooses, or as an object with the rest. */
    public function written(Json $written): Json
    {
        $chosen = $this->runner instanceof Choice ? $this->runner->written() : Json::object();
        $also = $this->alsoWritten();

        if ($also === [] && $this->pacing->isUnset()) {
            return $this->runner instanceof Choice ? $written->with(Member::of('runner', $chosen)) : $written;
        }

        $runner = $chosen instanceof Json ? $chosen : Json::object(Member::of('use', $chosen));

        foreach ($also as $member) {
            $runner = $runner->with($member);
        }

        return $written->with(Member::of('runner', $this->pacing->written($runner)));
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

            $called = $this->pathRules instanceof PathRuleMode
                ? sprintf('%s->applyingPathRules(%s)', $called, $this->pathRulesPhp($this->pathRules))
                : $called;
            $called = $this->pacing->onRunner($called);

            return PhpCalls::onGate(
                GateMethod::Runner,
                sprintf('%s%s', PhpCalls::chosen($this->runner, AdapterBuilder::Runner, ...$this->builtins()), $called),
            );
        }

        $calls = $this->pathRules instanceof PathRuleMode
            ? PhpCalls::inWith(sprintf('PathRule::applying(%s)', $this->pathRulesPhp($this->pathRules)))
            : PhpCalls::none();

        foreach ($also as [$method, $argument]) {
            $calls = $calls->and(PhpCalls::onGate($method, $argument));
        }

        return $this->pacing->onGate($calls);
    }

    /** @return list<string> what the layer withholds */
    private function withheld(): array
    {
        return $this->withhold instanceof Absent ? [] : $this->withhold;
    }

    /** @return list<Member> each setting the layer writes beside the runner's own */
    private function alsoWritten(): array
    {
        return [
            ...($this->withhold instanceof Absent ? [] : [Member::of('withhold', Json::items(...$this->withhold))]),
            ...($this->memory instanceof MemoryCap ? [Member::of('memory', $this->memory->written())] : []),
            ...($this->workers instanceof Workers ? [Member::of('workers', $this->workers->value)] : []),
            ...($this->pathRules instanceof PathRuleMode ? [Member::of('pathRules', $this->pathRules->value)] : []),
        ];
    }

    /** @return list<array{GateMethod, string}> each call besides the runner's own, and its argument as PHP writes it */
    private function alsoPhp(): array
    {
        return [
            ...($this->withheld() === []
                ? []
                : [[GateMethod::Withholding, sprintf('Withheld::of(%s)', PhpCalls::literals(...$this->withheld()))]]),
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

    /** How a shard applies `paths`, as the builder writes it. */
    private function pathRulesPhp(PathRuleMode $mode): string
    {
        return sprintf('PathRuleMode::%s', $mode->name);
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
