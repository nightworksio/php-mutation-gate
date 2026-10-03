<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function explode;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\CiPlan;
use NightWorksIO\MutationGate\Port\CostModel;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Port\Repository;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Port\TreeSource;
use NightWorksIO\MutationGate\Tests\Fakes\CostModelFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The flow commands over a project whose config names the fake runner, with
 * this package's own registry, each port the gate chooses by default replaced:
 * the runner, the ledgers and the CI by the ones a test hands it, and the
 * trees, the cost model and git's answers of a checkout on `main` by fakes.
 */
final readonly class FlowCommands
{
    private function __construct(
        public int $code,
        public string $output,
        public string $errors,
    ) {
    }

    /** A project with the flows' files, and a config naming the fake runner and these settings besides. */
    public static function project(string $config = ''): string
    {
        $project = Flows::project();
        Scratch::write(
            $project,
            'mutation-gate.json',
            sprintf('{"runner": "fake"%s}', $config === '' ? '' : sprintf(', %s', $config)),
        );

        return $project;
    }

    /**
     * How the command line composes a flow in a project, with these ports in
     * place of the ones the gate would choose, over the flows' trees, in no
     * environment variables.
     */
    public static function composition(string $project, Runner $runner, ProofStore $proofs, CiPlan $ci): Composition
    {
        return self::over(Flows::trees(), $project, $runner, $proofs, $ci, Variables::of([]));
    }

    /**
     * How the command line composes a flow in a project over these trees, with
     * these ports in place of the ones the gate would choose, in these
     * environment variables.
     */
    public static function over(
        Trees $trees,
        string $project,
        Runner $runner,
        ProofStore $proofs,
        CiPlan $ci,
        Variables $environment,
    ): Composition {
        return self::checkedOut(
            $trees,
            $project,
            $runner,
            $proofs,
            $ci,
            $environment,
            RepositoryFake::onMain(Revision::ref(Flows::HEAD)),
        );
    }

    /**
     * How the command line composes a flow as {@see over()} does, with git's
     * answers of the checkout from this repository.
     */
    public static function checkedOut(
        Trees $trees,
        string $project,
        Runner $runner,
        ProofStore $proofs,
        CiPlan $ci,
        Variables $environment,
        Repository $repository,
    ): Composition {
        return self::reading($trees, $project, $runner, $proofs, $ci, $environment, $repository, Flows::checkout());
    }

    /**
     * How the command line composes a flow outside CI over the flows' trees,
     * with these ports in place of the ones the gate would choose, reading
     * what the working tree holds from this change source.
     */
    public static function watching(
        string $project,
        Runner $runner,
        ProofStore $proofs,
        ChangeSource $changes,
    ): Composition {
        return self::reading(
            Flows::trees(),
            $project,
            $runner,
            $proofs,
            Flows::ci(),
            Variables::of([]),
            RepositoryFake::onMain(Revision::ref(Flows::HEAD)),
            $changes,
        );
    }

    /**
     * How the command line composes a flow as {@see checkedOut()} does,
     * reading what the working tree holds and what changed from this change
     * source.
     */
    public static function reading(
        Trees $trees,
        string $project,
        Runner $runner,
        ProofStore $proofs,
        CiPlan $ci,
        Variables $environment,
        Repository $repository,
        ChangeSource $changes,
    ): Composition {
        $registry = new FirstParty()->extend(new Extensions(Origin::of(ThisPackage::COMPOSER)))
            ->withRunner(Name::of('fake'), static fn(): Runner => $runner)
            ->withTreeSource(Name::of('phpunit'), static fn(): TreeSource => new TreeSourceFake($trees))
            ->withProofStore(Name::of('directory'), static fn(): ProofStore => $proofs)
            ->withCostModel(Name::of('learned'), static fn(): CostModel => new CostModelFake(Seconds::of(1.0)))
            ->withCiPlan(Name::of('json'), static fn(): CiPlan => $ci, Withheld::nothing(), CiMarker::none())
            ->withChangeSource(Name::of('git'), static fn(): ChangeSource => $changes)
            ->withRepository(Name::of('git'), static fn(): Repository => $repository);
        $vendor = sprintf('%s/vendor', $project);
        $detected = new Detected(Directory::at($project), Directory::at($vendor));
        $effective = new Effective($project, $registry, $detected, new StoppedClock(Configs::NOW)->now());

        return new Composition(
            $effective,
            $registry,
            $project,
            $vendor,
            new StoppedClock(Configs::NOW),
            $environment,
        );
    }

    /**
     * A command run as the command line runs it, handed these options, with
     * its exit code and what it printed on each stream. The options are
     * written as on the command line, `--shards=2 --full`, a value never
     * holding a space.
     */
    public static function run(Command $command, string $options = ''): self
    {
        return self::handed($command, '', $options);
    }

    /** A command run as {@see run()} runs it, handed this text on standard input, as git hands a hook its refs. */
    public static function handed(Command $command, string $input, string $options = ''): self
    {
        $tester = new CommandTester($command);
        $tester->setInputs($input === '' ? [] : [$input]);
        $code = $tester->execute(self::options($options), ['capture_stderr_separately' => true]);
        $output = $tester->getOutput();

        return new self(
            $code,
            Printed::by($output),
            Printed::by($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output),
        );
    }

    /** @return array<string, string|true> each option by its name, with its value, or true where it takes none */
    private static function options(string $line): array
    {
        $options = [];

        foreach ($line === '' ? [] : explode(' ', $line) as $option) {
            $parts = explode('=', $option, 2);
            $options[$parts[0]] = $parts[1] ?? true;
        }

        return $options;
    }
}
