<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** How the gate holds every process it starts (ADR-0158): `processes`. */
final readonly class Processes implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `processes.group`: `auto`, `off` or `require`: whether each process leads a session, stopped whole. */
    public static function group(string $hold): self
    {
        return new self(Json::at('processes.group', $hold));
    }

    /** `processes.contain`: `auto`, `off` or `require`: whether each process runs in a cgroup of its own, on Linux. */
    public static function contain(string $hold): self
    {
        return new self(Json::at('processes.contain', $hold));
    }

    /** `processes.memory`: the share of the machine's memory, in percent, the contained processes may hold together. */
    public static function memory(int $percent): self
    {
        return new self(Json::at('processes.memory', $percent));
    }

    /** `processes.pids`: how many processes the contained processes may be together. */
    public static function pids(int $most): self
    {
        return new self(Json::at('processes.pids', $most));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
