<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

use function sprintf;

/**
 * How the gate holds every process it starts (ADR-0158): `processes.group`,
 * each in a session of its own whose every process is stopped with it;
 * `processes.contain`, on Linux each in a cgroup of its own under one that
 * caps them all; `processes.memory`, the share of the machine's memory they
 * may hold together, with no swap; and `processes.pids`, how many processes
 * they may be together.
 */
final readonly class Processes implements Part
{
    /** The share of the machine's memory, in percent, the processes the gate starts may hold together. */
    private const int MEMORY = 85;

    /** How many processes the processes the gate starts may be together. */
    private const int PIDS = 8192;

    private function __construct(
        private ProcessHold|Absent $group,
        private ProcessHold|Absent $contain,
        private int|Absent $memory,
        private int|Absent $pids,
    ) {
    }

    public static function of(
        ProcessHold|Absent $group = new Absent(),
        ProcessHold|Absent $contain = new Absent(),
        int|Absent $memory = new Absent(),
        int|Absent $pids = new Absent(),
    ): self {
        return new self($group, $contain, $memory, $pids);
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        return self::of($none->group(), $none->contain(), $none->memory(), $none->pids());
    }

    public function over(Part $later): self
    {
        return $later instanceof self ? new self(
            Absent::laid($this->group, $later->group),
            Absent::laid($this->contain, $later->contain),
            Absent::laid($this->memory, $later->memory),
            Absent::laid($this->pids, $later->pids),
        ) : $this;
    }

    /** `processes.group`: whether each process the gate starts leads a session of its own, stopped whole. */
    public function group(): ProcessHold
    {
        return $this->group instanceof ProcessHold ? $this->group : ProcessHold::Auto;
    }

    /** `processes.contain`: whether each process the gate starts runs in a cgroup of its own, on Linux. */
    public function contain(): ProcessHold
    {
        return $this->contain instanceof ProcessHold ? $this->contain : ProcessHold::Auto;
    }

    /** `processes.memory`: the share of the machine's memory, in percent, its contained processes may hold together. */
    public function memory(): int
    {
        return $this->memory instanceof Absent ? self::MEMORY : $this->memory;
    }

    /** `processes.pids`: how many processes its contained processes may be together. */
    public function pids(): int
    {
        return $this->pids instanceof Absent ? self::PIDS : $this->pids;
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(
            Member::unlessEmpty(
                'processes',
                Json::object(
                    Member::of('group', $this->group instanceof ProcessHold ? $this->group->value : $this->group),
                    Member::of(
                        'contain',
                        $this->contain instanceof ProcessHold ? $this->contain->value : $this->contain,
                    ),
                    Member::of('memory', $this->memory),
                    Member::of('pids', $this->pids),
                ),
            ),
        );
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        return PhpCalls::inWith(
            ...$this->group instanceof ProcessHold
                ? [sprintf('Processes::group(%s)', PhpCalls::literal($this->group->value))]
                : [],
            ...$this->contain instanceof ProcessHold
                ? [sprintf('Processes::contain(%s)', PhpCalls::literal($this->contain->value))]
                : [],
            ...$this->memory instanceof Absent
                ? []
                : [sprintf('Processes::memory(%d)', $this->memory)],
            ...$this->pids instanceof Absent
                ? []
                : [sprintf('Processes::pids(%d)', $this->pids)],
        );
    }
}
