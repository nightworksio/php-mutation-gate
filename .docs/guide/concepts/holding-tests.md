# Holding tests

Some code is run by every test: a composition root, a service provider or a
kernel. Each mutant of it would run the whole suite. Declare instead which tests
hold it. The path is then mutated against those tests alone, once they are shown
to cover every line of it that the whole suite covers.

```php
// A Pest test, or every test in a describe
use NightWorksIO\MutationGate\Attribute\Holds;

it('boots the kernel', #[Holds('src/Kernel.php')] function () {
    // …
});

// or, for every test in the file
describe('Kernel', function () {
    it('boots', function () {
        // …
    });
})->group('holds:src/Kernel.php');
```

`pest()->group('holds:src/Kernel.php')` holds the whole file too, but it changes
Pest's configuration as the file loads, so every mutant's narrowed run has to
load that file
([ADR-0004](../../decisions/0004-pest-and-infection-behind-one-runner-port.md)).

```php
// A PHPUnit test class
use NightWorksIO\MutationGate\Attribute\Holds;

#[Holds('src/Kernel.php')]
final class KernelTest extends TestCase {}
```

A PHPUnit class run by Pest also needs `#[Group('holds:src/Kernel.php')]`
beside its `#[Holds]`, because Pest cannot add a group to a class it did not
build
([ADR-0005](../../decisions/0005-what-a-change-reaches-is-what-is-mutated.md)).
