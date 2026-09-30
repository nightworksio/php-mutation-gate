<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_last;
use function array_map;
use function implode;
use function ksort;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match_all;
use function sprintf;
use function trim;

/**
 * A PHP as it describes itself: its version, its extensions and their
 * versions, its ini settings, the php.ini it loads, and the operating system
 * family and architecture under it. A runner's is the PHP it starts, as that
 * PHP describes itself when started the way the runner starts it: the gate's
 * own PHP can differ, by a `-d` on the gate's command line or a variable
 * withheld. Its text, which a proof's key reads, leaves out the inert
 * settings and where php.ini is.
 */
final readonly class Platform
{
    /** The line a PHP describes itself on, among whatever else it prints. */
    private const string LINE = '/^mutation-gate platform (?<json>.*)$/m';

    /**
     * The code a PHP runs to describe itself: one line, which `LINE` finds,
     * of JSON holding its version, extensions, settings, php.ini and system.
     */
    private const string DESCRIBING = <<<'CODE'
        $extensions = [];
        foreach (get_loaded_extensions() as $extension) {
            $extensions[$extension] = (string) phpversion($extension);
        }
        $ini = [];
        foreach (array_keys(ini_get_all() ?: []) as $setting) {
            $ini[$setting] = (string) ini_get((string) $setting);
        }
        echo "\n", 'mutation-gate platform ', json_encode([
            'php' => PHP_VERSION,
            'extensions' => $extensions,
            'ini' => $ini,
            'system' => PHP_OS_FAMILY,
            'architecture' => php_uname('m'),
            ...(php_ini_loaded_file() === false ? [] : ['iniFile' => php_ini_loaded_file()]),
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES), "\n";
        CODE;

    private const string SILENT = 'it printed no description of itself:%s%s';

    private const string GARBLED = 'its description is not in the form the gate reads: %s';

    private const string UNKEYED = 'The PHP the runner starts could not describe itself, so no proof can be keyed: %s';

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
        private string|NotGiven $iniFile,
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

        return new self($php, $extensions, $ini, $system, $architecture, NotGiven::value());
    }

    /**
     * The arguments that make a PHP describe itself, which a runner passes
     * the PHP it starts, with every option it starts it with.
     *
     * @return list<string>
     */
    public static function describing(): array
    {
        return ['-r', self::DESCRIBING];
    }

    /** The PHP that printed what the arguments of `describing()` make it print, among whatever else it printed. */
    public static function describedBy(string $output): self|CannotJudge
    {
        preg_match_all(self::LINE, $output, $lines);
        $described = array_last($lines['json']);

        if ($described === null) {
            return CannotJudge::because(sprintf(self::SILENT, "\n", trim($output)));
        }

        try {
            return self::read(Node::decode($described));
        } catch (NotInShape $garbled) {
            return CannotJudge::because(sprintf(self::GARBLED, $garbled->getMessage()));
        }
    }

    /** The PHP a runner starts, from what it printed started with `describing()`, or why no proof can be keyed. */
    public static function ofRunner(string $output): self|CannotJudge
    {
        $platform = self::describedBy($output);

        return $platform instanceof CannotJudge
            ? CannotJudge::because(sprintf(self::UNKEYED, $platform->why()))
            : $platform;
    }

    /** This PHP, loading a php.ini. */
    public function loadingIni(string $file): self
    {
        return clone($this, ['iniFile' => $file]);
    }

    /** @return array<string, string> each extension it loads, with its version, by name */
    public function extensions(): array
    {
        return $this->extensions;
    }

    /** @return array<string, string> each setting's value, by name, the inert ones too */
    public function settings(): array
    {
        return $this->ini;
    }

    /** The php.ini it loads, where it loads one. */
    public function iniFile(): string|NotGiven
    {
        return $this->iniFile;
    }

    /** One line per fact a proof's key reads, in a fixed order, so equal platforms read alike. */
    public function text(): string
    {
        $lines = [sprintf('php %s', $this->php), sprintf('os %s %s', $this->system, $this->architecture)];

        foreach ($this->extensions as $name => $version) {
            $lines[] = sprintf('extension %s %s', $name, $version);
        }

        foreach ($this->ini as $name => $value) {
            if (! InertSetting::tryFrom($name) instanceof InertSetting) {
                $lines[] = sprintf('ini %s=%s', $name, $value);
            }
        }

        return implode("\n", $lines);
    }

    public function digest(): Digest
    {
        return Digest::sha256Of($this->text());
    }

    /** @throws NotInShape */
    private static function read(Node $described): self
    {
        $platform = self::of(
            $described->field('php')->text(),
            self::texts($described->field('extensions')),
            self::texts($described->field('ini')),
            $described->field('system')->text(),
            $described->field('architecture')->text(),
        );
        $ini = $described->field('iniFile');

        return $ini->isPresent() ? $platform->loadingIni($ini->text()) : $platform;
    }

    /**
     * @return array<string, string>
     *
     * @throws NotInShape
     */
    private static function texts(Node $map): array
    {
        return array_map(static fn(Node $entry): string => $entry->text(), $map->entries());
    }
}
