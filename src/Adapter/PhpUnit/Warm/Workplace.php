<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

use function dirname;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function scandir;
use function sprintf;
use function unlink;

/**
 * The directory a mutation run's warm workers share: the job, the count of
 * runs claimed so far, what each worker and its children printed, how each
 * run ended, and each worker's refusal, where its boot failed the guard.
 */
final readonly class Workplace
{
    private const string JOB = 'job.json';

    private const string CLAIMED = 'claimed';

    private const string OUT = 'worker-%d.out';

    private const string ERR = 'worker-%d.err';

    private const string END = '%d.end';

    private const string REFUSED = 'refused.%d';

    private function __construct(private string $directory)
    {
    }

    public static function at(string $directory): self
    {
        return new self($directory);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /** The workplace made, empty, with the job in it and no run claimed; whether it could be. */
    public function opened(Job $job): bool
    {
        $made = is_dir($this->directory)
            || (! is_file(dirname($this->directory)) && mkdir($this->directory, recursive: true));

        return $made
            && file_put_contents($this->job(), $job->written()->line()) !== false
            && file_put_contents($this->claimed(), '0') !== false;
    }

    /** The workplace removed, with every file in it. */
    public function removed(): void
    {
        $files = is_dir($this->directory) ? scandir($this->directory) : false;

        foreach (is_array($files) ? $files : [] as $file) {
            $path = $this->in($file);
            if (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function job(): string
    {
        return $this->in(self::JOB);
    }

    public function claimed(): string
    {
        return $this->in(self::CLAIMED);
    }

    /** What the worker in a place, and every child it forked, printed on standard output. */
    public function out(int $place): string
    {
        return $this->in(sprintf(self::OUT, $place));
    }

    /** What the worker in a place, and every child it forked, printed on standard error. */
    public function err(int $place): string
    {
        return $this->in(sprintf(self::ERR, $place));
    }

    /** How the run at a position ended, once it has. */
    public function end(int $at): string
    {
        return $this->in(sprintf(self::END, $at));
    }

    /** Why the worker in a place booted nothing it could fork from. */
    public function refused(int $place): string
    {
        return $this->in(sprintf(self::REFUSED, $place));
    }

    private function in(string $name): string
    {
        return sprintf('%s/%s', $this->directory, $name);
    }
}
