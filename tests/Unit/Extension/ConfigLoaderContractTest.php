<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\ConfigReads;
use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Extension\ConfigLoaderContract;
use NightWorksIO\MutationGate\Port\ConfigLoader;
use NightWorksIO\MutationGate\Tests\Fakes\ConfigLoaderFake;

it('says each way a loader breaks the contract', function (): void {
    $readsNothing = new ConfigLoaderFake([
        '/fixtures/Config/valid.toml' => '{}',
        '/fixtures/Config/invalid.toml' => '{}',
        '/fixtures/Config/dated.toml' => '{}',
        '/fixtures/Config/up.toml' => '{}',
        '/fixtures/Config/adapter.toml' => '{}',
        '/fixtures/Config/broken.toml' => '{}',
        '/fixtures/Config/missing.toml' => '{}',
        '/fixtures/Config/unquoted.toml' => '{}',
    ]);

    expect([...ConfigLoaderContract::failures($readsNothing, Path::of('/fixtures/Config'), 'toml')])->toBe([
        '/fixtures/Config/valid.toml, in a project at /fixtures/Config: the loader reads {}; the gate reads '
        . '{"runner":"pest","trees":[{"path":"src","floor":100}],"newCode":{"floor":100}}',
        '/fixtures/Config/valid.toml, in a project at /fixtures: the loader reads {}; the gate reads '
        . '{"runner":"pest","trees":[{"path":"Config/src","floor":100}],"newCode":{"floor":100}}',
        '/fixtures/Config/invalid.toml, in a project at /fixtures/Config: the loader reads {}; the gate reads '
        . 'newCode.floor: expected a number from 0 to 100, got 120',
        '/fixtures/Config/dated.toml, in a project at /fixtures/Config: the loader reads {}; the gate reads '
        . '{"runner":"pest","ignores":{"entries":[{"path":"src/**","mutator":"Plus","reason":"Equivalent",'
        . '"expires":"2027-01-31"}]}}',
        '/fixtures/Config/up.toml, in a project at /fixtures: the loader reads {}; the gate reads '
        . '{"runner":"pest","trees":[{"path":"src","floor":100}]}',
        '/fixtures/Config/adapter.toml, in a project at /fixtures: the loader names the store\'s path '
        . '.mutation-gate/ledger; the gate names it cache',
        '/fixtures/Config/broken.toml is not in its format, and was read anyway.',
        '/fixtures/Config/missing.toml is not there, and was read anyway.',
        '/fixtures/Config/unquoted.toml, in a project at /fixtures/Config: the loader reads {}; the gate reads '
        . 'ignores.entries[0].mutant: expected a mutant id, twelve lowercase hex characters in quotes, got 123456789012',
    ]);
});

it('says what a loader could not judge', function (): void {
    $failures = [...ConfigLoaderContract::failures(new ConfigLoaderFake([]), Path::of('/fixtures/Config'), 'toml')];

    expect($failures)->toHaveCount(6)
        ->and($failures[2])->toBe(
            '/fixtures/Config/invalid.toml, in a project at /fixtures/Config: the loader reads nothing it can judge '
            . '(/fixtures/Config/invalid.toml is not there.); the gate reads '
            . 'newCode.floor: expected a number from 0 to 100, got 120',
        );
});

it('says a loader that names an adapter\'s paths from anywhere but the file\'s directory', function (): void {
    $fromTheProject = new class implements ConfigLoader {
        public function load(ConfigFile $file): Layer|Invalid
        {
            $json = '{"proofs": {"store": {"use": "Acme\\\\Store", "with": {"path": "../cache"}}}}';

            return Definition::layer(Node::config($json), ProjectRoot::origin());
        }

        public function reads(ConfigFile $file): ConfigReads
        {
            return ConfigReads::none();
        }
    };

    expect([...ConfigLoaderContract::failures($fromTheProject, Path::of('/fixtures/Config'), 'toml')])->toContain(
        '/fixtures/Config/adapter.toml, in a project at /fixtures: the loader names the store\'s path '
        . 'expected a path inside the project, got "../cache"; the gate names it cache',
    );
});

it('says a loader that reads another file beside one that names none', function (ConfigReads $reads, string $said): void {
    $reading = new readonly class ($reads) implements ConfigLoader {
        public function __construct(private ConfigReads $reads)
        {
        }

        public function load(ConfigFile $file): Layer|Invalid|CannotJudge
        {
            return ConfigLoaderFake::ofTheFixture()->load($file);
        }

        public function reads(ConfigFile $file): ConfigReads
        {
            return $this->reads;
        }
    };

    expect([...ConfigLoaderContract::failures($reading, Path::of('/fixtures/Config'), 'fake')])->toBe([
        sprintf('/fixtures/Config/valid.fake names no other file, and the loader says it reads %s beside itself.', $said),
    ]);
})->with([
    'a file it names' => [ConfigReads::named(Path::of('shared.fake'), Path::of('more.fake')), 'shared.fake, more.fake'],
    'one it cannot name' => [ConfigReads::unnamed('It globs.'), 'what it cannot name (It globs.)'],
]);
