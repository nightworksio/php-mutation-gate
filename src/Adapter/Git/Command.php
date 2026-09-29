<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

use function implode;

use NightWorksIO\MutationGate\Core\Change\CannotTell;

use function sprintf;

use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

use function trim;

/** Git, run in one directory, answering with what it printed or why it could not. */
final readonly class Command
{
    /** Settings that keep what git prints the same whatever the user's own config says. */
    private const array SETTINGS = [
        '-c',
        'core.quotePath=false',
        '-c',
        'diff.noprefix=false',
        '-c',
        'diff.mnemonicPrefix=false',
        '-c',
        'diff.relative=false',
        '-c',
        'color.ui=false',
    ];

    /** Why git gave no answer. */
    private const string FAILED = 'git %s gave no answer: %s';

    private function __construct(private string $directory)
    {
    }

    public static function in(string $directory): self
    {
        return new self($directory);
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): string|CannotTell
    {
        return $this->finish(
            new Process(['git', ...self::SETTINGS, ...$arguments], $this->directory, timeout: null),
            $arguments,
        );
    }

    /**
     * Git, reading its standard input from this text.
     *
     * @param list<string> $arguments
     */
    public function feed(array $arguments, string $input): string|CannotTell
    {
        return $this->finish(
            new Process(['git', ...self::SETTINGS, ...$arguments], $this->directory, input: $input, timeout: null),
            $arguments,
        );
    }

    /** @param list<string> $arguments */
    private function finish(Process $process, array $arguments): string|CannotTell
    {
        try {
            $process->run();
        } catch (RuntimeException $refused) {
            return CannotTell::because(sprintf(self::FAILED, implode(' ', $arguments), $refused->getMessage()));
        }

        return $process->isSuccessful()
            ? $process->getOutput()
            : CannotTell::because(sprintf(self::FAILED, implode(' ', $arguments), trim($process->getErrorOutput())));
    }
}
