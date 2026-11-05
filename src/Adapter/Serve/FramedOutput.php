<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Serve;

use NightWorksIO\MutationGate\Core\Serve\ServeFrame;
use NightWorksIO\MutationGate\Core\Serve\ServeStream;

use const PHP_EOL;

use function sprintf;

use Symfony\Component\Console\Output\StreamOutput;

/**
 * A daemon child's output or error output (ADR-0078): each write sent to
 * the client as a frame of its stream, decorated as the client's terminal
 * is.
 *
 * @internal
 */
final class FramedOutput extends StreamOutput
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ServeStream $kind,
        bool $decorated,
    ) {
        parent::__construct($connection->stream(), decorated: $decorated);
    }

    protected function doWrite(string $message, bool $newline): void
    {
        $this->connection->sent(
            ServeFrame::written($this->kind, sprintf('%s%s', $message, $newline ? PHP_EOL : ''))->line(),
        );
    }
}
