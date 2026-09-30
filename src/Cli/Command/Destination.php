<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_file;

use NightWorksIO\MutationGate\Cli\Config\ConfigFile;
use NightWorksIO\MutationGate\Cli\Config\Formats;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;

/**
 * Where `init` writes a config (ADR-0002): `mutation-gate.<format>` in the
 * project, or the file `--config` names, in the format its extension names.
 */
final readonly class Destination
{
    private function __construct(
        private Path $file,
        private string $format,
        private string $shown,
        private Path|Absent|CannotJudge $existing,
    ) {
    }

    /**
     * @param string $config the file `--config` names, or ''
     * @param string $format what `--format` says, which `--config` overrides
     */
    public static function of(string $project, string $config, string $format): self
    {
        if ($config === '') {
            $name = sprintf('mutation-gate.%s', $format);

            return new self(
                Path::of(sprintf('%s/%s', $project, $name)),
                $format,
                $name,
                ConfigFile::in($project, ''),
            );
        }

        $file = ConfigFile::named($project, $config);

        return new self($file, Formats::of($file), $config, is_file($file->value()) ? $file : Absent::setting());
    }

    public function file(): Path
    {
        return $this->file;
    }

    public function format(): string
    {
        return $this->format;
    }

    /** The file as the person named it, or as `init` names it. */
    public function shown(): string
    {
        return $this->shown;
    }

    /** A config already there, which `init` never replaces; or why it cannot tell; or nothing. */
    public function existing(): Path|Absent|CannotJudge
    {
        return $this->existing;
    }
}
