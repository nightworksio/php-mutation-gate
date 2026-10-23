<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Command\Additions;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Init\Asking;
use NightWorksIO\MutationGate\Cli\Init\InitQuestions;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

afterEach(function (): void {
    Scratch::sweep();
});

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
