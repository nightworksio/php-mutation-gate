<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Mago\Mago;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\Analysis\OutOfScope;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\FakeAnalyser;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** Mago in a project, reading the config the gate hands it, if any. */
function magoIn(string $project, string $options = '{}', string $vendor = ''): Mago
{
    $mago = Mago::fromOptions(
        Configs::options($options),
        $project,
        $vendor === '' ? sprintf('%s/vendor', $project) : $vendor,
        new LocalProcesses(new SystemClock()),
    );

    return $mago instanceof Mago ? $mago : throw new LogicException('No Mago.');
}

/** What the stand-in answers to an analysis, and how it exits. */
function magoAnswers(string $project, string $json, int $exit): void
{
    Scratch::write(FakeAnalyser::scripts($project), 'answer.json', $json);
    Scratch::write(FakeAnalyser::scripts($project), 'answer.exit', (string) $exit);
}

it('names its version and the digest of the config it reads, or of none', function (): void {
    $project = FakeAnalyser::mago('1.50.0', "src/Money.php\0");
    $bare = magoIn($project)->identity(Withheld::standard());
    Scratch::write($project, 'mago.toml', "[source]\npaths = [\"src\"]\n");

    expect($bare)->toEqual(AnalyserIdentity::of('mago', '1.50.0', Digest::sha256Of('')))
        ->and(magoIn($project)->identity(Withheld::standard()))
        ->toEqual(AnalyserIdentity::of('mago', '1.50.0', Digest::sha256Of("[source]\npaths = [\"src\"]\n")));
});

it('checks a mutant in its original\'s place over the whole workspace, each error an error and the rest lesser, each in its file', function (): void {
    $project = FakeAnalyser::mago('1.50.0', "src/Caller.php\0src/Money.php\0");
    Scratch::write($project, 'build/mago.toml', '');
    magoAnswers($project, sprintf(
        '{"issues": [%s, %s]}',
        '{"level": "Error", "code": "invalid-method-access", "message": "Cannot access private method.", "annotations": [{"kind": "Primary", "span": {"file_id": {"path": "/tmp/mutant.php"}}}]}',
        sprintf('{"level": "Help", "code": "unused-method", "message": "Method is never used.", "annotations": [{"kind": "Primary", "span": {"file_id": {"path": "%s/src/Caller.php"}}}]}', $project),
    ), 1);

    expect(magoIn($project, '{"config": "build/mago.toml"}')->check(MutantCheck::of(Path::of('src/Money.php'), Path::of('/tmp/mutant.php'))))
        ->toEqual(Findings::of(
            Finding::error(Path::of('src/Money.php'), 'invalid-method-access', 'Cannot access private method.'),
            Finding::lesser(Path::of('src/Caller.php'), 'unused-method', 'Method is never used.'),
        ))
        ->and(explode("\n", (string) file_get_contents(sprintf('%s/argv.txt', FakeAnalyser::scripts($project)))))->toBe([
            sprintf('--workspace=%s', $project),
            sprintf('--config=%s/build/mago.toml', $project),
            '--colors=never',
            '--threads=1',
            'analyze',
            '--reporting-format=json',
            '--substitute',
            sprintf('%s/src/Money.php=/tmp/mutant.php', $project),
            'withheld',
        ]);
});

it('analyses the whole workspace for the originals, where it holds each of them', function (): void {
    $project = FakeAnalyser::mago('1.50.0', "src/Money.php\0");
    magoAnswers($project, '{"issues": []}', 0);

    expect(magoIn($project)->findings(Paths::of(Path::of(sprintf('%s/src/Money.php', $project))), Withheld::standard()))
        ->toEqual(Findings::none());
});

it('leaves a mutant of a file outside the paths it analyses out of its scope, and cannot warm up over one', function (): void {
    $project = FakeAnalyser::mago('1.50.0', "src/Money.php\0");

    expect(magoIn($project)->check(MutantCheck::of(Path::of('lib/Other.php'), Path::of('/tmp/mutant.php'))))
        ->toEqual(OutOfScope::of(Path::of('lib/Other.php')))
        ->and(magoIn($project)->findings(Paths::of(Path::of('lib/Other.php')), Withheld::standard()))
        ->toEqual(CannotJudge::because('lib/Other.php is outside the paths Mago analyses, so Mago leaves it unchecked.'));
});

it('cannot judge where it cannot list its files, read its config, or say its version', function (): void {
    $unlisted = FakeAnalyser::mago('1.50.0', '');
    Scratch::write(FakeAnalyser::scripts($unlisted), 'files.exit', '2');
    $silent = FakeAnalyser::mago('1.50.0', '');
    Scratch::write(FakeAnalyser::scripts($silent), 'version.txt', 'a mago of no version');

    expect(magoIn($unlisted)->findings(Paths::none(), Withheld::standard()))
        ->toEqual(CannotJudge::because('Mago could not list the files it analyses (exit 2: ).'))
        ->and(magoIn($unlisted, '{"config": "missing.toml"}')->identity(Withheld::standard()))
        ->toEqual(CannotJudge::because('Mago\'s config missing.toml cannot be read.'))
        ->and(magoIn($silent)->identity(Withheld::standard()))
        ->toEqual(CannotJudge::because('Mago did not say its version (exit 0: ).'));
});

it('says the configuration it merges, in one thread, with its config file among the files it names', function (): void {
    $project = FakeAnalyser::mago('1.50.0', '');
    Scratch::write($project, 'mago.toml', "[source]\npaths = [\"src\"]\n");
    magoAnswers($project, sprintf('{"threads": 1, "source": {"workspace": "%s"}, "analyzer": {"baseline": null}}', $project), 0);
    $settings = magoIn($project)->configuration(Withheld::standard());

    expect($settings instanceof AnalyserSettings ? [$settings->written(), $settings->references()] : $settings)
        ->toEqual(['{"analyzer":{"baseline":null},"source":{"workspace":"."},"threads":1}', Paths::of(Path::of('mago.toml'))])
        ->and(explode("\n", (string) file_get_contents(sprintf('%s/argv.txt', FakeAnalyser::scripts($project)))))->toBe([
            sprintf('--workspace=%s', $project),
            sprintf('--config=%s/mago.toml', $project),
            '--colors=never',
            '--threads=1',
            'config',
            'withheld',
        ]);
});

it('names no config file among what it reads where it reads none', function (): void {
    $project = FakeAnalyser::mago('1.50.0', '');
    magoAnswers($project, '{"threads": 1}', 0);
    $settings = magoIn($project)->configuration(Withheld::standard());

    expect($settings instanceof AnalyserSettings ? $settings->references() : $settings)->toEqual(Paths::none());
});

it('refuses a config option that is no path', function (): void {
    expect(Mago::fromOptions(Configs::options('{"config": 5}'), Scratch::directory(), 'vendor', new LocalProcesses(new SystemClock())))
        ->toEqual(Invalid::because(Problem::at('config', 'expected a path, got 5')));
});

it('never downloads its binary: it cannot judge until Composer\'s package has', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'vendor/composer/installed.json', '{"packages": [{"name": "carthage-software/mago", "version": "1.50.0"}]}');

    expect(magoIn($project)->identity(Withheld::standard()))
        ->toEqual(CannotJudge::because('Mago 1.50.0 has not downloaded its binary yet: run vendor/bin/mago --version once, which downloads it.'))
        ->and(magoIn($project)->check(MutantCheck::of(Path::of('src/Money.php'), Path::of('/tmp/mutant.php'))))
        ->toBeInstanceOf(CannotJudge::class);
});

it('cannot judge where its process never starts', function (): void {
    $project = FakeAnalyser::mago('1.50.0', '');
    $gone = magoIn(sprintf('%s/gone', $project), '{}', sprintf('%s/vendor', $project));

    expect($gone->identity(Withheld::standard()))->toEqual(CannotJudge::because(sprintf(
        'Mago did not say its version (it did not run: The provided cwd "%s/gone" does not exist.).',
        $project,
    )));
});

it('reads no dependents a check lists, analysing the whole workspace', function (): void {
    expect(magoIn(FakeAnalyser::mago('1.50.0', "src/Money.php\0"))->readsDependents())->toBeFalse();
});

it('cannot judge a check whose listing or analysis takes longer than its limit, stopping it there', function (
    string $listing,
    string $analysis,
): void {
    $project = FakeAnalyser::mago('1.50.0', "src/Money.php\0");
    magoAnswers($project, '{"issues": []}', 0);
    Scratch::write(FakeAnalyser::scripts($project), 'files.sleep', $listing);
    Scratch::write(FakeAnalyser::scripts($project), 'answer.sleep', $analysis);
    $started = microtime(as_float: true);

    $checked = magoIn($project)->check(MutantCheck::of(Path::of('src/Money.php'), Path::of('/tmp/mutant.php'))->within(Seconds::of(2.0)));

    expect($checked)->toEqual(CannotJudge::because('Mago did not finish a check in 2s.'))
        ->and(microtime(as_float: true) - $started)->toBeLessThan(20.0);
})->with([
    'a listing past the limit' => ['30', '0'],
    'an analysis past the limit' => ['0', '30'],
]);
