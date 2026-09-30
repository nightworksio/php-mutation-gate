<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;
use function mb_strtoupper;
use function sprintf;

/**
 * The four formats a config file is written in (ADR-0002), each by the word
 * `--format` takes and the extension its file has. YAML and NEON are read by
 * a library the gate only suggests.
 */
enum Format: string
{
    case Php = 'php';
    case Json = 'json';
    case Yaml = 'yaml';
    case Neon = 'neon';

    /** What a config file is called, before its extension. */
    private const string STEM = 'mutation-gate';

    /** The other extension a YAML file is written with. */
    private const string YML = 'yml';

    /** The package whose own code reads PHP and JSON. */
    private const string THIS_PACKAGE = 'nightworksio/mutation-gate';

    /** The format a file's extension names, `.yml` being YAML; or none of these. */
    public static function fromExtension(string $extension): self|Absent
    {
        return self::tryFrom($extension === self::YML ? self::Yaml->value : $extension) ?? Absent::setting();
    }

    /** @return list<string> every name a config file in the project has, in the order the formats are listed */
    public static function fileNames(): array
    {
        $names = [];

        foreach (self::cases() as $format) {
            $names = [...$names, ...$format->names()];
        }

        return $names;
    }

    /** The file `init` writes in this format. */
    public function fileName(): string
    {
        return sprintf('%s.%s', self::STEM, $this->value);
    }

    /** What a person calls it: PHP, JSON, YAML or NEON. */
    public function title(): string
    {
        return mb_strtoupper($this->value);
    }

    /** The Composer package that reads it: this one for PHP and JSON, a suggested one for YAML and NEON. */
    public function package(): string
    {
        return match ($this) {
            self::Php, self::Json => self::THIS_PACKAGE,
            self::Yaml => 'symfony/yaml',
            self::Neon => 'nette/neon',
        };
    }

    /** Whether it is read by a library a project installs only to write its config this way. */
    public function isSuggested(): bool
    {
        return $this->package() !== self::THIS_PACKAGE;
    }

    /** @return list<string> the names a config file in this format has */
    private function names(): array
    {
        $extensions = $this === self::Yaml ? [$this->value, self::YML] : [$this->value];

        return array_map(static fn(string $extension): string => sprintf('%s.%s', self::STEM, $extension), $extensions);
    }
}
