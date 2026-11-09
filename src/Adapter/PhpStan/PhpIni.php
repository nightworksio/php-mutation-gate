<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpStan;

use function array_key_exists;
use function extension_loaded;
use function glob;
use function is_dir;
use function is_file;
use function is_scalar;
use function mkdir;
use function php_uname;
use function sprintf;
use function strtolower;

/**
 * The ini settings a PHPStan process runs under, as `-d` arguments: those
 * `staticCheck.php` names, an opcache file cache under the gate's workspace
 * where it asks for one alone and names no directory, and PHPStan's turbo
 * extension where `staticCheck.turbo` is on, PHPStan ships a build for this
 * PHP and machine, and it is not loaded already. None of them changes a
 * finding; each saves PHPStan the restart it otherwise makes (ADR-0020).
 *
 * @internal
 */
final readonly class PhpIni
{
    private const string FILE_CACHE = 'opcache.file_cache';

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

    /** @param array<mixed> $written the settings as `staticCheck.php` writes them */
    public static function of(array $written, bool $turbo): self
    {
        $settings = [];

        foreach ($written as $name => $value) {
            $settings[sprintf('%s', $name)] = is_scalar($value) ? self::spelt($value) : '';
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
        $settings = $this->settings;

        if (($settings[self::FILE_CACHE_ONLY] ?? '') === '1' && ! array_key_exists(self::FILE_CACHE, $settings)) {
            $settings[self::FILE_CACHE] = $cache;

            if (! is_dir($cache)) {
                mkdir($cache, 0o700, recursive: true);
            }
        }

        $turbo = $this->turbo && ! extension_loaded(self::TURBO) ? self::build($root) : '';
        $arguments = [];

        foreach ([...$settings, ...$turbo === '' ? [] : ['extension' => $turbo]] as $name => $value) {
            $arguments[] = sprintf('-d%s=%s', $name, $value);
        }

        return $arguments;
    }

    /** PHPStan's turbo build for this PHP and machine, where it ships one; none otherwise. */
    private static function build(string $root): string
    {
        $platform = self::platform();
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
    private static function platform(): string
    {
        $machine = match (strtolower(php_uname('m'))) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'arm64',
            default => '',
        };

        return match (true) {
            $machine === '' => '',
            PHP_OS_FAMILY === 'Darwin' => $machine === 'arm64' ? 'macos-arm64' : '',
            PHP_OS_FAMILY === 'Windows' => $machine === 'x86_64' ? 'windows-x86_64' : '',
            PHP_OS_FAMILY === 'Linux' => sprintf('linux-%s-%s', self::isMusl() ? 'musl' : 'gnu', $machine),
            default => '',
        };
    }

    /** Whether this Linux links against musl, as Alpine's does, by its loader. */
    private static function isMusl(): bool
    {
        $loaders = glob('/lib/ld-musl-*');

        return $loaders !== false && $loaders !== [];
    }

    private static function spelt(string|int|float|bool $value): string
    {
        return match (true) {
            $value === true => '1',
            $value === false => '0',
            default => sprintf('%s', $value),
        };
    }
}
