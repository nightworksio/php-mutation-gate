<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Test\Group;

use function sprintf;

/** The optional Pest patches, and the tests that show they work (ADR-0004): `pest`. */
final readonly class Pest implements Part
{
    /** The runner these settings are the options of. */
    public const string RUNNER = 'pest';

    private const string CANARY = 'mutation-canary';

    private function __construct(private bool|Absent $patch, private Group|Absent $canary)
    {
    }

    public static function of(bool|Absent $patch = new Absent(), Group|Absent $canary = new Absent()): self
    {
        return new self($patch, $canary);
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        return self::of($none->patch(), $none->canary());
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(Absent::laid($this->patch, $later->patch), Absent::laid($this->canary, $later->canary))
            : $this;
    }

    /** Whether the optional Pest patches are applied. */
    public function patch(): bool
    {
        return ! $this->patch instanceof Absent && $this->patch;
    }

    /** The group of tests that shows the patches work. */
    public function canary(): Group
    {
        return $this->canary instanceof Group ? $this->canary : Group::named(self::CANARY);
    }

    /**
     * The runner a config chooses, with these settings beneath the options
     * it gives the Pest runner, so `pest.patch` reaches the runner it is
     * for; any other runner as it is.
     */
    public function beneath(Choice $runner): Choice
    {
        $options = Json::object(Member::of('patch', $this->patch()), Member::of('canary', $this->canary()->name()));

        return $runner->use() === self::RUNNER
            ? Choice::of($runner->use(), $options->merged($runner->options()))
            : $runner;
    }

    public function written(Origin $origin): Json
    {
        return Json::object(Member::unlessEmpty(
            'pest',
            Json::object(
                Member::of('patch', $this->patch),
                Member::of('canary', $this->canary instanceof Group ? $this->canary->name() : $this->canary),
            ),
        ));
    }

    public function php(Origin $origin): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->patch instanceof Absent ? [] : [$this->patch ? 'Pest::patched()' : 'Pest::unpatched()'],
            ...$this->canary instanceof Group
                ? [sprintf('Pest::canary(%s)', PhpCalls::literal($this->canary->name()))]
                : [],
        ]);
    }
}
