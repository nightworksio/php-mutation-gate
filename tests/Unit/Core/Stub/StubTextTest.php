<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Assertion\Assertion;
use NightWorksIO\MutationGate\Core\Assertion\AssertionKind;
use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\Assertion\WeaklyAsserted;
use NightWorksIO\MutationGate\Core\Assertion\WeakTest;
use NightWorksIO\MutationGate\Core\Config\Format;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Php\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Stub\StubText;
use NightWorksIO\MutationGate\Core\Stub\Subject;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Clustered;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

/** The survivor of `fits()`'s comparison, as a run judged it. */
$survivor = static fn(string $added = 'return $amount <= $limit;'): JudgedMutant => JudgedMutant::of(
    Verdicts::mutant('src/Cart.php:7', 'LessThan', MutatorFamily::Boundary, Verdicts::diff('return $amount < $limit;', $added)),
    MutantJudgement::Survived,
);

/** The function `fits()` of `Cart`. */
$fits = static fn(): Enclosing|Nameless => Enclosing::in(Contents::of(<<<'PHP'
    <?php

    final class Cart
    {
        public function fits(int $amount, int $limit): bool
        {
            return $amount < $limit;
        }
    }
    PHP), Line::of(7));

it('writes a Pest test that holds the mutant, its hint, the call and the scaffold, fails, and offers the ignore', function () use ($survivor, $fits): void {
    $mutant = $survivor();
    $id = $mutant->mutant()->id()->value();

    expect(StubText::of(Subject::of($mutant, $fits(), WholeSuite::tests()), AssertionStyle::Pest, Format::Json))->toBe(sprintf(<<<'PHP'
        it('kills mutant %1$s', function (): void {
            // src/Cart.php:7  LessThan  survived  %1$s
            //     @@ @@
            //     -        return $amount < $limit;
            //     +        return $amount <= $limit;
            // %2$s
            // $cart->fits($amount, $limit);
            // Call it at the boundary, then one step past it:
            // expect($cart->fits($amount, $limit))->toBe(/* at the boundary */);
            // expect($cart->fits($amount, $limit))->toBe(/* one step past it */);
            expect(true)->toBeFalse('Mutant %1$s, survived: %3$s Fill this test in.');
            // Where no test can kill it, leave it out in ignores.entries instead, with a reason:
            // {"mutant":"%1$s","reason":""}
        });
        PHP, $id, $mutant->hint()->text(), str_replace("'", "\\'", $mutant->hint()->text())));
});

it('writes a PHPUnit method, which joins the group that holds the unit, and offers the ignore as a PHP config writes it', function () use ($survivor): void {
    $mutant = $survivor();
    $id = $mutant->mutant()->id()->value();
    $text = StubText::of(Subject::of($mutant, Nameless::code(), Group::named('holds:src/Cart.php')), AssertionStyle::PhpUnit, Format::Php);

    expect($text)->toStartWith(sprintf("#[\\PHPUnit\\Framework\\Attributes\\Group('holds:src/Cart.php')]\npublic function testKillsMutant%s(): void\n{\n    // src/Cart.php:7", $id))
        ->and($text)->toContain(sprintf("\n    \$this->fail('Mutant %s, survived: ", $id))
        ->and($text)->toEndWith(sprintf("\n    // Ignore::mutant('%s', because: ''),\n}", $id))
        ->and($text)->not->toContain('$cart->');
});

it('joins a group by its name in Pest', function () use ($survivor): void {
    expect(StubText::of(Subject::of($survivor(), Nameless::code(), Group::named('holds:src/Cart.php')), AssertionStyle::Pest, Format::Yaml))
        ->toEndWith("\n})->group('holds:src/Cart.php');");
});

it('writes one test for a cluster: every member\'s diff, one scaffold for each family, and an ignore for each', function (): void {
    $clusters = iterator_to_array(Clustered::verdict()->trees()->clusters(), preserve_keys: false);
    $text = '';

    foreach ($clusters as $cluster) {
        $subject = Subject::of($cluster->representative(), Nameless::code(), WholeSuite::tests())->forCluster($cluster);
        $text = sprintf('%s%s', $text, StubText::of($subject, AssertionStyle::Pest, Format::Json));
    }

    expect($text)->toStartWith(sprintf("it('kills cluster %s', function (): void {\n", $clusters[0]->id()->value()))
        ->and(substr_count($text, 'Call it at the boundary, then one step past it:'))->toBe(1)
        ->and(substr_count($text, '// Assert on its result:'))->toBe(1)
        ->and(substr_count($text, '// Assert on what write() does'))->toBe(1)
        ->and(substr_count($text, '//     +        if ('))->toBe(3)
        ->and(substr_count($text, '// {"mutant":'))->toBe(3 + 3)
        ->and($text)->toContain(sprintf("expect(true)->toBeFalse('Cluster %s, survived: ", $clusters[0]->id()->value()));
});

it('offers the assertion of value a weak test that let the mutant through could make, in that test\'s style', function () use ($survivor): void {
    $weak = WeakTest::of(
        TestId::of('CartTest::fits'),
        TestName::in(Path::of('tests/CartTest.php'), 'it fits'),
        Assertion::of('->toBeBool()', AssertionKind::Shape, AssertionStyle::Pest),
    );
    $mutant = $survivor()->found(WeaklyAsserted::by('fits', $weak));

    expect(StubText::of(Subject::of($mutant, Nameless::code(), WholeSuite::tests()), AssertionStyle::PhpUnit, Format::Json))
        ->toContain("\n    // Or make tests/CartTest.php::it fits assert a value, not only an existence or a shape:\n    // expect(fits(…))->toBe(<expected>);\n");
});

it('writes each comment so no character in the diff ends it early', function () use ($survivor): void {
    $text = StubText::of(Subject::of($survivor("return '?>' . \"\e[31m\";"), Nameless::code(), WholeSuite::tests()), AssertionStyle::Pest, Format::Json);

    expect($text)->toContain("//     +        return '? >' . \"[31m\";\n")
        ->and($text)->not->toContain("\e");
});
