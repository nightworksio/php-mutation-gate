<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Neon;

use function file_get_contents;
use function is_file;
use function is_string;
use function json_decode;

use Nette\Neon\Exception;
use Nette\Neon\Neon;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\ConfigReads;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Parsed;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Port\ConfigLoader;

use function sprintf;

/**
 * A `mutation-gate.neon`, read with `nette/neon`, which is optional. NEON
 * reads a date as a date by itself, and it is written `YYYY-MM-DD` again.
 */
final readonly class NeonConfig implements ConfigLoader
{
    private const string INDENT = '    ';

    /** NEON's `includes:` is no key of the gate's config, which refuses it, so a NEON config names no other file. */
    public function reads(ConfigFile $file): ConfigReads
    {
        return ConfigReads::none();
    }

    public function load(ConfigFile $file): Layer|Invalid|CannotJudge
    {
        $path = $file->file()->value();
        $text = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($text)) {
            return CannotJudge::because(sprintf('%s could not be read.', $path));
        }

        try {
            $json = Parsed::json(Neon::decode($text), $path);
        } catch (Exception $exception) {
            return CannotJudge::because(sprintf('%s is not NEON: %s', $path, $exception->getMessage()));
        }

        return $json instanceof Json ? $file->read($json) : $json;
    }

    /** A config written as NEON, as `init` and `config:show` write it. */
    public function render(Json $config): string
    {
        return Neon::encode(
            json_decode($config->line(), associative: true),
            blockMode: true,
            indentation: self::INDENT,
        );
    }
}
