<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use function file_put_contents;
use function getenv;

use NightWorksIO\MutationGate\Core\Runner\ProcessLog;

use function sprintf;

/**
 * A log that says each line on another and also keeps it at the end of the
 * file `MUTATION_GATE_SAID` names while it names one, from which a shard's
 * progress takes the gate's last lines (ADR-0157).
 *
 * @internal
 */
final readonly class KeptLog implements ProcessLog
{
    private function __construct(private ProcessLog $log)
    {
    }

    public static function over(ProcessLog $log): self
    {
        return new self($log);
    }

    public function say(string $line): void
    {
        $this->log->say($line);
        $file = getenv(self::KEPT);

        if ($file !== false && $file !== '') {
            file_put_contents($file, sprintf("%s\n", $line), FILE_APPEND);
        }
    }
}
