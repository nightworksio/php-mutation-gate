<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Analysis\BuiltInAnalyser;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

use function sprintf;

/**
 * The static analyser that may kill a mutant, and the config it reads
 * (ADR-0020, decision 8): `staticCheck`. The analyser is chosen as any other
 * adapter is, by a name or a class. `auto` takes the first one installed and
 * configured, and `none` turns checking off; both are names, not adapters.
 * Without a config, the analyser discovers its own.
 */
final readonly class StaticCheck implements Part
{
    /** The analyser zero-config finds: the first installed and configured, or none. */
    public const string AUTO = 'auto';

    /** No analyser: every mutant goes to its tests alone. */
    public const string NONE = 'none';

    /** The analysers this package brings, which the PHP builder has a method of its own for. */
    private const array BUILT_IN = [
        self::AUTO,
        self::NONE,
        BuiltInAnalyser::Mago->value,
        BuiltInAnalyser::PhpStan->value,
        BuiltInAnalyser::Psalm->value,
    ];

    private function __construct(private Choice|Absent $tool, private Path|Absent $config)
    {
    }

    public static function of(Choice|Absent $tool = new Absent(), Path|Absent $config = new Absent()): self
    {
        return new self($tool, $config);
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        return self::of(self::none()->tool());
    }

    /** An analyser is chosen whole, and so is the config it reads. */
    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(Absent::laid($this->tool, $later->tool), Absent::laid($this->config, $later->config))
            : $this;
    }

    /** `staticCheck.tool`: the analyser chosen, `auto` where no layer chooses one. */
    public function tool(): Choice
    {
        return $this->tool instanceof Choice ? $this->tool : Choice::of(self::AUTO, Json::object());
    }

    /** `staticCheck.config`: the config the analyser reads, or none, where it discovers its own. */
    public function config(): Path|Absent
    {
        return $this->config;
    }

    public function written(Origin $origin): Json
    {
        return Json::object(Member::unlessEmpty(
            'staticCheck',
            Json::object(
                Member::of('tool', $this->tool instanceof Choice ? $this->tool->written() : $this->tool),
                Member::of('config', $this->config instanceof Path ? $origin->written($this->config) : $this->config),
            ),
        ));
    }

    public function php(Origin $origin): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->tool instanceof Choice ? [PhpCalls::chosen($this->tool, 'StaticCheck', ...self::BUILT_IN)] : [],
            ...$this->config instanceof Path
                ? [sprintf('StaticCheck::config(%s)', PhpCalls::literal($origin->written($this->config)))]
                : [],
        ]);
    }
}
