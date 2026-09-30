<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Mutator\Testing\Mutates;
use NightWorksIO\MutationGate\Mutator\Testing\NotParsed;
use NightWorksIO\MutationGate\Tests\Support\Mutators\DateTimeToImmutable;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveEcho;

function money(): string
{
    return <<<'CODE'
        <?php

        function total($a, $b)
        {
            return $a + $b;
        }

        final class Money
        {
            public function add($a, $b = 1 + 2)
            {
                echo 'adding';

                return $a + $b;
            }
        }
        CODE;
}

it('makes one change per node under Pest, which offers every node, in the order they stand', function (): void {
    expect(iterator_to_array(Mutates::with(new PlusToMinus(), money())->underPest(), preserve_keys: false))->toBe([
        "-    return \$a + \$b;\n+    return \$a - \$b;",
        "-    public function add(\$a, \$b = 1 + 2)\n+    public function add(\$a, \$b = 1 - 2)",
        "-        return \$a + \$b;\n+        return \$a - \$b;",
    ]);
});

it('makes a change under Infection only in a class method or on its signature', function (): void {
    expect(iterator_to_array(Mutates::with(new PlusToMinus(), money())->underInfection(), preserve_keys: false))->toBe([
        "-    public function add(\$a, \$b = 1 + 2)\n+    public function add(\$a, \$b = 1 - 2)",
        "-        return \$a + \$b;\n+        return \$a - \$b;",
    ]);
});

it('removes a statement under Pest, and empties it under Infection', function (): void {
    $mutates = Mutates::with(new RemoveEcho(), money());

    expect(iterator_to_array($mutates->underPest(), preserve_keys: false))->toBe(["-        echo 'adding';\n-"])
        ->and(iterator_to_array($mutates->underInfection(), preserve_keys: false))->toBe(["-        echo 'adding';\n+        "]);
});

it('offers each node with its names resolved, and counts no change where the mutator leaves one', function (): void {
    $code = <<<'CODE'
        <?php

        namespace App;

        use DateTime as Clock;

        final class Moment
        {
            public function now()
            {
                return [new Clock(), new \DateTimeZone('UTC'), new DateTime()];
            }
        }
        CODE;

    expect(iterator_to_array(Mutates::with(new DateTimeToImmutable(), $code)->underInfection(), preserve_keys: false))->toBe([
        "-        return [new Clock(), new \\DateTimeZone('UTC'), new DateTime()];\n+        return [new \\DateTimeImmutable(), new \\DateTimeZone('UTC'), new DateTime()];",
    ]);
});

it('finds no change in a snippet that holds none of the nodes a mutator looks at', function (): void {
    expect(Mutates::with(new RemoveEcho(), "<?php\n\nreturn 1 + 2;\n")->underPest())->toHaveCount(0);
});

it('refuses a snippet that is not PHP, saying why', function (): void {
    Mutates::with(new PlusToMinus(), '<?php return 1 +;');
})->throws(NotParsed::class, "The code a mutator was tested on is not PHP: Syntax error, unexpected ';' on line 1");
