<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\HtmlReportDirectory;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\Stryker;
use NightWorksIO\MutationGate\Core\Report\StrykerPage;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Extension\Options;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes the report and a page that shows it with the viewer the package carries', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'src/Money.php', Verdicts::MONEY);
    $html = sprintf('%s/build/html', $project);
    $viewer = Schema::at('resources/mutation-testing-elements');
    $answer = HtmlReportDirectory::at($html, $project, $viewer)->report(Verdicts::failing());
    $report = Stryker::json(Verdicts::failing(), ['src/Money.php' => Contents::of(Verdicts::MONEY)]);

    expect($answer)->toEqual(Written::to(sprintf('%s/index.html', $html)))
        ->and(file_get_contents(sprintf('%s/mutation-report.json', $html)))->toBe($report)
        ->and(file_get_contents(sprintf('%s/index.html', $html)))->toBe(StrykerPage::html(
            $report,
            (string) file_get_contents(sprintf('%s/mutation-test-elements.js', $viewer)),
            (string) file_get_contents(sprintf('%s/LICENSE', $viewer)),
        ));
});

it('carries the viewer at the version it names, with its licence', function (): void {
    $viewer = Schema::at('resources/mutation-testing-elements');

    expect(hash_file('sha256', sprintf('%s/mutation-test-elements.js', $viewer)))->toBe('751fb010242b0b44e32d84fe7fe0b9ff1da182823b94f59f5c52b001fcfc163b')
        ->and(hash_file('sha256', sprintf('%s/LICENSE', $viewer)))->toBe('c71d239df91726fc519c6eb72d318ec65820627232b2f796219e87dcf35d0ab4')
        ->and((string) file_get_contents(sprintf('%s/LICENSE', $viewer)))->toContain('Apache License');
});

it('finds the viewer in the package from its options, and needs a directory', function (): void {
    $html = sprintf('%s/html', Scratch::directory());
    $reporter = HtmlReportDirectory::fromOptions(Options::ofJson((string) json_encode(['path' => $html])));

    expect($reporter)->toEqual(HtmlReportDirectory::at($html, '.', Schema::at('resources/mutation-testing-elements')))
        ->and(HtmlReportDirectory::fromOptions(Options::none()))->toEqual(Invalid::because(Problem::at(
            'path',
            'The HTML report is written to a directory, whose `path` the entry names.',
        )));
});

it('writes no page without the viewer', function (): void {
    $root = Scratch::directory();

    expect(HtmlReportDirectory::at(sprintf('%s/html', $root), $root, sprintf('%s/none', $root))->report(Verdicts::passing()))
        ->toEqual(NotWritten::because(sprintf('The HTML report was not written: %s/none is missing, so the page would have no viewer.', $root)))
        ->and(is_dir(sprintf('%s/html', $root)))->toBeFalse();
});

it('says why it could not write the report', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'html/mutation-report.json/in-the-way', '');

    expect(HtmlReportDirectory::at(sprintf('%s/html', $root), $root, Schema::at('resources/mutation-testing-elements'))->report(Verdicts::passing()))
        ->toEqual(NotWritten::because(sprintf('%s/html/mutation-report.json could not be written.', $root)));
});
