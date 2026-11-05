<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Doctor\Fixing;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Doctor\DoctorRun;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Fix\Change;
use NightWorksIO\MutationGate\Core\Doctor\Fix\Edit;
use NightWorksIO\MutationGate\Core\Doctor\Fix\Repair;
use NightWorksIO\MutationGate\Core\Doctor\Fix\Repairs;
use NightWorksIO\MutationGate\Core\Doctor\GateDefinitions;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\PhpUnitMemory;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\Doctor\RunnerConfig;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\InfectionSource;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$finding = static fn(Slug $slug): Findings => Findings::of(Finding::of($slug, Severity::Advice, 'found', 'why', 'fix'));

/** What a fixing in a project with these files makes of one finding, edits and refusals both. */
$fixed = static function (string $project, Slug $slug, Observations $observed) use ($finding): array {
    $fixing = new Fixing($project);
    $repairs = $fixing->repairs($finding($slug), $observed);
    $refusals = [];

    foreach ($repairs as $repair) {
        $refusals = $repair->isRefused() ? [...$refusals, $repair->refusal()] : $refusals;
    }

    return [$fixing->edits($repairs), $refusals];
};

it('fixes no finding it has no repair for', function () use ($finding): void {
    expect([...new Fixing(Scratch::directory())->repairs($finding(Slug::TwoRunners), Observations::none())])->toBe([]);
});

it('loads pcov where the runner\'s PHP offers it, and names what installs it where it does not', function () use ($fixed): void {
    $project = Scratch::directory();
    Scratch::write($project, 'php.ini', "memory_limit=1G\n");
    $ini = sprintf('%s/php.ini', $project);
    $offered = RunnerPhp::at('php')->offering('pcov')->loadingIni($ini);

    expect($fixed($project, Slug::NoCoverageDriver, Observations::none()->withPhp($offered)))
        ->toEqual([[Edit::of($ini, "memory_limit=1G\n", "memory_limit=1G\nextension=pcov\n")], []])
        ->and($fixed($project, Slug::NoCoverageDriver, Observations::none()->withPhp(RunnerPhp::at('php')))[1])
        ->toBe(['Install pcov with pecl install pcov, then run doctor --fix again.']);
});

it('refuses a php.ini the PHP does not load, or one it cannot write', function () use ($fixed): void {
    $project = Scratch::directory();
    $missing = sprintf('%s/gone/php.ini', $project);

    expect($fixed($project, Slug::OpcacheOnTheCommandLine, Observations::none()->withPhp(RunnerPhp::at('php')->offering('pcov')))[1])
        ->toBe(['The runner\'s PHP loads no php.ini: create one, then run doctor --fix again.'])
        ->and($fixed($project, Slug::OpcacheOnTheCommandLine, Observations::none()->withPhp(RunnerPhp::at('php')->loadingIni($missing)))[1])
        ->toBe([sprintf('doctor --fix cannot write %s: make it writable, or make the change by hand.', $missing)]);
});

it('lowers the memory_limit the PHPUnit config sets to the cap', function () use ($fixed): void {
    $project = Scratch::directory();
    $xml = "<phpunit>\n    <php>\n        <ini name=\"memory_limit\" value=\"-1\"/>\n    </php>\n</phpunit>\n";
    Scratch::write($project, 'phpunit.xml', $xml);
    $observed = Observations::none()
        ->withSettings(Configs::settings(['runner' => ['use' => 'pest', 'memory' => '512M']]))
        ->withFiles(ProjectFiles::none()->withRunnerConfig(RunnerConfig::none()->withPhpUnitMemory(PhpUnitMemory::of(
            Path::of('phpunit.xml'),
            MemoryCap::of(-1, MemoryUnit::Bytes),
        ))));

    expect($fixed($project, Slug::MemoryCapLifted, $observed)[0])->toEqual([Edit::of('phpunit.xml', $xml, str_replace('-1', '512M', $xml))])
        ->and($fixed($project, Slug::MemoryCapLifted, Observations::none()))->toBe([[], []]);
});

it('includes the trees in the PHPUnit config\'s source, and refuses where it has no config or no tree', function () use ($fixed): void {
    $project = Scratch::directory();
    $trees = Observations::none()->withTrees(Trees::of(Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root()))));

    expect($fixed($project, Slug::CoverageEmpty, $trees)[1])->toBe(['The project has no PHPUnit config for doctor --fix to change.']);

    Scratch::write($project, 'phpunit.xml.dist', "<phpunit>\n</phpunit>\n");

    expect($fixed($project, Slug::CoverageEmpty, Observations::none())[1])->toBe(['The config names no tree for the PHPUnit config\'s <source> to include.'])
        ->and($fixed($project, Slug::CoverageEmpty, $trees)[0][0]->after())->toContain('<directory>src</directory>');
});

it('says why a vendor patch cannot be planned, as patching would', function () use ($fixed): void {
    [$edits, $refusals] = $fixed(Scratch::directory(), Slug::PestUnpatched, Observations::none());

    expect($edits)->toBe([])
        ->and($refusals[0])->toContain('cannot read');
});

it('plans Infection\'s patch for a release it supports, and nothing for one it does not patch', function () use ($fixed): void {
    $supported = Scratch::directory();
    $unsupported = Scratch::directory();
    InfectionSource::pristine()->into(sprintf('%s/vendor', $supported));
    InfectionSource::pristine()->into(sprintf('%s/vendor', $unsupported), '0.34.0');
    [$edits, $refusals] = $fixed($supported, Slug::InfectionUnpatched, Observations::none());

    expect($edits)->not->toBe([])
        ->and($refusals)->toBe([])
        ->and($edits[0]->file())->toStartWith(sprintf('%s/vendor/infection/', $supported))
        ->and($fixed($unsupported, Slug::InfectionUnpatched, Observations::none()))->toBe([[], []]);
});

it('repins each definition that pins another release, and none where nothing says which release runs', function () use ($fixed): void {
    $project = Scratch::directory();
    $old = sprintf("      - uses: nightworksio/php-mutation-gate@%s\n", str_repeat('a', 40));
    Scratch::write($project, '.github/workflows/mutation.yml', $old);
    Scratch::write($project, '.github/workflows/nightly.yml', $old);
    $pin = GatePin::of(Version::of('nightworksio/mutation-gate', 'v0.4.0', str_repeat('b', 40)));
    $running = GateDefinitions::none()
        ->with(Path::of('.github/workflows/mutation.yml'), $old)
        ->with(Path::of('.gitlab-ci.yml'), "    image: ghcr.io/nightworksio/mutation-gate:0.4.0\n")
        ->with(Path::of('.github/workflows/nightly.yml'), $old);
    $repinned = sprintf("      - uses: nightworksio/php-mutation-gate@%s # v0.4.0\n", str_repeat('b', 40));
    $observed = Observations::none()
        ->in(DoctorRun::of(new DateTimeImmutable('2026-11-05'), -1)->pinning($pin))
        ->withFiles(ProjectFiles::none()->withRunningTheGate($running));

    expect($fixed($project, Slug::WorkflowPinStale, $observed)[0])->toEqual([
        Edit::of('.github/workflows/mutation.yml', $old, $repinned),
        Edit::of('.github/workflows/nightly.yml', $old, $repinned),
    ])
        ->and($fixed($project, Slug::WorkflowPinStale, Observations::none()))->toBe([[], []]);
});

it('writes each edit, and refuses one whose file changed after it was read', function (): void {
    $project = Scratch::directory();
    $outside = Scratch::directory();
    Scratch::write($project, 'a.txt', "a\n");
    Scratch::write($outside, 'php.ini', "x=1\n");
    $fixing = new Fixing($project);
    $ini = sprintf('%s/php.ini', $outside);

    expect($fixing->write(Edit::of('a.txt', "a\n", "b\n"), Edit::of($ini, "x=1\n", "x=0\n")))->toBe(2)
        ->and((string) file_get_contents(sprintf('%s/a.txt', $project)))->toBe("b\n")
        ->and((string) file_get_contents($ini))->toBe("x=0\n")
        ->and($fixing->write(Edit::of('a.txt', "a\n", "c\n")))
        ->toEqual(CannotJudge::because('a.txt changed after doctor read it, so doctor --fix left it as it is.'))
        ->and($fixing->edits(Repairs::of(Repair::refused(Slug::NoCoverageDriver, 'Install pcov.'))))->toBe([]);
});

it('writes a file that is not there yet, read as empty', function (): void {
    $project = Scratch::directory();
    $fixing = new Fixing($project);

    expect($fixing->edits(Repairs::of(Repair::changing(Slug::WorkspaceNotIgnored, Change::of('.gitignore', static fn(string $text): string => sprintf('%sx', $text))))))
        ->toEqual([Edit::of('.gitignore', '', 'x')])
        ->and($fixing->write(Edit::of('new.txt', '', "x\n")))->toBe(1)
        ->and((string) file_get_contents(sprintf('%s/new.txt', $project)))->toBe("x\n");
});

it('stops at an edit it cannot write, and writes none after it', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'a.txt', "a\n");
    $fixing = new Fixing($project);
    $wrote = $fixing->write(Edit::of('a.txt/b.txt', '', "b\n"), Edit::of('a.txt', "a\n", "c\n"));

    expect($wrote)->toBeInstanceOf(CannotJudge::class)
        ->and((string) file_get_contents(sprintf('%s/a.txt', $project)))->toBe("a\n");
});
