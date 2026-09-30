<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * A throwaway git repository under the system's temporary directory, made
 * with git itself, and removed by `Scratch::sweep()`.
 */
final readonly class Repository
{
    /** What every commit is made with, whatever the machine's own config says. */
    private const array SETTINGS = [
        '-c',
        'user.name=Gate',
        '-c',
        'user.email=gate@example.com',
        '-c',
        'commit.gpgsign=false',
        '-c',
        'tag.gpgsign=false',
        '-c',
        'init.defaultBranch=main',
        '-c',
        'core.autocrlf=false',
    ];

    private function __construct(public string $root)
    {
    }

    /** A new repository with nothing in it. */
    public static function empty(): self
    {
        $repository = new self(Scratch::directory());
        $repository->git('init', '--quiet');

        return $repository;
    }

    /**
     * The repository every change source's contract suite reads: at the tag
     * `fixture-base`, `src/Money.php` returns 1; on disk it returns 2, and
     * `src/Limit.php` is new and untracked.
     */
    public static function ofTheFixture(): self
    {
        $repository = self::empty()->write('src/Money.php', "<?php\nreturn 1;\n")->commit('The base.');
        $repository->git('tag', 'fixture-base');

        return $repository
            ->write('src/Money.php', "<?php\nreturn 2;\n")
            ->write('src/Limit.php', "<?php\n");
    }

    /** Write a file, creating the directories it needs. */
    public function write(string $path, string $contents): self
    {
        Scratch::write($this->root, $path, $contents);

        return $this;
    }

    /** Commit everything on disk that git does not ignore. */
    public function commit(string $message): self
    {
        $this->git('add', '--all');
        $this->git('commit', '--quiet', '--allow-empty', '--message', $message);

        return $this;
    }

    /** What git prints for these arguments, run in the repository; a failure fails the test. */
    public function git(string ...$arguments): string
    {
        $process = new Process(['git', ...self::SETTINGS, ...$arguments], $this->root);
        $process->mustRun();

        return $process->getOutput();
    }
}
