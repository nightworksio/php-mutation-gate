<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Test\Group;

use function sprintf;

/**
 * The optional Pest patches, the tests that show they work (ADR-0004), and
 * whether the verification tail's workers boot the project (ADR-0075): `pest`.
 */
final readonly class Pest implements Part
{
    /** The key, and the Pest runner's option, that says whether the tail's workers boot the project. */
    public const string BOOT_TAIL = 'bootTail';

    /** The key, and the Pest runner's option, that says whether Pest's warm workers compile through opcache. */
    public const string WORKER_OPCACHE = 'workerOpcache';
    private const string CANARY = 'mutation-canary';

    private function __construct(
        private bool|Absent $patch,
        private Group|Absent $canary,
        private bool|Absent $bootTail = new Absent(),
        private bool|Absent $workerOpcache = new Absent(),
    ) {
    }

    public static function of(
        bool|Absent $patch = new Absent(),
        Group|Absent $canary = new Absent(),
        bool|Absent $bootTail = new Absent(),
        bool|Absent $workerOpcache = new Absent(),
    ): self {
        return new self($patch, $canary, $bootTail, $workerOpcache);
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        return self::of($none->patch(), $none->canary(), $none->bootTail(), $none->workerOpcache());
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                Absent::laid($this->patch, $later->patch),
                Absent::laid($this->canary, $later->canary),
                Absent::laid($this->bootTail, $later->bootTail),
                Absent::laid($this->workerOpcache, $later->workerOpcache),
            )
            : $this;
    }

    /** Whether the optional Pest patches are applied. */
    public function patch(): bool
    {
        return ! $this->patch instanceof Absent && $this->patch;
    }

    /**
     * Whether the verification tail's Pest workers boot the project, every
     * test file loaded, before they fork its runs that load every test file
     * (ADR-0075); false by default.
     */
    public function bootTail(): bool
    {
        return ! $this->bootTail instanceof Absent && $this->bootTail;
    }

    /**
     * Whether Pest's warm workers compile through opcache, shared by every
     * run they fork, with each file the run mutates and each copy it serves
     * kept out of it (ADR-0088); true by default.
     */
    public function workerOpcache(): bool
    {
        return $this->workerOpcache instanceof Absent || $this->workerOpcache;
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
        $options = Json::object(
            Member::of('patch', $this->patch()),
            Member::of('canary', $this->canary()->name()),
            Member::of(self::BOOT_TAIL, $this->bootTail()),
            Member::of(self::WORKER_OPCACHE, $this->workerOpcache()),
        );

        return $runner->use()->value() === BuiltinRunner::Pest->value
            ? Choice::of($runner->use()->value(), $runner->options()->over($options))
            : $runner;
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(Member::unlessEmpty(
            'pest',
            Json::object(
                Member::of('patch', $this->patch),
                Member::of('canary', $this->canary instanceof Group ? $this->canary->name() : $this->canary),
                Member::of(self::BOOT_TAIL, $this->bootTail),
                Member::of(self::WORKER_OPCACHE, $this->workerOpcache),
            ),
        ));
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->switched($this->patch, 'Pest::patched()', 'Pest::unpatched()'),
            ...$this->canary instanceof Group
                ? [sprintf('Pest::canary(%s)', PhpCalls::literal($this->canary->name()))]
                : [],
            ...$this->switched($this->bootTail, 'Pest::bootingTail()', 'Pest::tailUnbooted()'),
            ...$this->switched($this->workerOpcache, 'Pest::cachingInWorkers()', 'Pest::uncachedInWorkers()'),
        ]);
    }

    /**
     * The call that sets a switch as a layer sets it, on or off; none where the layer leaves it.
     *
     * @return list<string>
     */
    private function switched(bool|Absent $value, string $on, string $off): array
    {
        return match ($value) {
            true => [$on],
            false => [$off],
            default => [],
        };
    }
}
