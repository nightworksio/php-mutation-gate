<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Command\Additions;
use NightWorksIO\MutationGate\Cli\Command\CiRequest;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\InfectionFile;
use NightWorksIO\MutationGate\Cli\Init\Asking;
use NightWorksIO\MutationGate\Cli\Init\InitAnswers;
use NightWorksIO\MutationGate\Cli\Init\InitQuestions;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\NativeMarkers;
use NightWorksIO\MutationGate\Core\Hook\HookSetup;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Repository;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * Init's questions in a project that requires PHPUnit itself, at a console holding these answers, and what they
 * print.
 *
 * @return array{InitQuestions, BufferedOutput}
 */
function initQuestionsIn(string $project, string $answers, bool $interactive = true): array
{
    Scratch::write($project, 'composer.json', '{"require-dev": {"phpunit/phpunit": "^13.2"}}');
    $input = new ArrayInput([]);
    $stream = fopen('php://memory', 'r+');

    if (is_resource($stream)) {
        fwrite($stream, $answers);
        rewind($stream);
        $input->setStream($stream);
    }

    $input->setInteractive($interactive);
    $output = new BufferedOutput();
    $detected = new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project)));

    return [new InitQuestions(Asking::at($input, $output, Variables::of([])), $project, $detected), $output];
}

/** What init's first questions settle in a project, at a console holding these answers. */
function initFirstIn(string $project, string $answers): InitAnswers
{
    [$questions] = initQuestionsIn($project, $answers);

    return $questions->first(CommandLine::nothing(), runnerChosen: false, additions: Additions::none(), from: NotGiven::value());
}

/**
 * init's command line, with these options.
 *
 * @param array<string, string> $options
 */
function initQuestionsInput(array $options): InputInterface
{
    return new ArrayInput($options, InitQuestions::options(new Command('init'))->getDefinition());
}

/**
 * What init's first questions write on its command line, and what they
 * print, in a project that requires PHPUnit itself, of these installed
 * packages, at a console holding these answers, a line each.
 */
$firstAnswered = static function (array $installed, string $answers, bool $interactive = true, bool $runnerChosen = false): array {
    $project = Scratch::directory();
    Scratch::write($project, 'composer.json', '{"require-dev": {"phpunit/phpunit": "^13.2"}}');
    Scratch::write($project, 'vendor/composer/installed.json', (string) json_encode([
        'packages' => array_map(static fn(mixed $name): array => ['name' => $name], $installed),
    ]));
    $input = new ArrayInput([]);
    $stream = fopen('php://memory', 'r+');

    if (is_resource($stream)) {
        fwrite($stream, $answers);
        rewind($stream);
        $input->setStream($stream);
    }

    $input->setInteractive($interactive);
    $output = new BufferedOutput();
    $detected = new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project)));
    $answered = new InitQuestions(Asking::at($input, $output, Variables::of([])), $project, $detected)
        ->first(CommandLine::nothing(), $runnerChosen, Additions::none(), NotGiven::value());

    return [$answered->given->written()->line(), $output->fetch()];
};

it('asks which of the two installed runners mutates, and writes the one chosen', function () use ($firstAnswered): void {
    [$written, $printed] = $firstAnswered(['phpunit/phpunit', 'phpspec/phpspec'], "phpspec\nnone");

    expect($written)->toBe('{"runner":"phpspec"}')
        ->and($printed)->toContain('Both phpunit/phpunit and phpspec/phpspec are installed. Which runner mutates? [phpunit]');
});

it('names each of more than two installed runners it asks between', function () use ($firstAnswered): void {
    [$written, $printed] = $firstAnswered(['phpunit/phpunit', 'phpspec/phpspec', 'codeception/codeception'], "codeception\nnone");

    expect($written)->toBe('{"runner":"codeception"}')
        ->and($printed)->toContain(
            'phpunit/phpunit, phpspec/phpspec and codeception/codeception are installed. Which runner mutates? [phpunit]',
        );
});

it('takes the first runner where a person presses Enter', function () use ($firstAnswered): void {
    [$written] = $firstAnswered(['infection/infection', 'pestphp/pest-plugin-mutate'], "\nnone");

    expect($written)->toBe('{"runner":"pest"}');
});

it('asks nobody which runner where the command line chose one, nobody can answer, or zero-config chooses', function (
    array $installed,
    bool $interactive,
    bool $runnerChosen,
) use ($firstAnswered): void {
    [$written, $printed] = $firstAnswered($installed, 'none', $interactive, $runnerChosen);

    expect($written)->toBe('{}')
        ->and($printed)->not->toContain('Which runner mutates?');
})->with([
    'chosen on the command line' => [['phpunit/phpunit', 'phpspec/phpspec'], true, true],
    'nobody to answer' => [['phpunit/phpunit', 'phpspec/phpspec'], false, false],
    'one runner installed' => [['phpunit/phpunit'], true, false],
]);

it('asks which CI where the project shows none, offering each it writes, and takes the one it shows unasked', function (): void {
    $none = Scratch::directory();
    $gitlab = Scratch::directory();
    Scratch::write($gitlab, '.gitlab-ci.yml', "stages: []\n");
    $plan = static fn(InitAnswers $answers): ?BuiltinCiPlan => $answers->ci instanceof CiRequest ? $answers->ci->plan() : null;

    expect([$plan(initFirstIn($none, "github\n")), $plan(initFirstIn($none, "\n")), $plan(initFirstIn($gitlab, ''))])
        ->toBe([BuiltinCiPlan::GitHub, null, BuiltinCiPlan::GitLab]);
});

it('imports the Infection config it finds where a person presses Enter, and asks of none where there is none', function (): void {
    $found = Scratch::directory();
    Scratch::write($found, 'infection.json5', '{}');

    expect([initFirstIn($found, "none\n\n")->from, initFirstIn(Scratch::directory(), "none\n\n")->from])
        ->toEqual([InfectionFile::named(''), NotGiven::value()]);
});

it('takes --native as given, else asks of the markers found whether to allow them, refusing them by default', function (): void {
    $native = static function (string $given, int $markers, string $answers): NativeMarkers|NotGiven|CannotJudge {
        [$questions] = initQuestionsIn(Scratch::directory(), $answers);

        return $questions->native(initQuestionsInput($given === '' ? [] : ['--native' => $given]), $markers);
    };

    expect([
        $native('allow', 0, ''),
        $native('refuse', 3, ''),
        $native('sometimes', 3, ''),
        $native('', 0, "allow\n"),
        $native('', 1, "allow\n"),
        $native('', 2, "\n"),
    ])->toEqual([
        NativeMarkers::Allow,
        NativeMarkers::Refuse,
        CannotJudge::because('--native takes allow or refuse, not "sometimes".'),
        NotGiven::value(),
        NativeMarkers::Allow,
        NotGiven::value(),
    ]);
});

it('offers the hook frameworks the project holds first, then git\'s own where it is a repository, the rest, and none', function (): void {
    $repository = Repository::empty()->write('captainhook.json', '{}')->write('grumphp.yml', '');
    $plain = Scratch::directory();
    Scratch::write($plain, 'grumphp.yml', '');
    $offered = static function (string $project, string $answers): array {
        [$questions, $output] = initQuestionsIn($project, $answers);
        $chosen = $questions->hook(Additions::none());

        return [$chosen, array_values(array_filter(
            explode("\n", $output->fetch()),
            static fn(string $line): bool => str_starts_with($line, '  ['),
        ))];
    };

    expect([$offered($repository->root, "\n"), $offered($repository->root, "2\n"), $offered($plain, "\n")])->toBe([
        [HookSetup::CaptainHook, ['  [0] captainhook', '  [1] grumphp', '  [2] git', '  [3] pre-commit', '  [4] none']],
        [HookSetup::Git, ['  [0] captainhook', '  [1] grumphp', '  [2] git', '  [3] pre-commit', '  [4] none']],
        [HookSetup::GrumPhp, ['  [0] grumphp', '  [1] captainhook', '  [2] pre-commit', '  [3] none']],
    ]);
});

it('sets no hook up where the command line sets one, and none where nobody is asked', function (): void {
    [$questions] = initQuestionsIn(Scratch::directory(), '');
    [$unasked] = initQuestionsIn(Scratch::directory(), '', interactive: false);

    expect([$questions->hook(Additions::none()->answered(NotGiven::value(), HookSetup::Git)), $unasked->hook(Additions::none())])
        ->toEqual([NotGiven::value(), HookSetup::None]);
});

it('adds no composer mutate where a person presses Enter', function (): void {
    [$questions, $output] = initQuestionsIn(Scratch::directory(), "\n");

    expect([$questions->script(), $output->fetch()])->toBe([false, 'Add composer mutate to the scripts in composer.json? [y/N] ']);
});
