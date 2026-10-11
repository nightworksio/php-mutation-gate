<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\Xml;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;

use function simplexml_load_string;

use SimpleXMLElement;

use function sprintf;

/**
 * The tests PHPUnit's `--list-tests-xml` writes, which every runner that
 * starts PHPUnit, Pest among them, writes the same: each test of a class by
 * its id, and each group with the ids of its tests.
 */
final readonly class PhpUnitTestList
{
    /** The file, among a runner adapter's own, that a listing is written to. */
    public const string FILE = 'tests.xml';

    private const string UNREAD = <<<'SAID'
        %s did not list the tests of the suite: %s holds no list of tests. It said:
        %s
        SAID;

    /**
     * The listing a run of a program wrote to a file, as the file reads; or
     * why there is none: the run failed, or the file holds no list.
     */
    public static function listedIn(Ran $ran, string $xml, string $file, Program $program): TestListing|CannotJudge
    {
        $list = $ran->succeeded() ? simplexml_load_string($xml, options: Xml::QUIET) : false;

        if (! $list instanceof SimpleXMLElement || $list->getName() !== 'testSuite') {
            return CannotJudge::because(sprintf(self::UNREAD, $program->title(), $file, $ran->output()));
        }

        $tests = TestIds::none();

        foreach (self::named($list, 'tests') as $section) {
            foreach (self::named($section, 'testClass') as $class) {
                $tests = $tests->and(self::idsOf($class, 'testMethod'));
            }
        }

        $listing = TestListing::of($tests);

        foreach (self::named($list, 'groups') as $section) {
            foreach (self::named($section, 'group') as $group) {
                $listing = $listing->grouping(Group::named((string) $group['name']), self::idsOf($group, 'test'));
            }
        }

        return $listing;
    }

    /** The ids each child of an element that bears this name gives. */
    private static function idsOf(SimpleXMLElement $element, string $name): TestIds
    {
        $tests = TestIds::none();

        foreach (self::named($element, $name) as $test) {
            $tests = $tests->with(TestId::of((string) $test['id']));
        }

        return $tests;
    }

    /** @return list<SimpleXMLElement> the children of an element that bear this name, in their order */
    private static function named(SimpleXMLElement $element, string $name): array
    {
        $named = [];

        foreach ($element->children() ?? [] as $child) {
            $named = $child->getName() === $name ? [...$named, $child] : $named;
        }

        return $named;
    }
}
