<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_map;
use function explode;
use function preg_match;
use function sprintf;

use Symfony\Component\Process\Process;

/** A running process and every process under it, at any depth, as `ps` lists them. */
final readonly class ProcessTree
{
    /** A line of `ps`: a process and its parent. */
    private const string PROCESS = '~^\s*(?<pid>\d+)\s+(?<parent>\d+)\s*$~';

    private function __construct(private Process $process)
    {
    }

    public static function of(Process $process): self
    {
        return new self($process);
    }

    /** Stops every process under this one, deepest first, and then the process itself. */
    public function stop(): void
    {
        $under = $this->under((int) $this->process->getPid(), $this->parents());

        if ($under !== []) {
            new Process(['kill', '-KILL', ...array_map(static fn(int $pid): string => sprintf('%d', $pid), $under)])
                ->run();
        }

        $this->process->stop(0);
    }

    /**
     * Every process's parent, by process id, as `ps` lists them.
     *
     * @return array<int, int>
     */
    private function parents(): array
    {
        $listing = new Process(['ps', '-A', '-o', 'pid=', '-o', 'ppid=']);
        $listing->run();
        $parents = [];

        foreach (explode("\n", $listing->getOutput()) as $line) {
            if (preg_match(self::PROCESS, $line, $found) === 1) {
                $parents[(int) $found['pid']] = (int) $found['parent'];
            }
        }

        return $parents;
    }

    /**
     * The processes under one, at any depth, deepest first.
     *
     * @param array<int, int> $parents
     * @return list<int>
     */
    private function under(int $pid, array $parents): array
    {
        $under = [];

        foreach ($parents as $child => $parent) {
            $under = $parent === $pid ? [...$under, ...$this->under($child, $parents), $child] : $under;
        }

        return $under;
    }
}
