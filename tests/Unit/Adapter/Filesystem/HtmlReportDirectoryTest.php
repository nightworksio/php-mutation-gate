<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\HtmlReportDirectory;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Report\JsonReport;
use NightWorksIO\MutationGate\Core\Report\Page\ReportPage;
use NightWorksIO\MutationGate\Core\Report\Sources;
use NightWorksIO\MutationGate\Core\Report\Stryker;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Killings;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

afterEach(function (): void {
    Scratch::sweep();
});

it('writes the Stryker report and the gate\'s own page of the verdict, its style and script from the package', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'src/Money.php', Verdicts::MONEY);
    $html = sprintf('%s/build/html', $project);
    $page = Schema::at('resources/report-page');
    $answer = HtmlReportDirectory::at($html, $project, $page)->report(Verdicts::failing());
    $report = Stryker::json(Verdicts::failing(), Sources::none()->with(Path::of('src/Money.php'), Contents::of(Verdicts::MONEY)));

    expect($answer)->toEqual(Written::to(sprintf('%s/index.html', $html)))
        ->and(file_get_contents(sprintf('%s/mutation-report.json', $html)))->toBe($report)
        ->and(file_get_contents(sprintf('%s/index.html', $html)))->toBe(ReportPage::html(
            JsonReport::of(Verdicts::failing()),
            (string) file_get_contents(sprintf('%s/page.css', $page)),
            (string) file_get_contents(sprintf('%s/page.js', $page)),
        ));
});

it('shows what each suite alone kills among the run\'s details, a hostile suite\'s name as escaped text', function (): void {
    $project = Scratch::directory();
    $html = sprintf('%s/build/html', $project);
    HtmlReportDirectory::at($html, $project, Schema::at('resources/report-page'))->report(Killings::hostile());
    $page = (string) file_get_contents(sprintf('%s/index.html', $html));

    expect($page)->toContain('<section><h3>Suites</h3><ul><li>&lt;script&gt;alert(1)&lt;/script&gt;|x [31mred alone kills 66.66% of the 3 mutants its tests cover.</li>')
        ->and($page)->toContain('<li>feature alone kills 50.00% of the 2 mutants its tests cover.</li>')
        ->and($page)->not->toContain('<script>alert(1)</script>')
        ->and($page)->not->toContain("\e");
});

it('finds the page\'s style and script in the package from its options, and needs a directory', function (): void {
    $html = sprintf('%s/html', Scratch::directory());
    $reporter = HtmlReportDirectory::fromOptions(Configs::commandLine((string) json_encode(['path' => $html])));

    expect($reporter)->toEqual(HtmlReportDirectory::at($html, '.', Schema::at('resources/report-page')))
        ->and(HtmlReportDirectory::fromOptions(Options::none()))->toEqual(Invalid::because(Problem::at(
            'path',
            'The HTML report is written to a directory, whose `path` the entry names.',
        )));
});

it('writes no page without its style and script', function (string $kept): void {
    $root = Scratch::directory();
    Scratch::write($root, sprintf('none/%s', $kept), 'kept');

    expect(HtmlReportDirectory::at(sprintf('%s/html', $root), $root, sprintf('%s/none', $root))->report(Verdicts::passing()))
        ->toEqual(NotWritten::because(sprintf('The HTML report was not written: %s/none holds no page.css or page.js, which the page is styled and run by.', $root)))
        ->and(is_dir(sprintf('%s/html', $root)))->toBeFalse();
})->with(['only the style' => ['page.css'], 'only the script' => ['page.js'], 'neither' => ['other.txt']]);

it('says why it could not write the report', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'html/mutation-report.json/in-the-way', '');

    expect(HtmlReportDirectory::at(sprintf('%s/html', $root), $root, Schema::at('resources/report-page'))->report(Verdicts::passing()))
        ->toEqual(NotWritten::because(sprintf('%s/html/mutation-report.json could not be written.', $root)));
});
