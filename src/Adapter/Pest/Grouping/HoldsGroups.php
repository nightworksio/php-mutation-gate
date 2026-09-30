<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Grouping;

use function array_key_exists;
use function array_map;
use function array_unique;
use function array_values;

use Closure;

use function debug_backtrace;

use NightWorksIO\MutationGate\Attribute\Holds;
use Pest\Contracts\TestCaseMethodFilter;
use Pest\Factories\Attribute;
use Pest\Factories\TestCaseMethodFactory;
use Pest\PendingCalls\DescribeCall;
use Pest\Repositories\TestRepository;
use PHPUnit\Framework\Attributes\Group;
use ReflectionAttribute;
use ReflectionFunction;

use function sprintf;

/**
 * Pest's filter over the tests it registers, which adds `holds:<path>` as a
 * group to a test for each `#[Holds]` on its closure or on the closure of any
 * `describe` it is registered inside. Pest passes a test to it before it
 * builds the test's class, so the group reaches PHPUnit the way Pest's own
 * `->group()` does. It never drops a test.
 */
final readonly class HoldsGroups implements TestCaseMethodFilter
{
    /** How a group that holds a path is named. */
    private const string GROUP = 'holds:%s';

    /** Adds the filter to the tests Pest registers from here on. */
    public static function register(TestRepository $tests): void
    {
        $tests->addTestCaseMethodFilter(new self());
    }

    public function accept(TestCaseMethodFactory $factory): bool
    {
        $named = $this->groupsOf($factory);

        foreach ($this->heldBy([$factory->closure, ...$this->describing()]) as $path) {
            $group = sprintf(self::GROUP, $path);
            $factory->attributes = array_key_exists($group, $named)
                ? $factory->attributes
                : [...$factory->attributes, new Attribute(Group::class, [$group])];
            $named[$group] = true;
        }

        return true;
    }

    /** @return array<string, true> every group the test is already in, by name */
    private function groupsOf(TestCaseMethodFactory $factory): array
    {
        $named = [];

        foreach ($factory->attributes as $attribute) {
            foreach ($attribute->name === Group::class ? $attribute->arguments : [] as $group) {
                $named[$group] = true;
            }
        }

        return $named;
    }

    /**
     * The closure of every `describe` the test is registered inside, which
     * Pest is running further up the stack.
     *
     * @return list<Closure>
     */
    private function describing(): array
    {
        $closures = [];

        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) as $frame) {
            $object = array_key_exists('object', $frame) ? $frame['object'] : $frame;
            $closures = $object instanceof DescribeCall ? [...$closures, $object->tests] : $closures;
        }

        return $closures;
    }

    /**
     * Every path a `#[Holds]` on these closures names, each once.
     *
     * @param list<Closure|null> $closures
     * @return list<string>
     */
    private function heldBy(array $closures): array
    {
        $paths = [];

        foreach ($closures as $closure) {
            $attributes = $closure instanceof Closure
                ? new ReflectionFunction($closure)->getAttributes(Holds::class)
                : [];
            $paths = [
                ...$paths,
                ...array_map(
                    static fn(ReflectionAttribute $holds): string => $holds->newInstance()->path(),
                    $attributes,
                ),
            ];
        }

        return array_values(array_unique($paths));
    }
}
