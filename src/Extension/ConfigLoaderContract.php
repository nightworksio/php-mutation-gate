<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Extension;

use function array_map;
use function dirname;
use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Port\ConfigLoader;

use function sprintf;

/**
 * What every config loader answers (ADR-0002), for the gate's own loaders and
 * an extension's to be held to alike. A loader reads a file through
 * `ConfigFile::read()`, so the gate's definition judges it. The fixtures are
 * two files in the loader's format, in a directory of their own:
 *
 * - `valid.<extension>` writes `{"runner": "pest", "trees": [{"path": "src",
 *   "floor": 100}], "newCode": {"floor": 100}}`;
 * - `invalid.<extension>` writes `{"newCode": {"floor": 120}}`.
 *
 * A loader keeps the contract where the valid file reads into that layer,
 * with its path named from the file's directory wherever the project is; the
 * invalid file reads into the problem the gate finds in it; and a file that
 * is not there cannot be judged.
 */
final readonly class ConfigLoaderContract
{
    private const string VALID = '{"runner":"pest","trees":[{"path":"src","floor":100}],"newCode":{"floor":100}}';

    private const string INVALID = '{"newCode":{"floor":120}}';

    private function __construct(private ConfigLoader $loader, private Path $fixtures, private string $extension)
    {
    }

    /**
     * Every way a loader breaks the contract on the fixtures in a directory, each said as a sentence; none where
     * it keeps it.
     *
     * @return Listed<string>
     */
    public static function failures(ConfigLoader $loader, Path $fixtures, string $extension): Listed
    {
        $contract = new self($loader, $fixtures, $extension);

        return Listed::of(...[
            ...$contract->read('valid', self::VALID, $fixtures),
            ...$contract->read('valid', self::VALID, Path::of(dirname($fixtures->value()))),
            ...$contract->read('invalid', self::INVALID, $fixtures),
            ...$contract->missing(),
        ]);
    }

    /** @return list<string> */
    private function read(string $name, string $expected, Path $project): array
    {
        $file = $this->file($name, $project);
        $json = Json::parse($expected);
        $wanted = $json instanceof Json ? $file->read($json) : $json;
        $read = $this->loader->load($file);

        return $this->said($read) === $this->said($wanted) ? [] : [sprintf(
            '%s, in a project at %s: the loader reads %s; the gate reads %s',
            $file->file()->value(),
            $project->value(),
            $this->said($read),
            $this->said($wanted),
        )];
    }

    /** @return list<string> */
    private function missing(): array
    {
        $file = $this->file('missing', $this->fixtures);

        return $this->loader->load($file) instanceof CannotJudge
            ? []
            : [sprintf('%s is not there, and was read anyway.', $file->file()->value())];
    }

    private function file(string $name, Path $project): ConfigFile
    {
        return ConfigFile::at(
            Path::of(sprintf('%s/%s.%s', $this->fixtures->value(), $name, $this->extension)),
            $project,
        );
    }

    /** What a reading holds, said so that two readings alike are said alike. */
    private function said(Layer|Invalid|CannotJudge $read): string
    {
        return match (true) {
            $read instanceof Layer => $read->written(ProjectRoot::origin())->line(),
            $read instanceof Invalid => implode(
                '; ',
                array_map(
                    static fn(Problem $problem): string => sprintf('%s: %s', $problem->path(), $problem->message()),
                    [...$read],
                ),
            ),
            default => sprintf('nothing it can judge (%s)', $read->why()),
        };
    }
}
