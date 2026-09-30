<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Port\ConfigLoader;

use function sprintf;

/** A config format whose files are held as the JSON they read into. */
final readonly class ConfigLoaderFake implements ConfigLoader
{
    /** @param array<string, string> $files the JSON each file reads into, by path */
    public function __construct(private array $files)
    {
    }

    /** The contract suite's fixture: one config, as every format would write it. */
    public static function ofTheFixture(): self
    {
        return new self([
            '/fixtures/Config/mutation-gate.fake'
                => '{"runner": "pest", "trees": [{"path": "src", "floor": 100}], "newCode": {"floor": 100}}',
        ]);
    }

    public function load(ConfigFile $file): Layer|Invalid|CannotJudge
    {
        $path = $file->file()->value();

        $json = array_key_exists($path, $this->files)
            ? Json::parse($this->files[$path])
            : CannotJudge::because(sprintf('%s is not there.', $path));

        return $json instanceof Json ? $file->read($json) : $json;
    }
}
