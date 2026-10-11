<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Psalm\Psalm;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\Analysis\MutantChecks;
use NightWorksIO\MutationGate\Core\Analysis\OutOfScope;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\PsalmProjects;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = [
    'holds:src/Adapter/Psalm/LanguageServer.php',
    'holds:src/Adapter/Psalm/Psalm.php',
    'holds:src/Adapter/Psalm/Report.php',
    'holds:src/Adapter/Psalm/Sent.php',
];

afterEach(function (): void {
    Scratch::sweep();
    putenv('MUTATION_GATE_CONTRACT_TOKEN');
});

/**
 * What the stand-in kept of a run: its arguments, or what its server was sent, a line each.
 *
 * @return list<string>
 */
function psalmKept(string $project, string $file): array
{
    return explode("\n", trim((string) file_get_contents(sprintf('%s/vendor/bin/%s', $project, $file))));
}

/**
 * The project's language server removed, so a server started after this
 * ends at once, unable to open its script. A test tells a second start by
 * that, not by a file the server writes, which a server stopped at its
 * limit may never have written.
 */
function psalmServerGone(string $project): void
{
    unlink(sprintf('%s/vendor/bin/psalm-language-server', $project));
}

it('names its version and the digest of the config it reads', function (): void {
    $project = PsalmProjects::project();

    expect(PsalmProjects::in($project)->identity(Withheld::standard()))->toEqual(AnalyserIdentity::of(
        'psalm',
        '6.19.1',
        Digest::sha256Of((string) file_get_contents(sprintf('%s/psalm.xml', $project))),
    ));
})->group(...$holds);

it('reads the config the gate hands it before the one it finds, and a psalm.xml.dist where there is no psalm.xml', function (): void {
    $named = PsalmProjects::project();
    Scratch::write($named, 'build/psalm.xml', '<psalm><projectFiles><directory name="../src"/></projectFiles></psalm>');
    $dist = PsalmProjects::project();
    rename(sprintf('%s/psalm.xml', $dist), sprintf('%s/psalm.xml.dist', $dist));

    expect(PsalmProjects::in($named, '{"config": "build/psalm.xml"}')->identity(Withheld::standard()))
        ->toEqual(AnalyserIdentity::of(
            'psalm',
            '6.19.1',
            Digest::sha256Of('<psalm><projectFiles><directory name="../src"/></projectFiles></psalm>'),
        ))
        ->and(PsalmProjects::in($dist)->configuration(Withheld::standard()))->toBeInstanceOf(AnalyserSettings::class);
})->group(...$holds);

it('cannot judge without a config, one it cannot read or that is no XML, or a version it does not say', function (): void {
    $unconfigured = PsalmProjects::project();
    unlink(sprintf('%s/psalm.xml', $unconfigured));
    $unreadable = PsalmProjects::project();
    unlink(sprintf('%s/psalm.xml', $unreadable));
    mkdir(sprintf('%s/psalm.xml', $unreadable));
    $garbled = PsalmProjects::project();
    Scratch::write($garbled, 'psalm.xml', 'not <xml');
    $unversioned = PsalmProjects::project();
    Scratch::write($unversioned, 'vendor/bin/version.txt', 'Psalm, of some version');
    $none = 'Psalm has no config to read: add a psalm.xml, or name one in staticCheck.config.';

    expect(PsalmProjects::in($unconfigured)->identity(Withheld::standard()))->toEqual(CannotJudge::because($none))
        ->and(PsalmProjects::in($unconfigured)->configuration(Withheld::standard()))->toEqual(CannotJudge::because($none))
        ->and(PsalmProjects::in($unconfigured)->findings(Paths::none(), Withheld::standard()))->toEqual(CannotJudge::because($none))
        ->and(PsalmProjects::in($unconfigured)->check(MutantCheck::of(Path::of('src/Money.php'), Path::of('src/Money.php'))))
        ->toEqual(CannotJudge::because($none))
        ->and(PsalmProjects::in($unreadable, '{"config": "psalm.xml"}')->identity(Withheld::standard()))
        ->toEqual(CannotJudge::because('Psalm\'s config psalm.xml cannot be read.'))
        ->and(PsalmProjects::in($garbled)->configuration(Withheld::standard()))
        ->toEqual(CannotJudge::because('Psalm\'s config psalm.xml is not XML.'))
        ->and(PsalmProjects::in($unversioned)->identity(Withheld::standard()))
        ->toEqual(CannotJudge::because('Psalm did not say its version (exit 0: ).'));
})->group(...$holds);

it('analyses every file its config names for the originals, its baseline set aside, each finding in its file', function (): void {
    $project = PsalmProjects::project();
    Scratch::write($project, 'vendor/bin/answer.exit', '2');
    Scratch::write($project, 'vendor/bin/answer.json', sprintf(
        '[{"severity": "error", "type": "InvalidReturnType", "message": "Wrong.", "file_path": "%1$s/src/Money.php"},'
        . ' {"severity": "info", "type": "MissingParamType", "message": "Untyped.", "file_path": "%1$s/src/Wallet.php"}]',
        realpath($project),
    ));

    expect(PsalmProjects::in($project)->findings(Paths::of(Path::of('src/Money.php')), Withheld::standard()))->toEqual(Findings::of(
        Finding::error(Path::of('src/Money.php'), 'InvalidReturnType', 'Wrong.'),
        Finding::lesser(Path::of('src/Wallet.php'), 'MissingParamType', 'Untyped.'),
    ))
        ->and(psalmKept($project, 'argv.txt'))->toBe([
            sprintf('--config=%s/psalm.xml', realpath($project)),
            '--output-format=json',
            '--no-progress',
            '--ignore-baseline',
            '--show-info=true',
            'withheld',
        ]);
})->group(...$holds);

it('cannot run over the originals where one is outside the files it analyses, or where it writes no report', function (): void {
    $project = PsalmProjects::project();
    $failing = PsalmProjects::project('Fatal error', 1);
    Scratch::write($failing, 'vendor/bin/answer.err', 'PHP Fatal error: out of memory');

    expect(PsalmProjects::in($project)->findings(Paths::of(Path::of('src/Excluded.php')), Withheld::standard()))
        ->toEqual(CannotJudge::because('src/Excluded.php is outside the files Psalm analyses, so Psalm leaves it unchecked.'))
        ->and(PsalmProjects::in($failing)->findings(Paths::none(), Withheld::standard()))
        ->toEqual(CannotJudge::because('Psalm wrote no report (exit 1: PHP Fatal error: out of memory).'));
})->group(...$holds);

it('checks a mutant in its original\'s place, and each dependent it analyses, through one warm server, restoring the original after', function (): void {
    $project = PsalmProjects::project();
    Scratch::write($project, 'mutants/Money.php', "<?php\n// error: InvalidReturnStatement in the mutant\n// info: MixedReturn lesser\n");
    Scratch::write($project, 'tests/MoneyTest.php', "<?php\n// error: Never sent\n");
    Scratch::write($project, 'vendor/bin/answer.json', sprintf(
        '[{"severity": "error", "type": "InvalidReturnType", "message": "kept in the original", "file_path": "%1$s/src/Money.php"},'
        . ' {"severity": "error", "type": "UndefinedClass", "message": "Elsewhere.", "file_path": "%1$s/src/Other.php"}]',
        realpath($project),
    ));
    $psalm = PsalmProjects::in($project);
    $elsewhere = Finding::error(Path::of('src/Other.php'), 'UndefinedClass', 'Elsewhere.');
    $psalm->findings(Paths::none(), Withheld::standard());
    $check = MutantCheck::of(Path::of('src/Money.php'), Path::of('mutants/Money.php'));

    $alone = $psalm->check($check);
    $withDependents = $psalm->check($check->withDependents(Paths::of(Path::of('src/Wallet.php'), Path::of('tests/MoneyTest.php'))));
    $real = realpath($project);

    expect($alone)->toEqual(Findings::of(
        $elsewhere,
        Finding::error(Path::of('src/Money.php'), 'InvalidReturnStatement', 'in the mutant'),
        Finding::lesser(Path::of('src/Money.php'), 'MixedReturn', 'lesser'),
    ))
        ->and($withDependents)->toEqual(Findings::of(
            $elsewhere,
            Finding::error(Path::of('src/Money.php'), 'InvalidReturnStatement', 'in the mutant'),
            Finding::lesser(Path::of('src/Money.php'), 'MixedReturn', 'lesser'),
            Finding::error(Path::of('src/Wallet.php'), 'InaccessibleMethod', 'in the wallet'),
        ))
        ->and(psalmKept($project, 'server-argv.txt'))->toBe([
            sprintf('--config=%s/psalm.xml', $real),
            '--in-memory=true',
            '--enable-autocomplete=false',
            '--enable-code-actions=false',
            '--enable-provide-hover=false',
            '--enable-provide-signature-help=false',
            '--enable-provide-definition=false',
            'withheld',
        ])
        ->and(psalmKept($project, 'server-got.txt'))->toBe([
            'initialize 1 - -',
            'answer ask - -',
            'initialized - - -',
            sprintf('textDocument/didOpen - file://%s/src/Money.php 1', $real),
            '$/mutationGate/analysed 2 - -',
            sprintf('textDocument/didChange - file://%s/src/Money.php 2', $real),
            sprintf('textDocument/didChange - file://%s/src/Money.php 3', $real),
            sprintf('textDocument/didOpen - file://%s/src/Wallet.php 1', $real),
            '$/mutationGate/analysed 3 - -',
        ]);
})->group(...$holds);

it('leaves a mutant out of its scope where its config, or the server reading it, does not analyse the original, one check or several', function (): void {
    $project = PsalmProjects::project();
    Scratch::write($project, 'psalm.xml', '<psalm><projectFiles><directory name="src"/><directory name="lib"/></projectFiles></psalm>');
    Scratch::write($project, 'lib/Rate.php', "<?php\n");
    $psalm = PsalmProjects::in($project);
    $psalm->findings(Paths::none(), Withheld::standard());

    expect($psalm->check(MutantCheck::of(Path::of('outside/Other.php'), Path::of('src/Money.php'))))
        ->toEqual(OutOfScope::of(Path::of('outside/Other.php')))
        ->and($psalm->check(MutantCheck::of(Path::of('lib/Rate.php'), Path::of('src/Money.php'))))
        ->toEqual(OutOfScope::of(Path::of('lib/Rate.php')))
        ->and([...$psalm->checks(MutantChecks::of(
            MutantCheck::of(Path::of('outside/Other.php'), Path::of('src/Money.php')),
            MutantCheck::of(Path::of('lib/Rate.php'), Path::of('src/Money.php')),
        ), ProcessCount::of(2))])
        ->toEqual([OutOfScope::of(Path::of('outside/Other.php')), OutOfScope::of(Path::of('lib/Rate.php'))]);
})->group(...$holds);

it('keeps what the run over the originals found of a file it sent that the server published nothing for', function (): void {
    $project = PsalmProjects::project();
    Scratch::write($project, 'psalm.xml', '<psalm><projectFiles><directory name="src"/><directory name="lib"/></projectFiles></psalm>');
    Scratch::write($project, 'lib/Rate.php', "<?php\n");
    Scratch::write($project, 'vendor/bin/answer.json', sprintf(
        '[{"severity": "error", "type": "UndefinedClass", "message": "Kept.", "file_path": "%s/lib/Rate.php"}]',
        realpath($project),
    ));
    $psalm = PsalmProjects::in($project);
    $psalm->findings(Paths::none(), Withheld::standard());
    $check = MutantCheck::of(Path::of('src/Money.php'), Path::of('src/Money.php'))->withDependents(Paths::of(Path::of('lib/Rate.php')));

    expect($psalm->check($check))->toEqual(Findings::of(
        Finding::error(Path::of('lib/Rate.php'), 'UndefinedClass', 'Kept.'),
        Finding::error(Path::of('src/Money.php'), 'InvalidReturnType', 'kept in the original'),
    ));
})->group(...$holds);

it('cannot check before it ran over the originals, or a file it cannot read', function (): void {
    $project = PsalmProjects::project();
    $psalm = PsalmProjects::in($project);
    $check = MutantCheck::of(Path::of('src/Money.php'), Path::of('mutants/Money.php'));
    $cold = $psalm->check($check);
    $psalm->findings(Paths::none(), Withheld::standard());

    expect($cold)->toEqual(CannotJudge::because('Psalm has not run over the originals, so no mutant can be compared with them.'))
        ->and($psalm->check($check))->toEqual(CannotJudge::because('mutants/Money.php cannot be read, so Psalm cannot check it.'))
        ->and($psalm->check(MutantCheck::of(Path::of('src/Gone.php'), Path::of('src/Money.php'))))
        ->toEqual(CannotJudge::because('src/Gone.php cannot be read, so Psalm cannot check it.'));
})->group(...$holds);

it('cannot judge where a dependent it analyses cannot be read', function (): void {
    $project = PsalmProjects::project();
    $psalm = PsalmProjects::in($project);
    $psalm->findings(Paths::none(), Withheld::standard());
    $check = MutantCheck::of(Path::of('src/Money.php'), Path::of('src/Money.php'))->withDependents(Paths::of(Path::of('src/Gone.php')));

    expect($psalm->check($check))->toEqual(CannotJudge::because('src/Gone.php cannot be read, so Psalm cannot check it.'));
})->group(...$holds);

it('cannot judge where its server ends before it answers, and starts it no second time', function (string $mode, string $why): void {
    $project = PsalmProjects::project();
    Scratch::write($project, 'vendor/bin/server.mode', $mode);
    $psalm = PsalmProjects::in($project);
    $psalm->findings(Paths::none(), Withheld::standard());
    $check = MutantCheck::of(Path::of('src/Money.php'), Path::of('src/Money.php'));
    $first = $psalm->check($check);
    psalmServerGone($project);

    expect($first)->toEqual(CannotJudge::because($why))
        ->and($psalm->check($check))->toEqual(CannotJudge::because($why));
})->with([
    'at its start' => ['ends', 'Psalm\'s language server ended before it answered (no server here).'],
    'once a file is sent, saying only what it said since its last answer' => [
        'ends-on-file',
        'Psalm\'s language server ended before it answered (gave up).',
    ],
])->group(...$holds);

it('cannot judge in a root that is not there, where its process never starts', function (): void {
    $gone = Psalm::fromOptions(Configs::options('{}'), sprintf('%s/gone', Scratch::directory()));

    expect($gone instanceof Psalm ? $gone->identity(Withheld::standard()) : $gone)
        ->toEqual(CannotJudge::because('Psalm has no config to read: add a psalm.xml, or name one in staticCheck.config.'));
})->group(...$holds);

it('starts every process without what the runner withholds', function (): void {
    putenv('MUTATION_GATE_CONTRACT_TOKEN=leaked');
    $project = PsalmProjects::project();
    $withheld = Withheld::standard()->and(Withheld::of('MUTATION_GATE_CONTRACT_TOKEN'));
    $psalm = PsalmProjects::in($project);
    $psalm->findings(Paths::none(), $withheld);
    $cli = psalmKept($project, 'argv.txt');
    $psalm->check(MutantCheck::of(Path::of('src/Money.php'), Path::of('src/Money.php'))->withholding($withheld));

    $server = psalmKept($project, 'server-argv.txt');

    expect($cli[count($cli) - 1])->toBe('withheld')
        ->and($server[count($server) - 1])->toBe('withheld');
})->group(...$holds);

it('cannot judge where its server does not answer a check within the limit, and starts another for the next', function (): void {
    $project = PsalmProjects::project();
    Scratch::write($project, 'vendor/bin/server.mode', 'mute');
    $psalm = PsalmProjects::in($project);
    $psalm->findings(Paths::none(), Withheld::standard());
    $check = MutantCheck::of(Path::of('src/Money.php'), Path::of('src/Money.php'))->within(Seconds::of(3.0));
    $first = $psalm->check($check);
    Scratch::write($project, 'vendor/bin/server.mode', '');

    expect($first)->toEqual(CannotJudge::because('Psalm\'s language server did not answer in 3s.'))
        ->and($psalm->check($check))->not->toBeInstanceOf(CannotJudge::class);
})->group(...$holds);

it('cannot judge where its server does not answer its start within the limit, and starts it no second time', function (): void {
    $project = PsalmProjects::project();
    Scratch::write($project, 'vendor/bin/server.mode', 'mute-start');
    $psalm = PsalmProjects::in($project);
    $psalm->findings(Paths::none(), Withheld::standard());
    $check = MutantCheck::of(Path::of('src/Money.php'), Path::of('src/Money.php'))->within(Seconds::of(1.0));
    $first = $psalm->check($check);
    psalmServerGone($project);

    expect($first)->toEqual(CannotJudge::because('Psalm\'s language server did not answer in 1s.'))
        ->and($psalm->check($check))->toEqual(CannotJudge::because('Psalm\'s language server did not answer in 1s.'));
})->group(...$holds);
