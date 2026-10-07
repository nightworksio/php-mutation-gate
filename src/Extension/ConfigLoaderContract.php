<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Extension;

use function array_map;
use function dirname;
use function implode;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\NotGiven;
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
 * - `adapter.<extension>` writes `{"proofs": {"store": {"use": "Acme\\Store",
 *   "with": {"path": "../cache"}}}}`, an adapter's own options;
 * - `broken.<extension>` is not in the format, or cannot be read;
 * - `unquoted.<extension>`, only where the format can write a number, and
 *   read only where the loader finds it, writes `{"ignores": {"entries": [{"mutant": 123456789012, "reason":
 *   "Equivalent"}]}}`, the mutant id as a number.
 *
 * A loader keeps the contract where the valid file reads into that layer,
 * with its path named from the file's directory wherever the project is; a
 * date reads back as `YYYY-MM-DD`; a path that goes up from the file's
 * directory is named from the project, and so is a path among an adapter's
 * own options, `cache` in a project above the file's; the invalid file, and a mutant id
 * read as a number, read into the problems the gate finds in them; a
 * file that is broken or not there cannot be judged; and the valid file,
 * which names no other file, reads none beside itself (ADR-0005, decision 4).
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

    /** The path `adapter`'s store names, `../cache`, as the gate names it from a project above the fixtures. */
    private const string ADAPTER_PATH = 'cache';

    private const string UNQUOTED = '{"ignores":{"entries":[{"mutant":123456789012,"reason":"Equivalent"}]}}';

    private const string READS = '%s names no other file, and the loader says it reads %s beside itself.';

    private const string UNNAMED = 'what it cannot name (%s)';

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
            ...$contract->adapterPath('adapter', $above),
            ...$contract->unjudged('broken', 'is not in its format, and was read anyway'),
            ...$contract->unjudged('missing', 'is not there, and was read anyway'),
            ...$contract->whereWritten('unquoted', self::UNQUOTED),
            ...$contract->readsNoOther('valid'),
        ]);
    }

    /**
     * A file that names no other file, which the loader must say it reads none beside.
     *
     * @return list<string>
     */
    private function readsNoOther(string $name): array
    {
        $file = $this->file($name, $this->fixtures);
        $reads = $this->loader->reads($file);
        $unnamed = $reads->unnamedBecause();
        $named = array_map(static fn(Path $path): string => $path->value(), [...$reads->files()]);

        return match (true) {
            is_string($unnamed) => [sprintf(self::READS, $file->file()->value(), sprintf(self::UNNAMED, $unnamed))],
            $named !== [] => [sprintf(self::READS, $file->file()->value(), implode(', ', $named))],
            default => [],
        };
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

    /**
     * The path an adapter's own options name, as its store reads it from the loader's layer.
     *
     * @return list<string>
     */
    private function adapterPath(string $name, Path $project): array
    {
        $file = $this->file($name, $project);
        $read = $this->loader->load($file);
        $path = $read instanceof Layer
            ? $read->proofs()->store()->options()->path(Key::of('path'))
            : $this->said($read);
        $named = match (true) {
            $path instanceof Path => $path->value(),
            $path instanceof Problem => $path->message(),
            $path instanceof NotGiven => 'nothing',
            default => $path,
        };

        return $named === self::ADAPTER_PATH ? [] : [sprintf(
            '%s, in a project at %s: the loader names the store\'s path %s; the gate names it %s',
            $file->file()->value(),
            $project->value(),
            $named,
            self::ADAPTER_PATH,
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
