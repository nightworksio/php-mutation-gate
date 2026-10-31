<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use RuntimeException;
use Throwable;

/** A command that could not finish, whose exit code says it could not judge. */
final class NotFinished extends RuntimeException
{
    public static function by(Throwable $failed): self
    {
        return new self($failed->getMessage(), ExitCode::CannotJudge->value, $failed);
    }

    /** What stopped the command. */
    public function cause(): Throwable
    {
        return $this->getPrevious() ?? $this;
    }
}
