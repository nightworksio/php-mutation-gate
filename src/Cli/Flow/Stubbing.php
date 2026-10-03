<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Project\PhpUnitSuite;
use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Php\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Proof\Ambiguous;
use NightWorksIO\MutationGate\Core\Proof\NoRecord;
use NightWorksIO\MutationGate\Core\Report\Explanation;
use NightWorksIO\MutationGate\Core\Report\Explanations;
use NightWorksIO\MutationGate\Core\Stub\Nearest;
use NightWorksIO\MutationGate\Core\Stub\Stub;
use NightWorksIO\MutationGate\Core\Stub\StubText;
use NightWorksIO\MutationGate\Core\Stub\Subject;
use NightWorksIO\MutationGate\Core\Stub\TestFile;
use NightWorksIO\MutationGate\Core\Stub\Unstubbable;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;

use function pathinfo;

use const PATHINFO_EXTENSION;

use function sprintf;

/**
 * `stub`: a failing test for a survivor or an uncovered mutant, or for a
 * cluster's first survivor, found as `explain` finds it (ADR-0015, decisions
 * 1 to 5). It follows the nearest test file that covers the mutant, and is
 * added to it; with none, the runner's style, in a new file under the first
 * test directory, at the source's path from its tree.
 */
final readonly class Stubbing
{
    private const string MIXED = <<<'SAID'
        %s holds %s tests, so a %s test cannot go in it. Leave out --style to write one in its style.
        SAID;

    public function __construct(private Composed $composed)
    {
    }

    /** The stub for the mutant or cluster an id names, in the style asked for or followed; or why there is none. */
    public function stub(IdPrefix $sought, AssertionStyle|Absent $asked): Stub|NoRecord|Ambiguous|CannotJudge
    {
        $explained = new Explaining($this->composed)->explain($sought);

        if (! $explained instanceof Explanations) {
            return $explained;
        }

        $explanation = $explained->first();
        $checked = Unstubbable::checked($explanation->mutant());

        return $checked instanceof JudgedMutant
            ? $this->stubbed($this->subjectOf($checked, $explanation, $explained), $explanation, $asked)
            : $checked;
    }

    private function stubbed(Subject $subject, Explanation $explanation, AssertionStyle|Absent $asked): Stub|CannotJudge
    {
        $nearest = $this->nearest($subject, $explanation);
        $target = $nearest instanceof TestFile ? $nearest : $this->placed($subject);

        if ($target instanceof CannotJudge) {
            return $target;
        }

        $followed = $target instanceof TestFile
            ? $target->style()
            : $this->composed->adapters->runner->behaviour()->testStyle();
        $style = $asked instanceof AssertionStyle ? $asked : $followed;

        if ($target instanceof TestFile && $style !== $followed) {
            $file = $target->path()->value();

            return CannotJudge::because(sprintf(self::MIXED, $file, $followed->value, $style->value));
        }

        $test = StubText::of($subject, $style, $this->configFormat());
        $for = StubText::named($subject);

        return $target instanceof TestFile
            ? Stub::into($target, $test, $for)
            : Stub::created($target, $test, $style, $for);
    }

    /** The mutant, the function around it, and the tests that judge its unit; for a cluster, every member. */
    private function subjectOf(JudgedMutant $first, Explanation $explanation, Explanations $explained): Subject
    {
        $location = $first->mutant()->location();
        $source = $this->composed->adapters->project->read($location->file());
        $unit = $explanation->unit();
        $subject = Subject::of(
            $first,
            $source instanceof Contents ? Enclosing::in($source, $location->start()) : Nameless::code(),
            $unit instanceof JudgedUnit ? $unit->unit()->judgedBy() : WholeSuite::tests(),
        );
        $cluster = $explained->cluster();

        return $cluster instanceof Cluster ? $subject->forCluster($cluster) : $subject;
    }

    /** The covering test file the stub follows; none where no test covers the mutant. */
    private function nearest(Subject $subject, Explanation $explanation): TestFile|Nameless
    {
        $file = Nearest::file($explanation->matrix(), $subject->first());

        return $file instanceof Path ? $this->read($file) : $file;
    }

    private function read(Path $file): TestFile|Nameless
    {
        $contents = $this->composed->adapters->project->read($file);

        return $contents instanceof Contents ? TestFile::read($file, $contents) : Nameless::code();
    }

    /**
     * Where a test with no covering file to follow goes: under the first test
     * directory, at the source's path from its tree, named for its class. A
     * file already there is added to, since a stub never overwrites one.
     */
    private function placed(Subject $subject): TestFile|Path|CannotJudge
    {
        $suite = Suite::configured($this->composed->adapters->project);
        $trees = $this->composed->adapters->trees->trees();

        if (! $suite instanceof PhpUnitSuite || $trees instanceof CannotJudge) {
            return $suite instanceof CannotJudge ? $suite : $trees;
        }

        $source = $subject->first()->mutant()->location()->file();
        $tree = $trees->holding($source);
        $file = $suite->directories()[0]->caseFor($tree instanceof Tree ? $source->relativeTo($tree->path()) : $source);
        $there = $this->read($file);

        return $there instanceof TestFile ? $there : $file;
    }

    /** The format of the project's config, which an ignore is written in; PHP, as `init` writes, where it has none. */
    private function configFormat(): Format
    {
        $file = $this->composed->setup->configFile;
        $format = $file instanceof Path
            ? Format::fromExtension(pathinfo($file->value(), PATHINFO_EXTENSION))
            : Format::Php;

        return $format instanceof Format ? $format : Format::Php;
    }
}
