<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/** Whether mutants that are one program are judged once (ADR-0025, ADR-0137): `mutants`. */
final readonly class Mutants implements Part
{
    /** The key, and the runner option, that says it. */
    public const string DUPLICATES = 'duplicates';

    private function __construct(private Duplicates|Absent $duplicates)
    {
    }

    public static function of(Duplicates|Absent $duplicates = new Absent()): self
    {
        return new self($duplicates);
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        return self::of(Duplicates::JudgeOnce);
    }

    public function over(Part $later): self
    {
        return $later instanceof self ? new self(Absent::laid($this->duplicates, $later->duplicates)) : $this;
    }

    /** `mutants.duplicates`: whether mutants that are one program are judged once; `judge-once` unless a layer says. */
    public function duplicates(): Duplicates
    {
        return $this->duplicates instanceof Duplicates ? $this->duplicates : Duplicates::JudgeOnce;
    }

    /**
     * The runner a config chooses, with `duplicates` beneath its options where it is one the gate builds in;
     * Infection runs every mutant whatever it says, and another runner reads its own options.
     */
    public function beneath(Choice $runner): Choice
    {
        $builtIn = BuiltinRunner::tryFrom($runner->use()->value());

        return ! $builtIn instanceof BuiltinRunner || $builtIn === BuiltinRunner::Infection
            ? $runner
            : Choice::of(
                $runner->use()->value(),
                $runner->options()->over(Json::object(Member::of(self::DUPLICATES, $this->duplicates()->value))),
            );
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(Member::unlessEmpty(
            'mutants',
            Json::object(Member::of(
                self::DUPLICATES,
                $this->duplicates instanceof Duplicates ? $this->duplicates->value : $this->duplicates,
            )),
        ));
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        return PhpCalls::inWith(...$this->duplicates instanceof Duplicates ? [$this->duplicates->php()] : []);
    }
}
