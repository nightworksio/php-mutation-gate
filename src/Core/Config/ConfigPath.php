<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_key_last;
use function array_pop;
use function array_slice;
use function count;
use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;
use function str_repeat;
use function str_starts_with;

/**
 * A path a config wrote, with the directory it was written from (ADR-0002):
 * `src` in `ci/mutation-gate.json` is `ci/src`, and `../src` there is `src`.
 * An absolute path is itself wherever it is written.
 */
final readonly class ConfigPath
{
    private const string UP = '..';

    private const string CURRENT = '.';

    /** @param string $directory where it is written from, from the project; '' for the project itself */
    private function __construct(private string $written, private string $directory)
    {
    }

    /** @param string $directory where it is written from, from the project; '' for the project itself */
    public static function of(string $written, string $directory): self
    {
        return new self($written, $directory);
    }

    /**
     * A path from the project, as a file in a directory of it writes it: `src` from `ci` is `../src`.
     *
     * @param string $directory the file's directory, from the project; '' for the project itself
     */
    public static function from(Path $path, string $directory): string
    {
        if ($directory === '' || str_starts_with($path->value(), '/')) {
            return $path->value();
        }

        $target = $path->value() === self::CURRENT ? [] : explode('/', $path->value());
        $base = explode('/', $directory);
        $shared = 0;

        while ($shared < count($base) && $shared < count($target) && $base[$shared] === $target[$shared]) {
            $shared++;
        }

        $up = str_repeat(sprintf('%s/', self::UP), count($base) - $shared);
        $down = implode('/', array_slice($target, $shared));

        return Path::of(sprintf('%s%s', $up, $down))->value();
    }

    /** The path from the project, each `..` taking back the directory before it. */
    public function path(): Path
    {
        $joined = $this->directory === '' || str_starts_with($this->written, '/')
            ? $this->written
            : sprintf('%s/%s', $this->directory, $this->written);
        $segments = [];

        foreach (explode('/', Path::of($joined)->value()) as $segment) {
            $last = $segments === [] ? self::UP : $segments[array_key_last($segments)];

            if ($segment === self::UP && $last !== self::UP && $last !== '') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return Path::of(implode('/', $segments));
    }
}
