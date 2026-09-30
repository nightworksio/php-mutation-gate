<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Composer;

use function array_key_exists;
use function array_keys;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;

use JsonException;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

use function sprintf;

/**
 * A `composer.json`, read for what the gate asks of it: the paths its
 * autoload names, the packages it requires, its path repositories, and the
 * floors it declares under `extra.mutation-gate`.
 */
final readonly class Manifest
{
    /** Where Composer installs a project's packages when nothing names another directory. */
    public const string VENDOR = 'vendor';

    /** The file every project and module declares itself in. */
    private const string FILE = 'composer.json';

    /** The autoload keys whose values are paths. */
    private const array AUTOLOAD = ['psr-4', 'psr-0', 'classmap', 'files'];

    /** The keys whose values are the packages a manifest requires. */
    private const array REQUIRES = ['require', 'require-dev'];

    /** The highest floor there is. */
    private const int WHOLE = 100;

    /** Why a floor cannot be used. */
    private const string NOT_A_FLOOR = '%s: extra.mutation-gate.%s is not a number from 0 to 100.';

    /** Why a floor of 0 cannot be used. */
    private const string NO_REASON = '%s declares extra.mutation-gate.floor as 0 without a floorReason beside it.';

    /** Why a manifest cannot be read. */
    private const string NOT_AN_OBJECT = '%s is not a JSON object.';

    /** @param array<mixed> $data */
    private function __construct(private Path $directory, private array $data)
    {
    }

    /** The manifest in a directory, where there is one. */
    public static function in(Disk $disk, Path $directory): self|Missing|CannotJudge
    {
        $read = $disk->read(self::fileIn($directory));

        return is_string($read) ? self::decoded($directory, $read) : $read;
    }

    public function directory(): Path
    {
        return $this->directory;
    }

    /** Where Composer installs the project's packages: its `config.vendor-dir`, or `vendor`. */
    public function vendorDirectory(): Path
    {
        $declared = $this->at('config', 'vendor-dir');

        return Path::of(is_string($declared) && $declared !== '' ? $declared : self::VENDOR);
    }

    /** The package's name, or nothing where it declares none. */
    public function name(): string
    {
        $name = $this->at('name');

        return is_string($name) ? $name : '';
    }

    /** Every path the manifest's autoload names, spelt from the repository's root. */
    public function autoloaded(): Paths
    {
        $paths = [];

        foreach (self::AUTOLOAD as $kind) {
            foreach (Json::strings($this->at('autoload', $kind)) as $path) {
                $paths[] = Path::of(sprintf('%s/%s', $this->directory->value(), $path));
            }
        }

        return Paths::of(...$paths);
    }

    /**
     * Every package the manifest requires, in `require` or `require-dev`, by name.
     *
     * @return list<string>
     */
    public function requires(): array
    {
        $names = [];

        foreach (self::REQUIRES as $kind) {
            $required = $this->at($kind);

            foreach (is_array($required) ? array_keys($required) : [] as $name) {
                $names = is_string($name) ? [...$names, $name] : $names;
            }
        }

        return $names;
    }

    /**
     * The `url` of every repository of type `path`: a directory, or a shell glob.
     *
     * @return list<string>
     */
    public function pathRepositories(): array
    {
        $urls = [];
        $repositories = $this->at('repositories');

        foreach (is_array($repositories) ? $repositories : [] as $repository) {
            $url = Json::field($repository, 'url');
            $urls = Json::field($repository, 'type') === 'path' && is_string($url) ? [...$urls, $url] : $urls;
        }

        return $urls;
    }

    /** The floor `extra.mutation-gate.floor` declares, with `floorReason` for a floor of 0. */
    public function floor(): Floor|Exempt|Undeclared|CannotJudge
    {
        $floor = $this->floorAt('floor');
        $reason = $this->at('extra', 'mutation-gate', 'floorReason');

        return match (true) {
            ! $floor instanceof Floor || $floor->hundredths() !== 0 => $floor,
            is_string($reason) && $reason !== '' => Exempt::because($reason),
            default => CannotJudge::because(sprintf(self::NO_REASON, self::fileIn($this->directory)->value())),
        };
    }

    /** The floor `extra.mutation-gate.newCodeFloor` declares for the new lines of the trees below it. */
    public function newCodeFloor(): Floor|Undeclared|CannotJudge
    {
        return $this->floorAt('newCodeFloor');
    }

    private function floorAt(string $key): Floor|Undeclared|CannotJudge
    {
        $extra = $this->at('extra', 'mutation-gate');

        if (! is_array($extra) || ! array_key_exists($key, $extra)) {
            return Undeclared::floor();
        }

        $floor = $extra[$key];

        return (is_int($floor) || is_float($floor)) && $floor >= 0 && $floor <= self::WHOLE
            ? Floor::of($floor)
            : CannotJudge::because(sprintf(self::NOT_A_FLOOR, self::fileIn($this->directory)->value(), $key));
    }

    private function at(string ...$keys): mixed
    {
        $value = $this->data;

        foreach ($keys as $key) {
            $value = Json::field($value, $key);
        }

        return $value;
    }

    private static function decoded(Path $directory, string $text): self|CannotJudge
    {
        try {
            return self::objectIn($directory, json_decode($text, associative: true, flags: JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return self::notAnObject($directory);
        }
    }

    private static function objectIn(Path $directory, mixed $data): self|CannotJudge
    {
        return is_array($data) ? new self($directory, $data) : self::notAnObject($directory);
    }

    private static function notAnObject(Path $directory): CannotJudge
    {
        return CannotJudge::because(sprintf(self::NOT_AN_OBJECT, self::fileIn($directory)->value()));
    }

    private static function fileIn(Path $directory): Path
    {
        return Path::of(sprintf('%s/%s', $directory->value(), self::FILE));
    }
}
