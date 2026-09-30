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
 * files in the loader's format, in a directory of their own:
 *
 * - `valid.<extension>` writes `{"runner": "pest", "trees": [{"path": "src",
 *   "floor": 100}], "newCode": {"floor": 100}}`;
 * - `invalid.<extension>` writes `{"newCode": {"floor": 120}}`;
 * - `dated.<extension>` writes `{"runner": "pest", "ignores": {"entries":
 *   [{"path": "src/**", "mutator": "Plus", "reason": "Equivalent",
 *   "expires": "2027-01-31"}]}}`, the date as the format writes a date where
 *   it has its own way to;
 * - `up.<extension>` writes `{"runner": "pest", "trees": [{"path": "../src",
 *   "floor": 100}]}`;
 * - `broken.<extension>` is not in the format, or cannot be read;
 * - `unquoted.<extension>`, only where the format can write a number, and
 *   read only where the loader finds it, writes `{"ignores": {"entries": [{"mutant": 123456789012, "reason":
 *   "Equivalent"}]}}`, the mutant id as a number.
 *
 * A loader keeps the contract where the valid file reads into that layer,
 * with its path named from the file's directory wherever the project is; a
 * date reads back as `YYYY-MM-DD`; a path that goes up from the file's
 * directory is named from the project; the invalid file, and a mutant id
 * read as a number, read into the problems the gate finds in them; and a
 * file that is broken or not there cannot be judged.
 */
final readonly class ConfigLoaderContract
{
    private const string VALID = '{"runner":"pest","trees":[{"path":"src","floor":100}],"newCode":{"floor":100}}';

    private const string INVALID = '{"newCode":{"floor":120}}';

    private const string DATED = <<<'JSON'
        {"runner": "pest", "ignores": {"entries": [
            {"path": "src/**", "mutator": "Plus", "reason": "Equivalent", "expires": "2027-01-31"}
        ]}}
        JSON;

    private const string UP = '{"runner":"pest","trees":[{"path":"../src","floor":100}]}';

    private const string UNQUOTED = '{"ignores":{"entries":[{"mutant":123456789012,"reason":"Equivalent"}]}}';

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

        $above = Path::of(dirname($fixtures->value()));

        return Listed::of(...[
            ...$contract->read('valid', self::VALID, $fixtures),
            ...$contract->read('valid', self::VALID, $above),
            ...$contract->read('invalid', self::INVALID, $fixtures),
            ...$contract->read('dated', self::DATED, $fixtures),
            ...$contract->read('up', self::UP, $above),
            ...$contract->unjudged('broken', 'is not in its format, and was read anyway'),
            ...$contract->unjudged('missing', 'is not there, and was read anyway'),
            ...$contract->whereWritten('unquoted', self::UNQUOTED),
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
    private function unjudged(string $name, string $read): array
    {
        $file = $this->file($name, $this->fixtures);

        return $this->loader->load($file) instanceof CannotJudge
            ? []
            : [sprintf('%s %s.', $file->file()->value(), $read)];
    }

    /**
     * A case the format may have no way to write, read where the loader finds its fixture.
     *
     * @return list<string>
     */
    private function whereWritten(string $name, string $expected): array
    {
        return $this->loader->load($this->file($name, $this->fixtures)) instanceof CannotJudge
            ? []
            : $this->read($name, $expected, $this->fixtures);
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
