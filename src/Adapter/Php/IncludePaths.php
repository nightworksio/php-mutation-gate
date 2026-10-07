<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Php;

use function array_pop;
use function array_unique;
use function array_values;
use function dirname;
use function explode;
use function implode;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\NotGiven;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Scalar\MagicConst\Dir;
use PhpParser\Node\Scalar\String_;

use function realpath;
use function rtrim;
use function sprintf;
use function str_starts_with;

/**
 * Where PHP finds a path an include writes, in a project on disk: a path
 * written of literals, `__DIR__` and the dots between them; where it says,
 * for an absolute one; the working directory, where the gate runs from the
 * project's root, for one that begins with a dot; that directory and then
 * the including file's own, for any other.
 */
final readonly class IncludePaths
{
    /** What `__DIR__` is written as where a path that names it is shown. */
    private const string DIR = '__DIR__';

    private function __construct(private string $project)
    {
    }

    /** Paths found from a project's root on disk. */
    public static function in(string $project): self
    {
        return new self(rtrim(self::real($project), '/'));
    }

    /** A path as the file system names it, where it is there; as it is written otherwise. */
    public static function real(string $path): string
    {
        $real = realpath($path);

        return $real === false ? $path : $real;
    }

    /**
     * A path written of literals, `__DIR__` and the dots between them, as it reads and as it is written; nothing
     * where any part of it is built as the file runs.
     *
     * @return array{string, string}|NotGiven
     */
    public function literal(Expr $path, string $file): array|NotGiven
    {
        return match (true) {
            $path instanceof Concat
                => $this->joined($this->literal($path->left, $file), $this->literal($path->right, $file)),
            $path instanceof String_ => [$path->value, $path->value],
            $path instanceof Dir => [dirname($file), self::DIR],
            default => NotGiven::value(),
        };
    }

    /**
     * Each place PHP looks for a path an include in a file writes, on disk.
     *
     * @return list<string>
     */
    public function candidates(string $path, string $file): array
    {
        $relative = ! str_starts_with($path, '/');
        $dotted = str_starts_with($path, './') || str_starts_with($path, '../');
        $found = $relative ? [$this->normalized(sprintf('%s/%s', $this->project, $path))] : [$this->normalized($path)];

        return $relative && ! $dotted
            ? array_values(array_unique([...$found, $this->normalized(sprintf('%s/%s', dirname($file), $path))]))
            : $found;
    }

    /** A path on disk from the project's root; nothing where it is outside the project. */
    public function inProject(string $path): string|NotGiven
    {
        $root = sprintf('%s/', $this->project);

        return str_starts_with($path, $root) ? mb_substr($path, mb_strlen($root)) : NotGiven::value();
    }

    /** A file as a message names it: from the project's root, where it is inside. */
    public function shown(string $file): string
    {
        $inProject = $this->inProject($file);

        return $inProject instanceof NotGiven ? $file : $inProject;
    }

    /**
     * Two literal halves of a path joined, as it reads and as it is written; nothing where either is built.
     *
     * @param  array{string, string}|NotGiven $left
     * @param  array{string, string}|NotGiven $right
     * @return array{string, string}|NotGiven
     */
    private function joined(array|NotGiven $left, array|NotGiven $right): array|NotGiven
    {
        return $left instanceof NotGiven || $right instanceof NotGiven
            ? NotGiven::value()
            : [sprintf('%s%s', $left[0], $right[0]), sprintf('%s%s', $left[1], $right[1])];
    }

    /** An absolute path with each `.` and `..` part taken away, as the file system would read it. */
    private function normalized(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);

                continue;
            }

            $parts = $part === '' || $part === '.' ? $parts : [...$parts, $part];
        }

        return sprintf('/%s', implode('/', $parts));
    }
}
