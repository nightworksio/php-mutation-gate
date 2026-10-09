<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Init;

use function array_any;
use function count;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Git\Command as Git;
use NightWorksIO\MutationGate\Adapter\Git\Hooks;
use NightWorksIO\MutationGate\Cli\Command\Additions;
use NightWorksIO\MutationGate\Cli\Command\CiRequest;
use NightWorksIO\MutationGate\Cli\Command\Output;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\InfectionFile;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Config\NativeMarkers;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hook\HookFramework;
use NightWorksIO\MutationGate\Core\Hook\HookSetup;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * What `init` asks, in the order it asks, where detection and the command
 * line leave the answer open (ADR-0017 decision 1, ADR-0024 decisions 11 and
 * 12): the runner, the CI, an Infection config's import, native markers, the
 * hooks and the Composer plugin. Each offers what detection found as its
 * default; nobody asked, each takes the default the decision names.
 */
final readonly class InitQuestions
{
    private const string NATIVE = 'native';

    private const string NONE = 'none';

    private const string RUNNER = 'Both Pest\'s mutation plugin and Infection are installed. Which runner mutates?';

    private const string CI = 'Which CI runs the gate? init writes its definition.';

    private const string IMPORT = 'Import %s into the config?';

    private const string MARKERS = '%d native markers hide mutants with no reason. Allow them for now, or refuse them?';

    private const string NATIVE_UNKNOWN = '--native takes allow or refuse, not "%s".';

    private const string HOOKS = 'Where should the gate\'s pre-push hook be set up?';

    private const string PLUGIN = 'Add composer mutate, from the optional Composer plugin?';

    public function __construct(private Asking $asking, private string $project, private Detected $detected)
    {
    }

    public static function options(Command $command): Command
    {
        return $command->addOption(
            self::NATIVE,
            mode: InputOption::VALUE_REQUIRED,
            description: 'Answer the native-markers question: allow or refuse',
        );
    }

    /**
     * What the first questions settle, in their order: the runner, the CI,
     * where the command line names none, and the Infection config to import.
     */
    public function first(
        CommandLine $given,
        bool $runnerChosen,
        Additions $additions,
        InfectionFile|NotGiven $from,
    ): InitAnswers {
        $runner = $this->runner($runnerChosen);
        $ci = $additions->ci() instanceof CiRequest ? NotGiven::value() : $this->ci($additions->output());

        return InitAnswers::of(
            is_string($runner) ? $given->choosing($runner) : $given,
            $this->import($from),
            $ci,
        );
    }

    /**
     * What `--native` says, else what a person says of the markers found:
     * allowed while the project moves its ignores into the config, or
     * refused, as they are by default; or why `--native` cannot be read.
     */
    public function native(InputInterface $input, int $markers): NativeMarkers|NotGiven|CannotJudge
    {
        $given = $input->getOption(self::NATIVE);

        if (is_string($given)) {
            $answer = NativeMarkers::tryFrom($given);

            return $answer instanceof NativeMarkers
                ? $answer
                : CannotJudge::because(sprintf(self::NATIVE_UNKNOWN, $given));
        }

        $choices = [NativeMarkers::Refuse->value, NativeMarkers::Allow->value];
        $chosen = $markers > 0
            ? $this->asking->choice(sprintf(self::MARKERS, $markers), $choices, NativeMarkers::Refuse->value)
            : NativeMarkers::Refuse->value;

        return $chosen === NativeMarkers::Allow->value ? NativeMarkers::Allow : NotGiven::value();
    }

    /**
     * Where a person sets the hooks up: a framework whose config the project
     * holds is offered first, then git's own; nowhere where nobody is asked.
     */
    public function hook(Additions $additions): HookSetup|NotGiven
    {
        if ($additions->hook() instanceof HookSetup) {
            return NotGiven::value();
        }

        if (! $this->asking->isInteractive()) {
            return HookSetup::None;
        }

        $here = [];
        $rest = [];

        foreach (HookFramework::cases() as $framework) {
            $holds = $this->holds($framework);
            $here = $holds ? [...$here, $framework->value] : $here;
            $rest = $holds ? $rest : [...$rest, $framework->value];
        }

        $git = Hooks::of(Git::in($this->project)) instanceof Hooks ? [HookSetup::Git->value] : [];
        $choices = [...$here, ...$git, ...$rest, HookSetup::None->value];

        return HookSetup::from($this->asking->choice(self::HOOKS, $choices, $choices[0]));
    }

    /** Whether a person asks for `composer mutate`; nobody asked, nobody does. */
    public function plugin(): bool
    {
        return $this->asking->confirms(self::PLUGIN, default: false);
    }

    /** The runner a person chose where both are installed and nothing chooses one; none where nobody is asked. */
    private function runner(bool $chosen): string|NotGiven
    {
        if ($chosen || ! $this->detected->bothRunners() || ! $this->asking->isInteractive()) {
            return NotGiven::value();
        }

        $runners = [BuiltinRunner::Pest->value, BuiltinRunner::Infection->value];

        return $this->asking->choice(self::RUNNER, $runners, BuiltinRunner::Pest->value);
    }

    /**
     * The CI the project's files show where they show one; the one a person
     * chose where they show none or several; none where nobody is asked then,
     * or none is chosen.
     */
    private function ci(Output $output): CiRequest|NotGiven
    {
        $shown = CiRequest::shown($this->project);
        $plans = [];

        foreach (CiRequest::written() as $plan) {
            $plans[] = $plan->value;
        }

        $chosen = count($shown) === 1
            ? $shown[0]
            : BuiltinCiPlan::tryFrom($this->asking->choice(self::CI, [...$plans, self::NONE], self::NONE));

        return $chosen instanceof BuiltinCiPlan
            ? CiRequest::of($chosen, NotGiven::value(), $output)
            : NotGiven::value();
    }

    /** The Infection config to import: the one named, else the one found where a person takes it; none otherwise. */
    private function import(InfectionFile|NotGiven $named): InfectionFile|NotGiven
    {
        $found = InfectionFile::named('');
        $file = $found->in($this->project);

        return match (true) {
            $named instanceof InfectionFile => $named,
            ! $file instanceof Path => NotGiven::value(),
            $this->asking->confirms(sprintf(self::IMPORT, $file->value()), default: true) => $found,
            default => NotGiven::value(),
        };
    }

    /** Whether the project holds a config the framework reads. */
    private function holds(HookFramework $framework): bool
    {
        $project = Directory::at($this->project);
        return array_any(
            $framework->files(),
            static fn(string $file): bool => $project->read(Path::of($file)) instanceof Contents,
        );
    }
}
