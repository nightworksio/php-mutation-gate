<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpStan;

use function array_key_exists;
use function extension_loaded;
use function glob;
use function is_dir;
use function is_file;
use function mb_strtolower;
use function mkdir;

use NightWorksIO\MutationGate\Core\Doctor\Check\Xdebug;
use NightWorksIO\MutationGate\Core\File\FileMode;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\Opcache;

use const PHP_MAJOR_VERSION;
use const PHP_MINOR_VERSION;
use const PHP_OS_FAMILY;

use function php_uname;

use const PHP_ZTS;

use function sprintf;

/**
 * The ini settings a PHPStan process runs under, as `-d` arguments: those
 * `staticCheck.php` names, an opcache file cache under the gate's workspace
 * where it asks for one alone and names no directory, and PHPStan's turbo
 * extension where `staticCheck.turbo` is on, PHPStan ships a build for this
 * PHP and machine, and it is not loaded already; Xdebug's mode, where they
 * name one, also as its variable. None of them changes a finding; each saves
 * PHPStan the restart it otherwise makes (ADR-0020).
 *
 * @internal
 */
final readonly class PhpIni
{
    /** The setting of Xdebug's mode, which its variable overrides. */
    public const string XDEBUG_MODE = Xdebug::MODE_SETTING;

    private const string FILE_CACHE = Opcache::FILE_CACHE;

    private const string FILE_CACHE_ONLY = 'opcache.file_cache_only';

    private const string TURBO = 'phpstan_turbo';

    /** Where PHPStan keeps its turbo builds, one directory a platform. */
    private const string BUILDS = '%s/vendor/phpstan/phpstan/turbo-ext/%s/phpstan_turbo-%d.%d%s.%s';

    /** @param array<string, string> $settings */
    private function __construct(private array $settings, private bool $turbo)
    {
    }

    public static function none(): self
    {
        return new self([], turbo: false);
    }

    /** The settings as `staticCheck.php` writes them. */
    public static function of(Node $written, bool $turbo): self
    {
        $settings = [];

        foreach (Lenient::entries($written) as $name => $value) {
            $settings[sprintf('%s', $name)] = self::spelt($value);
        }

        return new self($settings, $turbo);
    }

    /**
     * The `-d` arguments for a process in this project, its file cache under this directory.
     *
     * @return list<string>
     */
    public function arguments(string $root, string $cache): array
    {
        $settings = $this->withFileCache($cache);
        $turbo = $this->turbo && ! extension_loaded(self::TURBO) ? $this->build($root) : '';
        $arguments = [];

        foreach ([...$settings, ...$turbo === '' ? [] : ['extension' => $turbo]] as $name => $value) {
            $arguments[] = sprintf('-d%s=%s', $name, $value);
        }

        return $arguments;
    }

    /**
     * A process's environment under these settings: Xdebug's variable set to
     * the mode they name, since the variable overrides the setting.
     *
     * @param  array<string, string> $environment
     * @return array<string, string>
     */
    public function environment(array $environment): array
    {
        return array_key_exists(self::XDEBUG_MODE, $this->settings)
            ? [...$environment, Xdebug::MODE_VARIABLE => $this->settings[self::XDEBUG_MODE]]
            : $environment;
    }

    /**
     * The settings, with the file cache under this directory, made, where they ask for a file cache alone and name
     * no directory.
     *
     * @return array<string, string>
     */
    private function withFileCache(string $cache): array
    {
        $settings = $this->settings;
        $alone = array_key_exists(self::FILE_CACHE_ONLY, $settings) && $settings[self::FILE_CACHE_ONLY] === '1';

        if (! $alone || array_key_exists(self::FILE_CACHE, $settings)) {
            return $settings;
        }

        if (! is_dir($cache)) {
            mkdir($cache, FileMode::OWNER_ONLY, recursive: true);
        }

        return [...$settings, self::FILE_CACHE => $cache];
    }

    /** PHPStan's turbo build for this PHP and machine, where it ships one; none otherwise. */
    private function build(string $root): string
    {
        $platform = $this->platform();
        $built = $platform === '' ? '' : sprintf(
            self::BUILDS,
            $root,
            $platform,
            PHP_MAJOR_VERSION,
            PHP_MINOR_VERSION,
            PHP_ZTS === 1 ? '-zts' : '',
            PHP_OS_FAMILY === 'Windows' ? 'dll' : 'so',
        );

        return $built !== '' && is_file($built) ? $built : '';
    }

    /** The name PHPStan gives this machine's platform among its builds; none where it ships none for it. */
    private function platform(): string
    {
        $machine = match (mb_strtolower(php_uname('m'))) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'arm64',
            default => '',
        };

        return match (true) {
            $machine === '' => '',
            PHP_OS_FAMILY === 'Darwin' => $machine === 'arm64' ? 'macos-arm64' : '',
            PHP_OS_FAMILY === 'Windows' => $machine === 'x86_64' ? 'windows-x86_64' : '',
            PHP_OS_FAMILY === 'Linux' => sprintf('linux-%s-%s', $this->isMusl() ? 'musl' : 'gnu', $machine),
            default => '',
        };
    }

    /** Whether this Linux links against musl, as Alpine's does, by its loader. */
    private function isMusl(): bool
    {
        $loaders = glob('/lib/ld-musl-*');

        return $loaders !== false && $loaders !== [];
    }

    /** A setting's value as a `-d` argument spells it; none where it is not a single value. */
    private static function spelt(Node $value): string
    {
        return match ($value->kind()) {
            Kind::Boolean => $value->boolean() ? '1' : '0',
            Kind::Text => $value->text(),
            Kind::Integer => sprintf('%d', $value->integer()),
            Kind::Number => sprintf('%s', $value->number()),
            Kind::Map, Kind::List, Kind::Empty, Kind::Null, Kind::Nothing => '',
        };
    }
}
