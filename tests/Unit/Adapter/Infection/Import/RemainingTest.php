<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Import\Kept;
use NightWorksIO\MutationGate\Adapter\Infection\Import\Remaining;
use NightWorksIO\MutationGate\Adapter\Infection\Import\Unknown;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Tests\Support\Imports;

it('says what became of every key no other part maps: kept where Infection reads it, dropped where the gate does it its own way', function (): void {
    $import = Remaining::of(Node::decode(<<<'JSON'
        {
            "$schema": "schema.json",
            "source": {}, "minMsi": 1, "minCoveredMsi": 1, "timeout": 1, "timeoutsAsEscaped": true, "logs": {},
            "threads": 4, "tmpDir": "/tmp", "mutators": {}, "bootstrap": "b.php", "phpUnit": {}, "testFramework": "phpunit",
            "initialTestsPhpOptions": "", "testFrameworkOptions": "", "testFrameworkExtraArgs": "",
            "staticAnalysisTool": "phpstan", "staticAnalysisToolOptions": "",
            "maxTimeouts": 3, "ignoreMsiWithNoMutations": true
        }
        JSON));
    $reads = 'stays in infection.json5, because the Infection adapter reads it';

    expect(Imports::written($import))->toBe('{}')
        ->and(Imports::keys($import))->toBe([
            '  $schema: stays in infection.json5, because the gate does not read it',
            '  threads: stays in infection.json5, because the gate overrides it for each run',
            '  tmpDir: stays in infection.json5, because the gate overrides it for each run',
            sprintf('  mutators: %s', $reads),
            sprintf('  bootstrap: %s', $reads),
            sprintf('  phpUnit: %s', $reads),
            sprintf('  testFramework: %s', $reads),
            sprintf('  initialTestsPhpOptions: %s', $reads),
            sprintf('  testFrameworkOptions: %s', $reads),
            sprintf('  testFrameworkExtraArgs: %s', $reads),
            sprintf('  staticAnalysisTool: %s', $reads),
            sprintf('  staticAnalysisToolOptions: %s', $reads),
            '  maxTimeouts: dropped, because timeouts are triaged, not capped',
            '  ignoreMsiWithNoMutations: dropped, because nothing to mutate already passes',
        ]);
});

it('knows a key only by its exact spelling', function (): void {
    expect(Kept::named('threads'))->toBe(Kept::Threads)
        ->and(Kept::named('Threads'))->toEqual(Unknown::key());
});
