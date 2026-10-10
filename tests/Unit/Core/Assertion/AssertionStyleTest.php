<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Assertion\Assertion;
use NightWorksIO\MutationGate\Core\Assertion\AssertionKind;
use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\Assertion\WeaklyAsserted;
use NightWorksIO\MutationGate\Core\Assertion\WeakTest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Tests\Support\Weakly;

it('suggests an assertion of value on the result of the function around the survivors, in each style', function (AssertionStyle $style, string|Nameless $function, string $suggestion): void {
    $weak = WeakTest::of(
        TestId::of('Tests\CartTest::testFits'),
        TestName::in(Path::of('tests/CartTest.php'), 'testFits'),
        Assertion::of('assertIsBool', AssertionKind::Shape, $style),
    );

    expect($style->suggestion(WeaklyAsserted::by($function, $weak)))->toBe($suggestion);
})->with([
    'Pest, in a function' => [AssertionStyle::Pest, 'fits', 'expect(fits(…))->toBe(<expected>)'],
    'Pest, outside any' => [AssertionStyle::Pest, fn(): Nameless => Nameless::code(), 'expect(…)->toBe(<expected>)'],
    'PHPUnit, outside any' => [AssertionStyle::PhpUnit, fn(): Nameless => Nameless::code(), Weakly::PHPUNIT_OUTSIDE],
]);
