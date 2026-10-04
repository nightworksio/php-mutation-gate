<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_pop;
use function explode;
use function file_put_contents;
use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use Pest\Mutate\Mutators\Sets\DefaultSet;

use function sprintf;
use function var_export;

/**
 * The bridges through which Pest makes the mutants of the registered
 * mutators a config turns on (ADR-0021): for each, a class of Pest's
 * mutator contract that hands every node to it. The gate writes them into
 * one file, and the package's plugin loads it in Pest's process, which
 * registers each in pest-plugin-mutate's map of mutators. A bridged mutant
 * carries its mutator's own name and family, whatever its bridge's class.
 *
 * `--mutator` replaces Pest's list rather than adding to it, so a run of
 * every mutator names Pest's `DefaultSet` beside the bridges, and a run of
 * some names each bridged one by its bridge's class.
 */
final readonly class Bridges
{
    /** The namespace each bridge's class is under, followed by its mutator's own. */
    private const string NAMESPACE = 'NightWorksIO\\MutationGateBridge\\Pest';

    private const string HEADER = <<<'PHP'
        <?php

        declare(strict_types=1);

        // The bridges through which Pest makes the mutants of the registered
        // mutators the config turns on. The gate writes this file for each run.


        PHP;

    /**
     * One bridge: its namespace, its class, its mutator's name, set and
     * class, and the node classes Pest offers it.
     */
    private const string BRIDGE = <<<'PHP'
        namespace %1$s {
            final class %2$s implements \Pest\Mutate\Contracts\Mutator
            {
                public static function nodesToHandle(): array
                {
                    return [%6$s];
                }

                public static function name(): string
                {
                    return %3$s;
                }

                public static function set(): string
                {
                    return %4$s;
                }

                public static function can(\PhpParser\Node $node): bool
                {
                    return \NightWorksIO\MutationGate\Adapter\Pest\Bridged::can(new \%5$s(), $node);
                }

                public static function mutate(\PhpParser\Node $node): \PhpParser\Node|int
                {
                    return \NightWorksIO\MutationGate\Adapter\Pest\Bridged::mutate(new \%5$s(), $node);
                }
            }
        }

        PHP;

    /** The registration of one bridge, by its class. */
    private const string REGISTERED = <<<'PHP'
            \NightWorksIO\MutationGate\Adapter\Pest\Bridged::register(
                \%1$s::class,
                \%1$s::name(),
                \%1$s::nodesToHandle(),
            );

        PHP;

    private const string GLOBAL = "namespace {\n%s}\n";

    /**
     * @param array<string, Mutator> $mutators by name; none, where no registered mutator is turned on
     * @param CannotJudge|NotGiven   $refusal  why Pest cannot make mutants with what the options name, if it cannot
     */
    public function __construct(private array $mutators = [], private CannotJudge|NotGiven $refusal = new NotGiven())
    {
    }

    /** The bridges to these mutators. */
    public static function to(Enabled $mutators): self
    {
        $named = [];

        foreach ($mutators as $mutator) {
            $named[$mutator->name()->value()] = $mutator;
        }

        return new self($named);
    }

    /** No bridge, and why Pest cannot make mutants with what the options name, which every mutation run says. */
    public static function refusing(CannotJudge $why): self
    {
        return new self(refusal: $why);
    }

    public function isEmpty(): bool
    {
        return $this->mutators === [];
    }

    /**
     * What `--mutator` names for the mutators a run applies: nothing, so Pest
     * applies its own list, for a run of every mutator with no bridge; Pest's
     * `DefaultSet` and every bridge for one with bridges; and each mutator
     * named, a bridged one by its bridge's class, for a run of some.
     *
     * @return list<string>
     */
    public function applying(Mutators $mutators): array
    {
        $named = $mutators->isAll() && ! $this->isEmpty() ? [DefaultSet::class] : [];

        foreach ($mutators->isAll() ? array_keys($this->mutators) : $mutators as $mutator) {
            $bridged = array_key_exists($mutator, $this->mutators);
            $named[] = $bridged ? $this->bridge($this->mutators[$mutator]) : $mutator;
        }

        return $named;
    }

    /** A bridged mutator's own sentence for its survivors, by its name; nothing where the family's is used. */
    public function hintOf(string $mutator): string|NotGiven
    {
        $hint = array_key_exists($mutator, $this->mutators) ? $this->mutators[$mutator]->hint() : false;

        return $hint instanceof Hint ? $hint->sentence() : NotGiven::value();
    }

    /** A mutant's family, by its mutator's name as the gate names it: a bridged mutator's own, or Pest's. */
    public function familyOf(string $mutator): MutatorFamily
    {
        return array_key_exists($mutator, $this->mutators)
            ? $this->mutators[$mutator]->family()
            : Families::of($mutator);
    }

    /**
     * A mutation run whose Pest loads these bridges: started with the variable
     * that names the file they are written to, among the gate's files, with
     * the node classes Pest offers each, as the project's php-parser declares
     * them; as it is, where there are none; or why it cannot be: the refusal,
     * or an earlier run's file that cannot be replaced.
     */
    public function loading(Project $project, Command $command): Command|CannotJudge
    {
        if ($this->refusal instanceof CannotJudge || $this->isEmpty()) {
            return $this->refusal instanceof CannotJudge ? $this->refusal : $command;
        }

        $file = $project->fresh(Workspace::bridges(BuiltinRunner::Pest)->value());

        if ($file instanceof CannotJudge) {
            return $file;
        }

        file_put_contents($file, $this->written(ParserNodes::in($project->absolute($project->vendor()))));

        return $command->with([GateVariable::Mutators->value => $file]);
    }

    /** The PHP of the file that declares every bridge and registers each, with the node classes Pest offers it. */
    public function written(ParserNodes $nodes): string
    {
        $bridges = [];
        $registered = [];

        foreach ($this->mutators as $name => $mutator) {
            $bridge = $this->bridge($mutator);
            $segments = explode('\\', $bridge);
            $class = array_pop($segments);
            $handled = array_map(
                static fn(string $node): string => var_export($node, return: true),
                $nodes->handled($mutator->handles()),
            );
            $bridges[] = sprintf(
                self::BRIDGE,
                implode('\\', $segments),
                $class,
                var_export($name, return: true),
                var_export($mutator->name()->set(), return: true),
                $mutator::class,
                implode(', ', $handled),
            );
            $registered[] = sprintf(self::REGISTERED, $bridge);
        }

        return sprintf('%s%s%s', self::HEADER, implode('', $bridges), sprintf(self::GLOBAL, implode('', $registered)));
    }

    /** The class of a mutator's bridge: its own class, under the bridges' namespace. */
    private function bridge(Mutator $mutator): string
    {
        return sprintf('%s\\%s', self::NAMESPACE, $mutator::class);
    }
}
