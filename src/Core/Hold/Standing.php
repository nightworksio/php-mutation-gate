<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

/** Where a `#[Holds]` stands in a test file, which says whether a group can follow from it. */
enum Standing: string
{
    /** On the closure passed to `it()`, `test()` or `arch()`: the test it becomes. */
    case TestClosure = 'test closure';

    /** On the closure passed to `describe()`: every test inside it. */
    case DescribeClosure = 'describe closure';

    /** On the closure passed to `beforeEach()`, `afterEach()`, `beforeAll()` or `afterAll()`. */
    case HookClosure = 'hook closure';

    /** On the closure passed to `dataset()` or `->with()`, directly or inside an array. */
    case DatasetClosure = 'dataset closure';

    /** On a closure assigned, such as `$test = #[Holds('src/Kernel.php')] fn () => …`. */
    case KeptClosure = 'kept closure';

    /** On any other closure. */
    case OtherClosure = 'other closure';

    /** On a function declared with a name. */
    case NamedFunction = 'named function';

    /** On a class, which PHPUnit runs as its tests. */
    case TestClass = 'test class';

    /** On a method of a class, which PHPUnit runs as a test. */
    case TestMethod = 'test method';

    /**
     * On anything else: a parameter, a property, a constant, a trait, an
     * interface, an enum, an anonymous class or one of its methods.
     */
    case Elsewhere = 'elsewhere';

    /** Whether Pest passes a `#[Holds]` standing here to its filter, which turns it into a group. */
    public function isFiltered(): bool
    {
        return match ($this) {
            self::HookClosure, self::DatasetClosure, self::NamedFunction => false,
            self::TestClosure, self::DescribeClosure, self::KeptClosure, self::OtherClosure,
            self::TestClass, self::TestMethod, self::Elsewhere => true,
        };
    }

    /** Whether Pest loads what stands here as PHPUnit's own: a test class or one of its methods. */
    public function isPhpUnits(): bool
    {
        return $this === self::TestClass || $this === self::TestMethod;
    }

    /** What a refusal calls the place. */
    public function called(): string
    {
        return match ($this) {
            self::HookClosure => 'a beforeEach, afterEach, beforeAll or afterAll closure',
            self::DatasetClosure => 'a dataset closure',
            self::NamedFunction => 'a named function',
            self::TestClass => 'class',
            self::TestMethod => 'method',
            self::TestClosure, self::DescribeClosure, self::KeptClosure, self::OtherClosure,
            self::Elsewhere => $this->value,
        };
    }
}
