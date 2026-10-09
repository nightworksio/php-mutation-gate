<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function implode;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Editor\Editor;
use NightWorksIO\MutationGate\Core\Editor\VsCode;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The files `init --editor=vscode` sets VS Code up with (ADR-0015 decision
 * 7): the watch task and the SARIF Viewer's recommendation, each written
 * only where the file is not there, and otherwise printed as what to add.
 * With `--stdout`, both are printed whole.
 */
final readonly class EditorFiles
{
    private const string EDITOR = 'editor';

    private const string UNKNOWN = '%s is no editor init --editor sets up. Name vscode.';

    private const string ADD_TASK = "Add this task to the tasks in %s:\n\n%s";

    private const string ADD_VIEWER = 'Add %s to the recommendations in %s.';

    public static function option(Command $command): Command
    {
        return $command->addOption(
            self::EDITOR,
            mode: InputOption::VALUE_REQUIRED,
            description: 'Set up this editor: vscode',
        );
    }

    /** The editor `--editor` names; none where it names none; why not, where it names one `init` does not know. */
    public static function asked(InputInterface $input): Editor|NotGiven|CannotJudge
    {
        $named = $input->getOption(self::EDITOR);
        $editor = Editor::tryFrom(is_string($named) ? $named : '');

        return match (true) {
            ! is_string($named) => NotGiven::value(),
            $editor instanceof Editor => $editor,
            default => CannotJudge::because(sprintf(self::UNKNOWN, $named)),
        };
    }

    /** VS Code's files in this project, written or printed, said; or why one could not be. */
    public static function made(string $project, Output $output): string|CannotJudge
    {
        $directory = Directory::at($project);
        $tasks = self::one(
            $directory,
            $output,
            Path::of(VsCode::TASKS),
            VsCode::tasks(),
            sprintf(
                self::ADD_TASK,
                VsCode::TASKS,
                VsCode::task(),
            ),
        );
        $extensions = self::one(
            $directory,
            $output,
            Path::of(VsCode::EXTENSIONS),
            VsCode::extensions(),
            sprintf(
                self::ADD_VIEWER,
                VsCode::SARIF_VIEWER,
                VsCode::EXTENSIONS,
            ),
        );

        return match (true) {
            $tasks instanceof CannotJudge => $tasks,
            $extensions instanceof CannotJudge => $extensions,
            default => implode("\n", [$tasks, $extensions]),
        };
    }

    /** One file: printed whole, written where it is not there, or else what to add to it; said. */
    private static function one(
        Directory $project,
        Output $output,
        Path $file,
        string $whole,
        string $addition,
    ): string|CannotJudge {
        if ($output === Output::Printed) {
            return sprintf(Output::FILE, $file->value(), $whole);
        }

        $there = $project->read($file);

        return match (true) {
            $there instanceof CannotJudge => $there,
            $there instanceof Contents => $addition,
            default => self::written($project, $file, $whole),
        };
    }

    private static function written(Directory $project, Path $file, string $whole): string|CannotJudge
    {
        $written = $project->write($file, Contents::of(sprintf("%s\n", $whole)));

        return $written instanceof CannotJudge ? $written : sprintf('Wrote %s.', $file->value());
    }
}
