<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;

/**
 * The `memory_limit` the project's PHPUnit config sets with
 * `<ini name="memory_limit">` under `<php>`, and the config that sets it.
 * PHPUnit sets it as it starts, after PHP has read the cap `runner.memory`
 * sets, so each test process runs under it in place of the cap.
 */
final readonly class PhpUnitMemory
{
    private function __construct(private Path $config, private MemoryCap $limit)
    {
    }

    public static function of(Path $config, MemoryCap $limit): self
    {
        return new self($config, $limit);
    }

    public function config(): Path
    {
        return $this->config;
    }

    public function limit(): MemoryCap
    {
        return $this->limit;
    }
}
