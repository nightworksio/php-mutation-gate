<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_file;

use NightWorksIO\MutationGate\Cli\Config\ConfigLocation;
use NightWorksIO\MutationGate\Cli\Config\Formats;
use NightWorksIO\MutationGate\Cli\Config\NoConfigFile;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;

use function pathinfo;
use function sprintf;

/**
 * Where `init` writes a config (ADR-0002): `mutation-gate.<format>` in the
 * project, or the file `--config` names, in the format its extension names.
 */
final readonly class Destination
{
    private function __construct(
        private Path $file,
        private Format $format,
        private string $shown,
        private Path|NoConfigFile|CannotJudge $existing,
    ) {
    }

    /**
     * @param string|NotGiven $config the file `--config` names
     * @param string          $format what `--format` says, which `--config` overrides
     */
    public static function of(string $project, string|NotGiven $config, string $format): self|CannotJudge
    {
        return $config instanceof NotGiven
            ? self::named($project, Formats::chosen($format))
            : self::given(ConfigLocation::named($project, $config), $config);
    }

    public function file(): Path
    {
        return $this->file;
    }

    public function format(): Format
    {
        return $this->format;
    }

    /** The file as the person named it, or as `init` names it. */
    public function shown(): string
    {
        return $this->shown;
    }

    /** A config already there, which `init` never replaces; or why it cannot tell; or none. */
    public function existing(): Path|NoConfigFile|CannotJudge
    {
        return $this->existing;
    }

    /** `mutation-gate.<format>` in the project, which is refused where a config is already there. */
    private static function named(string $project, Format|CannotJudge $format): self|CannotJudge
    {
        return $format instanceof Format
            ? new self(
                Path::of(sprintf('%s/%s', $project, $format->fileName())),
                $format,
                $format->fileName(),
                ConfigLocation::in($project, NotGiven::value()),
            )
            : $format;
    }

    /** The file `--config` names, in the format its extension names. */
    private static function given(Path $file, string $config): self|CannotJudge
    {
        $format = Format::fromExtension(pathinfo($file->value(), PATHINFO_EXTENSION));

        return $format instanceof Format
            ? new self($file, $format, $config, is_file($file->value()) ? $file : NoConfigFile::there())
            : CannotJudge::because(sprintf(
                '%s is in no format init writes. Name a .php, .json, .yaml, .yml or .neon file.',
                $config,
            ));
    }
}
