<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\Doctor;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Tests\Support\Described;
use NightWorksIO\MutationGate\Tests\Support\Doctored;
use NightWorksIO\MutationGate\Tests\Support\FakePhp;
use NightWorksIO\MutationGate\Tests\Support\Printed;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

$here = (string) getcwd();

afterEach(function () use ($here): void {
    chdir($here);
    Scratch::sweep();
});

/**
 * `doctor` over a copy of a fixture project, with a PHP that loads pcov.
 *
 * @param  array<string, string>     $input
 * @return array{int, string, string} the exit code, and what it printed on each stream
 */
function doctored(string $fixture, array $input = []): array
{
    $project = Scratch::copy($fixture);
    Scratch::write($project, '.gitignore', ".mutation-gate/\n");
    $php = FakePhp::printing(Described::output(['pcov' => '1.0.12'], ['extension_dir' => '/nowhere']));
    $tester = new CommandTester(Doctor::command(Doctored::observed($project, sprintf('%s/php', $php)), Guide::unreleased()));
    $code = $tester->execute($input, ['capture_stderr_separately' => true]);
    $output = $tester->getOutput();

    return [$code, Printed::by($output), Printed::by($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)];
}

it('exits 1 where a finding would fail a run, and writes each as text', function (): void {
    [$code, $output, $errors] = doctored('tests/Fixtures/Projects/TwoRunners');

    expect([$code, $errors])->toBe([1, ''])
        ->and($output)->toStartWith("will fail  two-runners\n")
        ->and($output)->toEndWith("1 would fail a run, 0 would slow it, and 0 are advice.\n");
});

it('exits 0 where nothing would fail a run, and writes the public JSON when asked', function (): void {
    [$code, $output] = doctored('tests/Fixtures/Projects/Library', ['--format' => 'json']);

    expect($code)->toBe(0)
        ->and(json_decode($output, associative: true))->toBe(['format' => 1, 'failsARun' => false, 'findings' => []]);
});

it('cannot judge a format it does not write', function (): void {
    expect(doctored('tests/Fixtures/Projects/Library', ['--format' => 'xml']))
        ->toBe([2, '', "--format is xml; doctor writes text or json.\n"]);
});
