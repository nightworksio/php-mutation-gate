<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Import\Logs;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Tests\Support\Imports;

it('takes the HTML and GitLab logs as reports, and drops every other log the gate writes itself', function (): void {
    $import = Logs::of(Node::decode(<<<'JSON'
        {"logs": {
            "html": "build/infection.html",
            "gitlab": "build/code-quality.json",
            "json": "build/infection.json",
            "github": true,
            "text": "infection.log",
            "stryker": {"report": "main", "badge": "main", "other": 1}
        }}
        JSON));

    expect(Imports::written($import))->toBe('{"reports":[{"use":"html","path":"build/infection"},{"use":"gitlab","path":"build/code-quality.json"}]}')
        ->and(Imports::keys($import))->toBe([
            '  logs.html: imported as a reports entry html at build/infection',
            '  logs.gitlab: imported as a reports entry gitlab at build/code-quality.json',
            '  logs.json: dropped, because the gate\'s JSON is its own format; to write it, add the reports entry {"use": "json", "path": "build/infection.json"}',
            '  logs.github: dropped, because annotations are automatic under GitHub Actions',
            '  logs.text: dropped, because the gate writes Infection\'s logs itself, in the config it generates for each run',
            '  logs.stryker.report: dropped, because logs.html gives the HTML report already',
            '  logs.stryker.badge: dropped, because the gate publishes its own badge',
            '  logs.stryker.other: dropped, because the gate writes Infection\'s logs itself, in the config it generates for each run',
        ]);
});

it('takes the Stryker dashboard\'s report as the HTML report where no HTML log is named', function (): void {
    $import = Logs::of(Node::decode('{"logs": {"stryker": {"report": "main"}, "html": ""}}'));

    expect(Imports::written($import))->toBe('{"reports":[{"use":"html","path":"build/mutation-gate"}]}')
        ->and(Imports::keys($import))->toBe([
            '  logs.stryker.report: imported as a reports entry html at build/mutation-gate',
            '  logs.html: dropped, because the gate writes Infection\'s logs itself, in the config it generates for each run',
        ])
        ->and(Imports::keys(Logs::of(Node::decode('{}'))))->toBe([]);
});
