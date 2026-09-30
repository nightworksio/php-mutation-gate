<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpStan;

use function getenv;
use function is_int;

use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Withholding;

use function sprintf;

use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * PHPStan, run once to its end in the project's root without what is
 * withheld: what it wrote on each stream, and how it exited, where it
 * exited at all.
 */
final readonly class Started
{
    private function __construct(private int|NotGiven $exit, private string $output, private string $errors)
    {
    }

    /** @param list<string> $arguments */
    public static function run(Root $root, Withheld $withheld, array $arguments): self
    {
        $process = new Process($arguments, $root->value(), Withholding::of($withheld, getenv()), timeout: null);

        try {
            $process->run();
        } catch (RuntimeException $failure) {
            return new self(NotGiven::value(), '', $failure->getMessage());
        }

        $exit = $process->getExitCode();

        return new self(is_int($exit) ? $exit : NotGiven::value(), $process->getOutput(), $process->getErrorOutput());
    }

    /** How it exited, or nothing where it never started, or a signal ended it. */
    public function exit(): int|NotGiven
    {
        return $this->exit;
    }

    /** What it wrote on its standard output. */
    public function output(): string
    {
        return $this->output;
    }

    /** What it said on its error output, or how it failed to start, for a message that says why. */
    public function said(): string
    {
        return $this->exit instanceof NotGiven
            ? sprintf('it did not run: %s', $this->errors)
            : sprintf('exit %d: %s', $this->exit, $this->errors);
    }
}
