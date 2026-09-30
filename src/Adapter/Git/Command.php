<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

use function array_filter;
use function array_key_exists;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function getmypid;
use function implode;
use function is_dir;
use function is_string;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Withholding;

use function proc_close;
use function proc_open;
use function sprintf;
use function stream_get_contents;

use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

/**
 * Git, run in one directory, answering with what it printed or why it could
 * not. Git inherits the gate's environment but the CI's credentials, which it
 * never needs, so that a hook or a config the repository ships cannot read
 * them.
 */
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
        '-c',
        'diff.renames=true',
    ];

    /** Why git gave no answer. */
    private const string FAILED = 'git %s gave no answer: %s';

    /** Why git cannot run where the directory it is to run in is not there. */
    private const string NO_DIRECTORY = 'The provided cwd "%s" does not exist.';

    /** Why git cannot be handed its input. */
    private const string NO_SCRATCH = 'there is no temporary file to hand it its input in.';

    /** Why git did not run. */
    private const string NOT_STARTED = 'it could not be started.';

    /**
     * How the files git reads its input from and writes its errors to are
     * named, after the process that made them: `mutation-gate-git-<pid>-`.
     */
    private const string SCRATCH = 'mutation-gate-git-%d-';

    /** @param array<string, string> $environment what git inherits, the credentials left out */
    private function __construct(private string $directory, private array $environment)
    {
    }

    /** Git in a directory, withholding what every run withholds from the environment the gate runs in. */
    public static function in(string $directory): self
    {
        return self::withholding($directory, Withheld::standard(), getenv());
    }

    /**
     * Git in a directory, withholding these from this environment.
     *
     * @param array<string, string> $inherited
     */
    public static function withholding(string $directory, Withheld $withheld, array $inherited): self
    {
        $kept = array_filter(
            Withholding::of($withheld, $inherited),
            static fn(string|false $value): bool => $value !== false,
        );

        return new self($directory, $kept);
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): string|CannotTell
    {
        return $this->finish(
            new Process(['git', ...self::SETTINGS, ...$arguments], $this->directory, $this->withheld(), timeout: null),
            $arguments,
        );
    }

    /**
     * Git, reading its standard input from this text. The text reaches git as
     * a file rather than a pipe, so git that stops before it has read all of
     * it, as it does when it cannot start, leaves nothing half written, and
     * its exit code says why.
     *
     * @param list<string> $arguments
     */
    public function feed(array $arguments, string $input): string|CannotTell
    {
        $scratch = sprintf(self::SCRATCH, getmypid());
        $in = tempnam(sys_get_temp_dir(), $scratch);
        $errors = tempnam(sys_get_temp_dir(), $scratch);
        $handed = is_string($in) && is_string($errors) && file_put_contents($in, $input) !== false;

        try {
            return match (true) {
                ! is_dir($this->directory) => $this->refused($arguments, sprintf(self::NO_DIRECTORY, $this->directory)),
                ! $handed => $this->refused($arguments, self::NO_SCRATCH),
                default => $this->fedFrom($arguments, $in, $errors),
            };
        } finally {
            $this->cleared($in, $errors);
        }
    }

    /** @param list<string> $arguments */
    private function finish(Process $process, array $arguments): string|CannotTell
    {
        try {
            $process->run();
        } catch (RuntimeException $refused) {
            return $this->refused($arguments, $refused->getMessage());
        }

        return $process->isSuccessful()
            ? $process->getOutput()
            : $this->refused($arguments, trim($process->getErrorOutput()));
    }

    /**
     * What git printed, reading its input from one file and writing what went
     * wrong to another, or why it gave no answer.
     *
     * @param list<string> $arguments
     */
    private function fedFrom(array $arguments, string $in, string $errors): string|CannotTell
    {
        $git = proc_open(
            ['git', ...self::SETTINGS, ...$arguments],
            [0 => ['file', $in, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']],
            $pipes,
            $this->directory,
            $this->environment,
        );
        $printed = $git === false ? false : stream_get_contents($pipes[1]);
        $succeeded = $git !== false && proc_close($git) === 0;
        $said = $git === false ? self::NOT_STARTED : trim(sprintf('%s', file_get_contents($errors)));

        return $succeeded && is_string($printed) ? $printed : $this->refused($arguments, $said);
    }

    /**
     * The environment a process inherits, as Symfony's process takes it: every
     * variable the gate runs with that git must not see, as false.
     *
     * @return array<string, string|false>
     */
    private function withheld(): array
    {
        $withheld = [];

        foreach (getenv() as $name => $value) {
            $withheld[$name] = array_key_exists($name, $this->environment) ? $value : false;
        }

        return [...$withheld, ...$this->environment];
    }

    /** Removes the files git read from and wrote to, where they were made. */
    private function cleared(string|false ...$files): void
    {
        foreach ($files as $file) {
            if (is_string($file)) {
                unlink($file);
            }
        }
    }

    /** @param list<string> $arguments */
    private function refused(array $arguments, string $why): CannotTell
    {
        return CannotTell::because(sprintf(self::FAILED, implode(' ', $arguments), $why));
    }
}
