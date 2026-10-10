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
use NightWorksIO\MutationGate\Core\Mutant\SourcePin;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Stub\StubText;
use NightWorksIO\MutationGate\Core\Stub\Subject;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Clustered;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

/** The survivor of `fits()`'s comparison, as a run judged it. */
$survivor = static fn(string $added = 'return $amount <= $limit;'): JudgedMutant => JudgedMutant::of(
    Verdicts::mutant('src/Cart.php:7', 'LessThan', MutatorFamily::Boundary, Verdicts::diff('return $amount < $limit;', $added)),
    MutantJudgement::Survived,
);

/** `Cart.php` as the whole suite judges it, and as a group of tests holds it. */
$makeWhole = static fn(): Unit => Unit::file(Path::of('src/Cart.php'));
$makeHeld = static fn(): Unit => Unit::held(Path::of('src/Cart.php'), Group::holding('src/Cart.php'));

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

it('writes a Pest test that holds the mutant, its hint, the call and the scaffold, fails, and offers the ignore', function () use ($survivor, $fits, $makeWhole): void {
    $whole = $makeWhole();

    $mutant = $survivor();
    $id = $mutant->mutant()->id()->value();

    expect(StubText::of(Subject::of($mutant, $fits(), $whole), AssertionStyle::Pest, Format::Json, RunnerBehaviour::standard()))->toBe(sprintf(<<<'PHP'
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

it('writes a Pest test that runs the code and reads its file in place of the scaffold, where the mutator is pinned by source', function () use ($fits, $makeWhole): void {
    $whole = $makeWhole();

    $mutant = JudgedMutant::of(
        Verdicts::mutant('src/Cart.php:7', 'security/HashEqualsToIdentical', MutatorFamily::Condition, Verdicts::diff('return \\hash_equals($a, $b);', 'return $a === $b;')),
        MutantJudgement::Survived,
    );
    $subject = Subject::of($mutant, $fits(), $whole)->pinned(SourcePin::call('hash_equals'));
    $text = StubText::of($subject, AssertionStyle::Pest, Format::Json, RunnerBehaviour::standard());

    expect($text)->toContain(implode("\n", [
        '    // Run it, then read the file it is in, which the mutant changes:',
        '    // expect($cart->fits($amount, $limit))->toBe(/* expected */);',
        "    // expect(file_get_contents((new \\ReflectionMethod(Cart::class, 'fits'))->getFileName()))->toContain('hash_equals(');",
        sprintf("    expect(true)->toBeFalse('Mutant %s", $mutant->mutant()->id()->value()),
    ]))
        ->and($text)->not->toContain('Assert on its result:')
        ->and($subject->pin())->toEqual(SourcePin::call('hash_equals'))
        ->and(Subject::of($mutant, $fits(), $whole)->pin())->toEqual(NotGiven::value());
});

it('writes a PHPUnit method that holds the unit, and offers the ignore as a PHP config writes it', function () use ($survivor, $makeHeld): void {
    $held = $makeHeld();

    $mutant = $survivor();
    $id = $mutant->mutant()->id()->value();
    $text = StubText::of(Subject::of($mutant, Nameless::code(), $held), AssertionStyle::PhpUnit, Format::Php, RunnerBehaviour::standard());

    expect($text)->toStartWith(sprintf("#[\\NightWorksIO\\MutationGate\\Attribute\\Holds('src/Cart.php')]\npublic function testKillsMutant%s(): void\n{\n    // src/Cart.php:7", $id))
        ->and($text)->toContain(sprintf("\n    \$this->fail('Mutant %s, survived: ", $id))
        ->and($text)->toEndWith(sprintf("\n    // Ignore::mutant('%s', because: ''),\n}", $id))
        ->and($text)->not->toContain('$cart->');
});

it('puts a PHPUnit method in the holding group beside its #[Holds] where the runner reads #[Holds] as its files load', function () use ($survivor, $makeHeld): void {
    $held = $makeHeld();

    $text = StubText::of(Subject::of($survivor(), Nameless::code(), $held), AssertionStyle::PhpUnit, Format::Php, RunnerBehaviour::standard()->holdingAsLoaded());

    expect($text)->toStartWith("#[\\NightWorksIO\\MutationGate\\Attribute\\Holds('src/Cart.php')]\n#[\\PHPUnit\\Framework\\Attributes\\Group('holds:src/Cart.php')]\npublic function testKillsMutant");
});

it('joins the holding group in Pest, and no group where the whole suite judges the unit', function () use ($survivor, $makeHeld, $makeWhole): void {
    $held = $makeHeld();
    $whole = $makeWhole();

    expect(StubText::of(Subject::of($survivor(), Nameless::code(), $held), AssertionStyle::Pest, Format::Yaml, RunnerBehaviour::standard()->holdingAsLoaded()))
        ->toEndWith("\n})->group('holds:src/Cart.php');")
        ->and(StubText::of(Subject::of($survivor(), Nameless::code(), $whole), AssertionStyle::Pest, Format::Yaml, RunnerBehaviour::standard()))
        ->toEndWith("\n});")
        ->and(StubText::of(Subject::of($survivor(), Nameless::code(), $whole), AssertionStyle::PhpUnit, Format::Yaml, RunnerBehaviour::standard()->holdingAsLoaded()))
        ->toStartWith('public function testKillsMutant');
});

it('writes one test for a cluster: every member\'s diff, one scaffold for each family, and an ignore for each', function () use ($makeWhole): void {
    $whole = $makeWhole();

    $clusters = iterator_to_array(Clustered::verdict()->trees()->clusters(), preserve_keys: false);
    $text = '';

    foreach ($clusters as $cluster) {
        $subject = Subject::of($cluster->representative(), Nameless::code(), $whole)->forCluster($cluster);
        $text = sprintf('%s%s', $text, StubText::of($subject, AssertionStyle::Pest, Format::Json, RunnerBehaviour::standard()));
    }

    expect($text)->toStartWith(sprintf("it('kills cluster %s', function (): void {\n", $clusters[0]->id()->value()))
        ->and(substr_count($text, 'Call it at the boundary, then one step past it:'))->toBe(1)
        ->and(substr_count($text, '// Assert on its result:'))->toBe(1)
        ->and(substr_count($text, '// Assert on what write() does'))->toBe(1)
        ->and(substr_count($text, '//     +        if ('))->toBe(3)
        ->and(substr_count($text, '// {"mutant":'))->toBe(3 + 3)
        ->and($text)->toContain(sprintf("expect(true)->toBeFalse('Cluster %s, survived: ", $clusters[0]->id()->value()));
});

it('offers the assertion of value a weak test that let the mutant through could make, in that test\'s style', function () use ($survivor, $makeWhole): void {
    $whole = $makeWhole();

    $weak = WeakTest::of(
        TestId::of('CartTest::fits'),
        TestName::in(Path::of('tests/CartTest.php'), 'it fits'),
        Assertion::of('->toBeBool()', AssertionKind::Shape, AssertionStyle::Pest),
    );
    $mutant = $survivor()->found(WeaklyAsserted::by('fits', $weak));

    expect(StubText::of(Subject::of($mutant, Nameless::code(), $whole), AssertionStyle::PhpUnit, Format::Json, RunnerBehaviour::standard()))
        ->toContain("\n    // Or make tests/CartTest.php::it fits assert a value, not only an existence or a shape:\n    // expect(fits(…))->toBe(<expected>);\n");
});

it('writes each comment so no character in the diff ends it early', function () use ($survivor, $makeWhole): void {
    $whole = $makeWhole();

    $text = StubText::of(Subject::of($survivor("return '?>' . \"\e[31m\";"), Nameless::code(), $whole), AssertionStyle::Pest, Format::Json, RunnerBehaviour::standard());

    expect($text)->toContain("//     +        return '? >' . \"[31m\";\n")
        ->and($text)->not->toContain("\e");
});
