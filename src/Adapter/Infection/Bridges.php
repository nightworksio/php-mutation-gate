<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_filter;
use function array_key_exists;
use function array_values;
use function implode;
use function mb_strlen;
use function mb_strrpos;
use function mb_substr;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\Hint;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Mutator\PinnedBySource;

use function sprintf;
use function var_export;

/**
 * The bridges through which Infection makes the mutants of the registered
 * mutators a config turns on (ADR-0021): for each, a class of Infection's
 * mutator contract that hands every node to it and is named by its mutator's
 * name, so its mutants carry that name in Infection's logs. The gate writes
 * them into one file, which the config it writes names as Infection's
 * `bootstrap`, and which then loads the project's own bootstrap. A bridged
 * mutant carries its mutator's own family.
 */
final readonly class Bridges
{
    /** The namespace each bridge's class is under, followed by its mutator's own. */
    private const string NAMESPACE = 'NightWorksIO\\MutationGateBridge\\Infection';

    private const string HEADER = <<<'PHP'
        <?php

        declare(strict_types=1);

        // The bridges through which Infection makes the mutants of the registered
        // mutators the config turns on. The gate writes this file for each run.


        PHP;

    /** One bridge: its namespace, its class, its mutator's name and its mutator's class. */
    private const string BRIDGE = <<<'PHP'
        namespace %1$s {
            final class %2$s implements \Infection\Mutator\Mutator
            {
                public static function getDefinition(): \Infection\Mutator\Definition
                {
                    return \NightWorksIO\MutationGate\Adapter\Infection\Bridged::definition(new \%4$s());
                }

                public function getName(): string
                {
                    return %3$s;
                }

                public function canMutate(\PhpParser\Node $node): bool
                {
                    return \NightWorksIO\MutationGate\Adapter\Infection\Bridged::canMutate(new \%4$s(), $node);
                }

                public function mutate(\PhpParser\Node $node): iterable
                {
                    return \NightWorksIO\MutationGate\Adapter\Infection\Bridged::mutate(new \%4$s(), $node);
                }
            }
        }

        PHP;

    /** The project's own bootstrap, loaded once the bridges are declared. */
    private const string BOOTSTRAP = <<<'PHP'
        namespace {
            require_once %s;
        }

        PHP;

    /**
     * @param array<string, Mutator> $mutators by name; none, where no registered mutator is turned on
     * @param CannotJudge|NotGiven   $refusal  why Infection cannot make mutants with what the options name, if so
     */
    public function __construct(private array $mutators = [], private CannotJudge|NotGiven $refusal = new NotGiven())
    {
    }

    /**
     * The bridges to these mutators, less each pinned by source: Infection
     * shows a mutant only to an include, so no test that reads the file sees
     * it (ADR-0021, decision 19).
     */
    public static function to(Enabled $mutators): self
    {
        $named = [];

        foreach ($mutators as $mutator) {
            if (! $mutator instanceof PinnedBySource) {
                $named[$mutator->name()->value()] = $mutator;
            }
        }

        return new self($named);
    }

    /**
     * These bridges, less each to a mutator that stands down beside them and
     * the mutators of Infection's own that run (ADR-0021, decision 18).
     */
    public function besides(NamedMutators $own): self
    {
        $classes = [];

        foreach ($this->mutators as $mutator) {
            $classes[] = $mutator::class;
        }

        return $this->isEmpty() ? $this : self::to(Enabled::of(MutatorSet::of(...$classes))->besides($own));
    }

    /** No bridge, and why Infection cannot make mutants with what the options name, which every mutation run says. */
    public static function refusing(CannotJudge $why): self
    {
        return new self(refusal: $why);
    }

    /** Why Infection cannot make mutants with what the options name, if it cannot. */
    public function refusal(): CannotJudge|NotGiven
    {
        return $this->refusal;
    }

    public function isEmpty(): bool
    {
        return $this->mutators === [];
    }

    /**
     * The named mutators Infection runs: those of its own, and those bridged
     * that do not stand down beside the named ones of its own (ADR-0021,
     * decision 18). A named mutator that stands down, or that only another
     * runner has, is left out.
     *
     * @return list<string>
     */
    public function runnable(Mutators $mutators, NamedMutators $infection): array
    {
        $isOwn = static fn(string $mutator): bool => NamedMutators::of($mutator)->meet($infection);
        $own = array_values(array_filter([...$mutators], $isOwn));
        $bridged = $this->besides(NamedMutators::of(...$own))->mutators;

        return array_values(array_filter(
            [...$mutators],
            static fn(string $mutator): bool => $isOwn($mutator) || array_key_exists($mutator, $bridged),
        ));
    }

    /** @return list<string> each bridge's class, which Infection's `mutators` block turns it on by */
    public function classes(): array
    {
        $classes = [];

        foreach ($this->mutators as $mutator) {
            $classes[] = $this->bridge($mutator);
        }

        return $classes;
    }

    /** The key Infection's `mutators` block names a mutator by: a bridged one's bridge's class, any other's name. */
    public function keyOf(string $mutator): string
    {
        return array_key_exists($mutator, $this->mutators) ? $this->bridge($this->mutators[$mutator]) : $mutator;
    }

    /** A bridged mutator's own sentence for its survivors, by its name; nothing where the family's is used. */
    public function hintOf(string $mutator): string|NotGiven
    {
        $hint = array_key_exists($mutator, $this->mutators) ? $this->mutators[$mutator]->hint() : false;

        return $hint instanceof Hint ? $hint->sentence() : NotGiven::value();
    }

    /** A mutant's family, by the name Infection's logs give its mutator: a bridged mutator's own, or Infection's. */
    public function familyOf(string $mutator): MutatorFamily
    {
        return array_key_exists($mutator, $this->mutators)
            ? $this->mutators[$mutator]->family()
            : Families::of($mutator);
    }

    /**
     * The PHP of the file that declares every bridge, and then loads the
     * project's own bootstrap, by its path on disk, where it has one.
     */
    public function written(string|NotGiven $bootstrap): string
    {
        $bridges = [];

        foreach ($this->mutators as $name => $mutator) {
            $bridge = $this->bridge($mutator);
            $namespace = (int) mb_strrpos($bridge, '\\');
            $bridges[] = sprintf(
                self::BRIDGE,
                mb_substr($bridge, 0, $namespace),
                mb_substr($bridge, $namespace + mb_strlen('\\')),
                var_export($name, return: true),
                $mutator::class,
            );
        }

        return sprintf(
            '%s%s%s',
            self::HEADER,
            implode('', $bridges),
            $bootstrap instanceof NotGiven ? '' : sprintf(self::BOOTSTRAP, var_export($bootstrap, return: true)),
        );
    }

    /** The class of a mutator's bridge: its own class, under the bridges' namespace. */
    private function bridge(Mutator $mutator): string
    {
        return sprintf('%s\\%s', self::NAMESPACE, $mutator::class);
    }
}
