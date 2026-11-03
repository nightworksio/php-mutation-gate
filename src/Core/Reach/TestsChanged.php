<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_key_exists;
use function array_keys;
use function count;
use function is_string;

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\ChangeKind;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestDeclarations;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestMethod;

/**
 * The tests a change to a test file touched, among the coverage map's tests
 * the runner places in it (ADR-0065): each whose declaration differs between
 * the file as it was and as it is, or stands in only one of them, found by
 * the method its id names. Where the change also moved what those tests
 * share, or a test may depend on another, it touched them all.
 *
 * @internal
 */
final readonly class TestsChanged
{
    /** The placed tests this change touched, or why it touched every one of them. */
    public static function of(Change $change, Sources $sources, TestIds $placed): TestIds|Widening
    {
        $now = $sources->now($change->path());
        $before = $sources->before($change->previousPath());

        return match (true) {
            $change->kind() === ChangeKind::Added => Widening::New,
            $change->kind() === ChangeKind::Renamed => Widening::Renamed,
            ! $now instanceof Contents || ! $before instanceof Contents => Widening::Unread,
            default => self::between(TestDeclarations::in($before), TestDeclarations::in($now), $placed),
        };
    }

    private static function between(TestDeclarations $before, TestDeclarations $now, TestIds $placed): TestIds|Widening
    {
        $byMethod = self::byMethod($placed);
        $methods = array_keys($byMethod);

        if ($before->shared($methods) !== $now->shared($methods)) {
            return Widening::Shared;
        }

        $changed = TestIds::none();

        foreach ($byMethod as $method => $tests) {
            $changed = self::same($before->of($method), $now->of($method)) ? $changed : $changed->and($tests);
        }

        return count($changed) > 0 && ($before->dependent() || $now->dependent()) ? Widening::Dependent : $changed;
    }

    /** Whether a test's declaration reads the same in both versions, or stands in neither. */
    private static function same(string|NotGiven $before, string|NotGiven $now): bool
    {
        return is_string($before) && is_string($now)
            ? $before === $now
            : $before instanceof NotGiven && $now instanceof NotGiven;
    }

    /**
     * These tests by the method each runs, a data set's rows under their method.
     *
     * @return array<string, TestIds>
     */
    private static function byMethod(TestIds $tests): array
    {
        $byMethod = [];

        foreach ($tests as $test) {
            $method = TestMethod::of($test);
            $name = $method instanceof TestMethod ? $method->method() : $test->value();
            $byMethod[$name] = array_key_exists($name, $byMethod) ? $byMethod[$name]->with($test) : TestIds::of($test);
        }

        return $byMethod;
    }
}
