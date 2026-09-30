<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Config;

use function array_key_exists;
use function array_last;
use function array_map;
use function array_pop;
use function array_reduce;

use Closure;

use function count;
use function dirname;
use function explode;
use function implode;
use function is_array;
use function is_string;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Definition\Json;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;
use function str_repeat;
use function str_starts_with;

/**
 * A config file's paths made relative to the project (ADR-0002): a path in a
 * config file is relative to the file, so `src` in `ci/mutation-gate.json`
 * is `ci/src`. Presets and the command line name paths from the project, and
 * are laid over the file only once its paths are.
 */
final readonly class Relocated
{
    private const string UP = '..';

    /** @param string $base the file's directory, from the project; '' for the project itself */
    private function __construct(private string $base)
    {
    }

    /** A config file's settings, with every path it names made relative to the project. */
    public static function file(Document $document, Path $file, string $project): Document|CannotJudge
    {
        $relocated = new self(self::directory($file, Path::of($project)->value()));

        return $relocated->base === ''
            ? $document
            : Document::ofJson(Json::encode(Json::object($relocated->tree(Json::decode($document->json())))));
    }

    /** A path from the project, as a config file at this path names it: from the file's directory. */
    public static function fromProject(string $path, Path $file, string $project): string
    {
        $base = self::directory($file, Path::of($project)->value());

        return match (true) {
            $base === '' => $path,
            str_starts_with($base, '/') => Path::of(sprintf('%s/%s', $project, $path))->value(),
            default => sprintf('%s%s', str_repeat('../', count(explode('/', $base))), $path),
        };
    }

    /** Every setting that names a path, relocated: those of the built-in adapters only where they are chosen. */
    private function tree(mixed $tree): mixed
    {
        $steps = [
            fn(mixed $config): mixed => $this->each($config, 'trees', 'path'),
            fn(mixed $config): mixed => $this->each($config, 'reports', 'path'),
            fn(mixed $config): mixed => $this->change($config, 'baseline', fn(mixed $baseline): mixed => $this->key(
                $baseline,
                'path',
            )),
            fn(mixed $config): mixed => $this->change($config, 'ci', fn(mixed $ci): mixed => $this->change(
                $ci,
                'gitlab',
                fn(mixed $gitlab): mixed => $this->key($gitlab, 'template'),
            )),
            fn(mixed $config): mixed => $this->change($config, 'treeSource', fn(mixed $source): mixed => $this->adapter(
                $source,
                'phpunit',
                'fallback',
            )),
            fn(mixed $config): mixed => $this->change($config, 'proofs', fn(mixed $proofs): mixed => $this->change(
                $proofs,
                'store',
                fn(mixed $store): mixed => $this->adapter($store, 'directory', 'path'),
            )),
        ];

        return array_reduce($steps, static fn(mixed $config, Closure $step): mixed => $step($config), $tree);
    }

    /** An object with the value under a key changed, where it has one. */
    private function change(mixed $object, string $key, Closure $change): mixed
    {
        if (is_array($object) && array_key_exists($key, $object)) {
            $object[$key] = $change($object[$key]);
        }

        return $object;
    }

    /** An object with every entry of the list under a key holding its path relocated. */
    private function each(mixed $object, string $list, string $key): mixed
    {
        return $this->change($object, $list, fn(mixed $entries): mixed => is_array($entries)
            ? array_map(fn(mixed $entry): mixed => $this->key($entry, $key), $entries)
            : $entries);
    }

    /** A built-in adapter chosen with its options, with the path, or the paths, under one of them relocated. */
    private function adapter(mixed $choice, string $builtin, string $option): mixed
    {
        return is_array($choice) && array_key_exists('use', $choice) && $choice['use'] === $builtin
            ? $this->change($choice, 'with', fn(mixed $with): mixed => $this->key($with, $option))
            : $choice;
    }

    /** An object with the path, or every path of the list, under a key relocated. */
    private function key(mixed $object, string $key): mixed
    {
        return $this->change(
            $object,
            $key,
            fn(mixed $value): mixed => is_array($value) ? array_map($this->path(...), $value) : $this->path($value),
        );
    }

    /** A path relative to the file, from the project; any other value as it is. */
    private function path(mixed $path): mixed
    {
        return is_string($path) && ! str_starts_with($path, '/')
            ? $this->resolved(sprintf('%s/%s', $this->base, $path))
            : $path;
    }

    /** A path with each `..` taking back the directory before it. */
    private function resolved(string $path): string
    {
        $segments = [];

        foreach (explode('/', Path::of($path)->value()) as $segment) {
            $last = $segments === [] ? '' : array_last($segments);

            if ($segment === self::UP && $last !== '' && $last !== self::UP) {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return Path::of(implode('/', $segments))->value();
    }

    /** The directory of a config file, from the project, or absolute when the file is outside it. */
    private static function directory(Path $file, string $project): string
    {
        $directory = dirname($file->value());

        return match (true) {
            $directory === $project => '',
            str_starts_with($directory, sprintf('%s/', $project)) => mb_substr($directory, mb_strlen($project) + 1),
            default => $directory,
        };
    }
}
