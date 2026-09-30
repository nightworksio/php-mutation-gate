<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;

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
        return $this->tool instanceof Choice ? $this->tool : Choice::of(self::AUTO, Options::none());
    }

    /** `staticCheck.config`: the config the analyser reads, or none, where it discovers its own. */
    public function config(): Path|Absent
    {
        return $this->config;
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(Member::unlessEmpty(
            'staticCheck',
            Json::object(
                Member::of('tool', $this->tool instanceof Choice ? $this->tool->written() : $this->tool),
                Member::of('config', $this->config instanceof Path ? $origin->written($this->config) : $this->config),
            ),
        ));
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->tool instanceof Choice ? [PhpCalls::chosen($this->tool, 'StaticCheck', ...$this->builtIn())] : [],
            ...$this->config instanceof Path
                ? [sprintf('StaticCheck::config(%s)', PhpCalls::literal($origin->written($this->config)))]
                : [],
        ]);
    }

    /** @return list<string> `auto`, `none` and the analysers built in, each with a builder method of its own */
    private function builtIn(): array
    {
        return [
            self::AUTO,
            self::NONE,
            ...array_map(static fn(BuiltinAnalyser $analyser): string => $analyser->value, BuiltinAnalyser::cases()),
        ];
    }
}
