<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\Commands;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Console\Tester\CommandTester;

$makeHere = static fn(): string => (string) getcwd();

afterEach(function () use ($makeHere): void {
    $here = $makeHere();

    chdir($here);
    Scratch::sweep();
});

/**
 * A Laravel project, with these files written into it, as the directory init runs in.
 *
 * @param array<string, string> $files
 */
function questionedProject(array $files = []): string
{
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');

    foreach ($files as $path => $text) {
        Scratch::write($project, $path, $text);
    }

    chdir($project);

    return $project;
}

/** A file of a project, or '' where it is not there. */
function questionedFile(string $project, string $path): string
{
    $file = sprintf('%s/%s', $project, $path);

    return is_file($file) ? (string) file_get_contents($file) : '';
}

/** What init prints where it runs in CI, an answer waiting. */
function questionedInCi(string $project): string
{
    $tester = new CommandTester(Commands::console($project, ['CI' => 'true'])->find('init'));
    $tester->setInputs(['gitlab']);
    $tester->execute(['--no-measure' => true], ['interactive' => true]);

    return $tester->getDisplay();
}

/** What Composer lists installed where both runners are. */
const QUESTIONED_BOTH = '{"packages": [{"name": "pestphp/pest"}, {"name": "pestphp/pest-plugin-mutate"}, {"name": "infection/infection"}]}';

/** Pest's own ignore marker in a comment, built so this file holds none. */
function questionedMarker(): string
{
    return sprintf("<?php\n\nfinal class Money\n{\n    public function add(int \$a): int\n    {\n        return \$a + 1; // %s\n    }\n}\n", '@pest-' . 'mutate-ignore');
}

it('asks which runner mutates where both are installed, and writes the one chosen', function (): void {
    $project = questionedProject(['vendor/composer/installed.json' => QUESTIONED_BOTH]);

    $ran = Commands::answering($project, 'init', ['--no-measure' => true, '--format' => 'json'], ['infection', 'none', 'none', 'no']);

    expect($ran->code)->toBe(0)
        ->and($ran->errors)->toContain('Both Pest\'s mutation plugin and Infection are installed. Which runner mutates?')
        ->and(json_decode(questionedFile($project, 'mutation-gate.json'), associative: true))->toMatchArray(['runner' => 'infection']);
});

it('asks nobody which runner where nobody can answer, and refuses as it did', function (): void {
    $project = questionedProject(['vendor/composer/installed.json' => QUESTIONED_BOTH]);

    $ran = Commands::run($project, 'init', ['--no-measure' => true, '--format' => 'json']);

    expect([$ran->code, $ran->output])->toBe([2, ''])
        ->and($ran->errors)->toContain('Choose one: set runner in the config, or pass --runner.');
});

it('asks which CI runs the gate where the files show none, offering only the CIs it writes for', function (): void {
    $project = questionedProject();

    $ran = Commands::answering($project, 'init', ['--no-measure' => true], ['gitlab', 'none', 'no']);

    expect($ran->code)->toBe(0)
        ->and($ran->errors)->toContain("[6] jenkins\n  [7] none\n")
        ->and($ran->errors)->not->toContain('] json')
        ->and(questionedFile($project, '.gitlab/mutation-gate.yml'))->not->toBe('');
});

it('writes for the one CI the files show without asking, and for none where nobody is asked and none is shown', function (): void {
    $shown = questionedProject(['.gitlab-ci.yml' => "stages: [test]\n"]);
    $none = questionedProject();

    $ranShown = Commands::run($shown, 'init', ['--no-measure' => true]);
    $ranNone = Commands::run($none, 'init', ['--no-measure' => true]);

    expect($ranShown->code)->toBe(0)
        ->and($ranShown->errors)->not->toContain('Which CI')
        ->and(questionedFile($shown, '.gitlab/mutation-gate.yml'))->not->toBe('')
        ->and($ranNone->code)->toBe(0)
        ->and(glob(sprintf('%s/.gitlab/*', $none)))->toBe([]);
});

it('asks whether to import the Infection config it finds, which nobody asked imports', function (): void {
    $files = ['infection.json5' => '{minMsi: 80}', '.gitignore' => ".mutation-gate/\n"];
    $declined = questionedProject($files);
    $taken = questionedProject($files);

    $no = Commands::answering($declined, 'init', ['--no-measure' => true, '--format' => 'json'], ['none', 'n', 'none', 'no']);
    $unasked = Commands::run($taken, 'init', ['--no-measure' => true, '--format' => 'json']);

    expect($no->errors)->toContain('Import infection.json5 into the config? [Y/n]')
        ->and($no->output)->not->toContain('What became of each key')
        ->and($unasked->output)->toContain('Wrote mutation-gate.json with infection.json5 and what zero-config found.');
});

it('asks what to do with native markers where it finds them, and writes ignores.native: allow where they are allowed', function (): void {
    $project = questionedProject(['app/Money.php' => questionedMarker()]);
    $refused = questionedProject(['app/Money.php' => questionedMarker()]);

    $allowed = Commands::answering($project, 'init', ['--no-measure' => true, '--format' => 'json'], ['none', 'allow', 'none', 'no']);
    $unasked = Commands::run($refused, 'init', ['--no-measure' => true, '--format' => 'json']);

    expect($allowed->errors)->toContain('Native markers that hide mutants with no reason: 1. Allow them for now, or refuse them?')
        ->and(json_decode(questionedFile($project, 'mutation-gate.json'), associative: true))->toMatchArray(['ignores' => ['native' => 'allow']])
        ->and(questionedFile($refused, 'mutation-gate.json'))->not->toContain('"native"');
});

it('takes --native as the answer, asks nothing of markers where there are none, and refuses a word it does not know', function (): void {
    $project = questionedProject();
    $refused = questionedProject();

    $given = Commands::run($project, 'init', ['--no-measure' => true, '--format' => 'json', '--native' => 'allow']);
    $unknown = Commands::run($refused, 'init', ['--no-measure' => true, '--native' => 'maybe']);

    expect(json_decode(questionedFile($project, 'mutation-gate.json'), associative: true))->toMatchArray(['ignores' => ['native' => 'allow']])
        ->and([$unknown->code, $unknown->errors])->toBe([2, "--native takes allow or refuse, not \"maybe\".\n"])
        ->and(questionedFile($refused, 'mutation-gate.php'))->toBe('');
});

it('offers a framework whose config the project holds first, then git\'s own hooks, and sets up the one chosen', function (): void {
    $project = questionedProject(['grumphp.yml' => "grumphp: {}\n"]);
    exec(sprintf('git -C %s init --quiet', escapeshellarg($project)));

    $ran = Commands::answering($project, 'init', ['--no-measure' => true], ['none', '', 'no']);

    expect($ran->errors)->toContain("Where should the gate's pre-push hook be set up? [grumphp]\n  [0] grumphp\n  [1] git\n  [2] captainhook\n  [3] pre-commit\n  [4] none\n")
        ->and($ran->output)->toContain('grumphp.yml is here, so init leaves it as it is.');
});

it('sets git\'s own pre-push hook up with a bare --hook, nothing with --no-hook, and refuses both at once', function (): void {
    $project = questionedProject();
    exec(sprintf('git -C %s init --quiet', escapeshellarg($project)));
    $declined = questionedProject();

    $git = Commands::run($project, 'init', ['--no-measure' => true, '--hook' => null]);
    $none = Commands::answering($declined, 'init', ['--no-measure' => true, '--no-hook' => true], ['none', 'no']);
    $both = Commands::run(questionedProject(), 'init', ['--no-measure' => true, '--hook' => 'git', '--no-hook' => true]);

    expect($git->code)->toBe(0)
        ->and(questionedFile($project, '.git/hooks/pre-push'))->toContain('pre-push "$@"')
        ->and($none->errors)->not->toContain('pre-push hook be set up')
        ->and([$both->code, $both->errors])->toBe([2, "--hook and --no-hook each answer the hook question; pass one of them.\n"]);
});

it('offers no git hooks outside a repository', function (): void {
    $ran = Commands::answering(questionedProject(), 'init', ['--no-measure' => true], ['none', 'none', 'no']);

    expect($ran->errors)->toContain("[0] captainhook\n  [1] grumphp\n  [2] pre-commit\n  [3] none\n");
});

it('asks last whether to add composer mutate, and says how, changing no file', function (): void {
    $project = questionedProject();
    $manifest = questionedFile($project, 'composer.json');

    $yes = Commands::answering($project, 'init', ['--no-measure' => true], ['none', 'none', 'y']);
    $no = Commands::answering(questionedProject(), 'init', ['--no-measure' => true], ['none', 'none', '']);

    expect($yes->errors)->toContain("Add composer mutate, from the optional Composer plugin? [y/N]")
        ->and($yes->output)->toContain(<<<'SAID'
            To add composer mutate, from the optional Composer plugin:
              composer config allow-plugins.nightworksio/mutation-gate-composer true
              composer require --dev nightworksio/mutation-gate-composer
            Or, with no plugin, add this to the scripts in composer.json:
              "mutate": ["Composer\\Config::disableProcessTimeout", "mutation-gate"]
            SAID)
        ->and(questionedFile($project, 'composer.json'))->toBe($manifest)
        ->and($no->output)->not->toContain('To add composer mutate');
});

it('asks nothing in CI, whatever answers wait', function (): void {
    $project = questionedProject();

    $ran = questionedInCi($project);

    expect($ran)->not->toContain('Which CI')
        ->and(glob(sprintf('%s/.gitlab/*', $project)))->toBe([]);
});

it('ends with git add of the files it wrote, in a repository', function (): void {
    $project = questionedProject(['.gitignore' => "/vendor/\n"]);
    exec(sprintf('git -C %s init --quiet && git -C %s add --all && git -C %s -c user.name=t -c user.email=t@t commit --quiet -m t', escapeshellarg($project), escapeshellarg($project), escapeshellarg($project)));

    $ran = Commands::run($project, 'init', ['--no-measure' => true, '--editor' => 'vscode']);

    expect($ran->output)->toEndWith("Next:\n  vendor/bin/mutation-gate doctor\n  vendor/bin/mutation-gate\n  git add .gitignore .vscode/extensions.json .vscode/tasks.json mutation-gate.php\n");
});
