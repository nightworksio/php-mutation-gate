<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_keys;
use function get_loaded_extensions;
use function implode;
use function ini_get;
use function ini_get_all;
use function is_array;
use function ksort;

use NightWorksIO\MutationGate\Core\File\Digest;

use function php_uname;
use function phpversion;
use function sprintf;

/**
 * The PHP Pest runs on: its version, its extensions and their versions, its
 * ini settings, and the operating system family and architecture under it.
 */
final readonly class Platform
{
    /**
     * @param array<string, string> $extensions each extension's version, by name
     * @param array<string, string> $ini        each setting's value, by name, empty where it has none
     */
    private function __construct(
        private string $php,
        private array $extensions,
        private array $ini,
        private string $system,
        private string $architecture,
    ) {
    }

    /**
     * @param array<string, string> $extensions
     * @param array<string, string> $ini
     */
    public static function of(string $php, array $extensions, array $ini, string $system, string $architecture): self
    {
        ksort($extensions);
        ksort($ini);

        return new self($php, $extensions, $ini, $system, $architecture);
    }

    /** The PHP running this process, which is the PHP the adapter starts Pest on. */
    public static function current(): self
    {
        $extensions = [];

        foreach (get_loaded_extensions() as $extension) {
            $extensions[$extension] = sprintf('%s', phpversion($extension));
        }

        $settings = ini_get_all(details: false);
        $ini = [];

        foreach (array_keys(is_array($settings) ? $settings : []) as $name) {
            $setting = sprintf('%s', $name);
            $ini[$setting] = sprintf('%s', ini_get($setting));
        }

        return self::of(PHP_VERSION, $extensions, $ini, PHP_OS_FAMILY, php_uname('m'));
    }

    /** One line per fact, in a fixed order, so equal platforms read alike. */
    public function text(): string
    {
        $lines = [sprintf('php %s', $this->php), sprintf('os %s %s', $this->system, $this->architecture)];

        foreach ($this->extensions as $name => $version) {
            $lines[] = sprintf('extension %s %s', $name, $version);
        }

        foreach ($this->ini as $name => $value) {
            $lines[] = sprintf('ini %s=%s', $name, $value);
        }

        return implode("\n", $lines);
    }

    public function digest(): Digest
    {
        return Digest::sha256Of($this->text());
    }
}
