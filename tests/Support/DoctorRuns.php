<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Cli\Command\Doctor;
use NightWorksIO\MutationGate\Cli\Doctor\Measure;
use NightWorksIO\MutationGate\Cli\Doctor\Online;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;

use function sprintf;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/** `doctor` run over a test's copy of a fixture project. */
final readonly class DoctorRuns
{
    /**
     * `doctor` over a copy of a fixture project, with these files besides, and a PHP that loads pcov.
     *
     * @param  array<string, string>     $input
     * @param  array<string, string>     $files each file's contents, by its path in the project
     * @return array{int, string, string} the exit code, and what it printed on each stream
     */
    public static function over(string $fixture, array $input = [], array $files = []): array
    {
        $project = Scratch::copy($fixture);
        Scratch::write($project, '.gitignore', ".mutation-gate/\n");

        foreach ($files as $path => $contents) {
            Scratch::write($project, $path, $contents);
        }

        $php = FakePhp::printing(Described::output(['pcov' => '1.0.12'], ['extension_dir' => '/nowhere']));
        $measure = new Measure(FlowCommands::composition($project, ScriptedRunner::fixture(), new ProofStoreFake(), Flows::ci()));
        $online = new Online($project, Variables::of([]), GitHubAnswering::client([]));
        $tester = new CommandTester(Doctor::command(Doctored::observed($project, sprintf('%s/php', $php)), $measure, $online, Guide::unreleased()));
        $code = $tester->execute($input, ['capture_stderr_separately' => true]);
        $output = $tester->getOutput();

        return [$code, Printed::by($output), Printed::by($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)];
    }
}
