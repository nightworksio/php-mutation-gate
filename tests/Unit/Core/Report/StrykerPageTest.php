<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\StrykerPage;
use NightWorksIO\MutationGate\Tests\Support\Decoded;

$viewer = 'var MutationTestElements = (function () { return /<!--(?:x)-->/; })(); "</script>";';

it('inlines the viewer, its licence and the report, and loads nothing from a network', function () use ($viewer): void {
    $page = StrykerPage::html('{"files": {}}', $viewer, 'Apache License, Version 2.0');

    expect($page)->toStartWith("<!DOCTYPE html>\n")
        ->and($page)->toContain('<mutation-test-report-app title-postfix="mutation-gate"></mutation-test-report-app>')
        ->and($page)->toContain('mutation-testing-elements 3.9.0, by the Stryker Mutator team, under this licence:')
        ->and($page)->toContain('Apache License, Version 2.0')
        ->and($page)->toContain('<script type="application/json" id="report">{"files": {}}</script>')
        ->and($page)->toContain("app.report = JSON.parse(document.getElementById('report').textContent);")
        ->and($page)->not->toMatch('/\b(?:src|href)=/');
});

it('lets nothing in the viewer end its script element or open a comment', function () use ($viewer): void {
    $page = StrykerPage::html('{}', $viewer, 'licence');

    expect($page)->toContain('return /\x3C!--(?:x)-->/;')
        ->and($page)->toContain('"<\/script>"')
        ->and(substr_count($page, '</script'))->toBe(3);
});

it('lets nothing the project wrote end the report\'s element, open a comment or break a line of script', function (): void {
    $report = (string) json_encode(
        ['diff' => "</script><script>alert(1)</script> <!-- ]]> & \u{2028}\u{2029}", 'file' => '</SCRIPT>.php'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS,
    );
    $page = StrykerPage::html($report, 'viewer', 'licence');
    $data = preg_match('~<script type="application/json" id="report">(.*?)</script>~s', $page, $found) === 1 ? $found[1] : '';

    expect($data)->not->toContain('<')
        ->and($data)->not->toContain('>')
        ->and($data)->not->toContain('&')
        ->and($data)->not->toContain("\u{2028}")
        ->and($data)->not->toContain("\u{2029}")
        ->and(Decoded::at($data))->toBe([
            'diff' => "</script><script>alert(1)</script> <!-- ]]> & \u{2028}\u{2029}",
            'file' => '</SCRIPT>.php',
        ])
        ->and(substr_count(mb_strtolower($page), '</script'))->toBe(3);
});

it('keeps the licence from closing its comment', function (): void {
    expect(StrykerPage::html('{}', 'viewer', 'a -- b -->'))->toContain('a - - b - ->');
});

it('shows what each suite alone kills above the viewer, escaped, and nothing where there is none to say', function () use ($viewer): void {
    $page = StrykerPage::html('{}', $viewer, 'licence', ['unit alone kills <b>66.66%</b> & more.', 'Each is a lower bound.']);

    expect($page)->toContain(
        '<section id="suites" style="font-family: sans-serif; padding: 0 1rem"><h2>Suites</h2>'
        . '<p>unit alone kills &lt;b&gt;66.66%&lt;/b&gt; &amp; more.</p><p>Each is a lower bound.</p></section>'
        . "\n<mutation-test-report-app",
    )
        ->and(StrykerPage::html('{}', $viewer, 'licence'))->not->toContain('<section');
});
