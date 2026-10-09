<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;
use function is_string;
use function mb_strtoupper;

use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Format\Series;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\ThisPackage;

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
    private const string STEM = ThisPackage::NAME;

    /** The other extension a YAML file is written with. */
    private const string YML = 'yml';

    /** A comment, set off from what comes before it by a blank line. */
    private const string COMMENT = "\n%s";

    private const string LINE = "%s%s %s\n";

    private const string NO_COMMENTS = 'JSON holds no comments.';

    /** The package whose own code reads PHP and JSON. */
    private const string THIS_PACKAGE = ThisPackage::COMPOSER;

    /** The format a file's extension names, `.yml` being YAML; or none of these. */
    public static function fromExtension(string $extension): self|Absent
    {
        return self::tryFrom($extension === self::YML ? self::Yaml->value : $extension) ?? Absent::setting();
    }

    /** The words `--format` takes, as a sentence lists them: php, json, yaml or neon. */
    public static function words(): string
    {
        return Series::or(...array_map(static fn(self $format): string => $format->value, self::cases()));
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

    /**
     * These lines as a comment in a file of this format, set off by a blank
     * line; none in JSON, which holds no comments.
     *
     * @param list<string> $lines
     */
    public function commented(array $lines): string|NotWritten
    {
        $marker = match ($this) {
            self::Php => '//',
            self::Yaml, self::Neon => '#',
            self::Json => NotWritten::because(self::NO_COMMENTS),
        };

        return is_string($marker) ? self::comment($marker, $lines) : $marker;
    }

    /** Whether it is read by a library a project installs only to write its config this way. */
    public function isSuggested(): bool
    {
        return $this->package() !== self::THIS_PACKAGE;
    }

    /**
     * These lines, each opened by this marker, set off by a blank line.
     *
     * @param list<string> $lines
     */
    private static function comment(string $marker, array $lines): string
    {
        $commented = '';

        foreach ($lines as $line) {
            $commented = sprintf(self::LINE, $commented, $marker, Fit::commentLine($line));
        }

        return sprintf(self::COMMENT, $commented);
    }

    /** @return list<string> the names a config file in this format has */
    private function names(): array
    {
        $extensions = $this === self::Yaml ? [$this->value, self::YML] : [$this->value];

        return array_map(static fn(string $extension): string => sprintf('%s.%s', self::STEM, $extension), $extensions);
    }
}
