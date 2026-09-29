<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Project;

use function array_key_exists;
use function dirname;
use function file_get_contents;
use function is_array;
use function is_dir;
use function is_file;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Definition\Json;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;

use function sprintf;

/**
 * A project's Composer manifests: the `autoload` paths of its root
 * `composer.json`, and the floor each tree declares in the nearest
 * `composer.json` above it, under `extra.mutation-gate` (ADR-0005).
 */
final readonly class Manifests
{
    private const string MANIFEST = 'composer.json';

    private const array AUTOLOAD = ['psr-4', 'psr-0', 'classmap', 'files'];

    private const int NONE = 0;

    private const int WHOLE = 100;

    private function __construct(private string $root)
    {
    }

    public static function in(string $root): self
    {
        return new self($root);
    }

    /** Every path the root `composer.json` autoloads, not `autoload-dev`, in the order it lists them. */
    public function autoloaded(): Paths|CannotJudge
    {
        $manifest = $this->read(Path::root());

        if (! is_array($manifest)) {
            return $manifest instanceof CannotJudge ? $manifest : Paths::none();
        }

        $paths = Paths::none();

        foreach (self::AUTOLOAD as $kind) {
            foreach (self::strings(self::under($manifest, ['autoload', $kind])) as $path) {
                $paths = $paths->with(Path::of($path));
            }
        }

        return $paths;
    }

    /** One tree per path, each with the floor the nearest manifest above it declares, in the root package. */
    public function trees(Paths $paths): Trees|CannotJudge
    {
        $trees = Trees::none();

        foreach ($paths as $path) {
            $declared = $this->declaredFor($path);

            if ($declared instanceof CannotJudge) {
                return $declared;
            }

            $trees = $trees->with(Tree::at($path, $declared, Package::at(Path::root())));
        }

        return $trees;
    }

    private function declaredFor(Path $tree): Floor|Exempt|Undeclared|CannotJudge
    {
        $start = is_dir(sprintf('%s/%s', $this->root, $tree->value())) ? $tree->value() : dirname($tree->value());

        foreach (self::upFrom($start) as $directory) {
            $declared = $this->declaredIn(Path::of($directory));

            if (! $declared instanceof Undeclared) {
                return $declared;
            }
        }

        return Undeclared::floor();
    }

    private function declaredIn(Path $directory): Floor|Exempt|Undeclared|CannotJudge
    {
        $manifest = $this->read($directory);

        return match (true) {
            is_array($manifest) => self::floor(
                self::under($manifest, ['extra', 'mutation-gate']),
                Path::of(sprintf('%s/%s', $directory->value(), self::MANIFEST))->value(),
            ),
            $manifest instanceof CannotJudge => $manifest,
            default => Undeclared::floor(),
        };
    }

    /** @return array<mixed>|Absent|CannotJudge */
    private function read(Path $directory): array|Absent|CannotJudge
    {
        $file = sprintf('%s/%s/%s', $this->root, $directory->value(), self::MANIFEST);

        if (! is_file($file)) {
            return Absent::setting();
        }

        $manifest = json_decode((string) file_get_contents($file), associative: true);

        return is_array($manifest)
            ? $manifest
            : CannotJudge::because(sprintf(
                '%s is not a JSON object.',
                Path::of(sprintf('%s/%s', $directory->value(), self::MANIFEST))->value(),
            ));
    }

    private static function floor(mixed $settings, string $file): Floor|Exempt|Undeclared|CannotJudge
    {
        $floor = self::under($settings, ['floor']);
        $reason = self::under($settings, ['floorReason']);

        return match (true) {
            $floor instanceof Absent => Undeclared::floor(),
            ! self::isPercentage($floor) => CannotJudge::because(sprintf(
                '%s declares extra.mutation-gate.floor as %s, which is not a number from 0 to 100.',
                $file,
                Json::encode($floor),
            )),
            $floor > self::NONE => Floor::of($floor),
            is_string($reason) && $reason !== '' => Exempt::because($reason),
            default => CannotJudge::because(sprintf(
                '%s declares a floor of 0 without the reason extra.mutation-gate.floorReason gives it.',
                $file,
            )),
        };
    }

    /** @phpstan-assert-if-true int|float $floor */
    private static function isPercentage(mixed $floor): bool
    {
        return (is_int($floor) || is_float($floor)) && $floor >= self::NONE && $floor <= self::WHOLE;
    }

    /**
     * What a decoded manifest holds under these keys, or none.
     *
     * @param list<string> $keys
     */
    private static function under(mixed $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (! is_array($data) || ! array_key_exists($key, $data)) {
                return Absent::setting();
            }

            $data = $data[$key];
        }

        return $data;
    }

    /**
     * The strings an autoload entry names: a map's values, or a list's entries, each a string or a list of them.
     *
     * @return list<string>
     */
    private static function strings(mixed $entry): array
    {
        $strings = [];

        foreach (is_array($entry) ? $entry : [] as $value) {
            foreach (is_array($value) ? $value : [$value] as $path) {
                if (is_string($path)) {
                    $strings[] = $path;
                }
            }
        }

        return $strings;
    }

    /**
     * A directory and every directory above it, to the top.
     *
     * @return list<string>
     */
    private static function upFrom(string $directory): array
    {
        $parent = dirname($directory);

        return $parent === $directory ? [$directory] : [$directory, ...self::upFrom($parent)];
    }
}
