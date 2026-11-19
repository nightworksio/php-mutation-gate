<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\OpArrays;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\FakePhp;
use NightWorksIO\MutationGate\Tests\Support\FreshProcess;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * What these programs compile to, as a fake PHP that prints this answers them, and the arguments each run of it was
 * started with, each run's from its first to the script.
 *
 * @param  array<array-key, string>                                    $programs
 * @return array{array{array<array-key, string>, string}|NotGiven, list<string>}
 */
function opArraysAnswered(string $answer, array $programs): array
{
    $php = FakePhp::printing($answer);
    $compiled = new OpArrays(sprintf('%s/php', $php), '/nowhere/outbreak.so', sprintf('%s/programs', $php), 5.0)
        ->digests(array_map(Contents::of(...), $programs));
    $arguments = is_file(sprintf('%s/arguments.txt', $php)) ? (string) file_get_contents(sprintf('%s/arguments.txt', $php)) : '';
    preg_match_all('/^-d .* -r /m', $arguments, $runs);

    return [$compiled, $runs[0]];
}

/** A digest as the extension writes it, of one character repeated. */
function opArraysDigest(string $character): string
{
    return str_repeat($character, 64);
}

it('keeps what a child answered before it ran out of time, and compiles nothing where none could start', function (): void {
    $directory = Scratch::directory();
    Scratch::write($directory, 'php', "#!/bin/sh\necho 1.2.3\nsleep 5\n");
    chmod(sprintf('%s/php', $directory), 0o755);
    $programs = ['a' => Contents::of("<?php\n")];
    $stalled = new OpArrays(sprintf('%s/php', $directory), '/nowhere/outbreak.so', sprintf('%s/programs', $directory), 0.5);
    $missing = new OpArrays(sprintf('%s/none', $directory), '/nowhere/outbreak.so', sprintf('%s/programs', $directory), 0.5);

    expect($stalled->digests($programs))->toBe([[], '1.2.3'])
        ->and($missing->digests($programs))->toEqual(NotGiven::value());
});

it('starts the PHP the tests run on with the extension and no error among its answers, and leaves none of the programs it wrote', function (): void {
    $php = FakePhp::printing(sprintf("1.2.3\n%s\n%s\n", opArraysDigest('a'), opArraysDigest('b')));
    $programs = sprintf('%s/programs', $php);
    $compiled = new OpArrays(sprintf('%s/php', $php), '/nowhere/outbreak.so', $programs, 5.0)
        ->digests(['first' => Contents::of("<?php\n"), 'second' => Contents::of("<?php\necho 1;\n")]);
    $arguments = (string) file_get_contents(sprintf('%s/arguments.txt', $php));

    expect($compiled)->toBe([['first' => opArraysDigest('a'), 'second' => opArraysDigest('b')], '1.2.3'])
        ->and($arguments)->toStartWith("-d extension=/nowhere/outbreak.so -d display_errors=0 -d log_errors=0 -r if (! function_exists('outbreak_op_arrays')) {")
        ->and($arguments)->toEndWith(sprintf(" %1\$s/%2\$d/0.php %1\$s/%2\$d/1.php\n", $programs, getmypid()))
        ->and(sprintf('%s/%d', $programs, getmypid()))->not->toBeDirectory();
});

it('leaves out each program a child could not compile, and asks a new child for those after it, each by its key', function (): void {
    [$compiled, $runs] = opArraysAnswered(sprintf("1.2.3\n%s\n-\n", opArraysDigest('a')), [
        10 => "<?php\n",
        11 => "<?php\n",
        12 => "<?php\n",
        13 => "<?php\n",
    ]);

    expect($compiled)->toBe([[10 => opArraysDigest('a'), 12 => opArraysDigest('a')], '1.2.3'])
        ->and($runs)->toHaveCount(2);
});

it('reads no digest past the first answer that is none', function (): void {
    [$compiled] = opArraysAnswered(sprintf("1.2.3\n%s\nnot a digest\n%s\n", opArraysDigest('a'), opArraysDigest('c')), [
        'first' => "<?php\n",
        'second' => "<?php\n",
        'third' => "<?php\n",
    ]);

    expect($compiled)->toBe([['first' => opArraysDigest('a'), 'third' => opArraysDigest('a')], '1.2.3']);
});

it('asks no more children once one loaded no extension or answered nothing', function (string $answer): void {
    [$compiled, $runs] = opArraysAnswered($answer, ['first' => "<?php\n", 'second' => "<?php\n", 'third' => "<?php\n"]);

    expect($compiled)->toEqual(NotGiven::value())
        ->and($runs)->toHaveCount(1);
})->with([
    'no extension' => ["-\n"],
    'nothing' => [''],
]);

it('compiles nothing where PHP can start no process', function (): void {
    expect(FreshProcess::answer(sprintf(<<<'PHP'
        use NightWorksIO\MutationGate\Adapter\Outbreak\OpArrays;
        use NightWorksIO\MutationGate\Core\File\Contents;
        use NightWorksIO\MutationGate\Core\NotGiven;

        posix_setrlimit(POSIX_RLIMIT_NPROC, 1, 1);
        echo json_encode(new OpArrays(%s, '/nowhere/outbreak.so', %s, 5.0)->digests(['first' => Contents::of("<?php\n")]) instanceof NotGiven);
        PHP, var_export(PHP_BINARY, return: true), var_export(Scratch::directory(), return: true))))->toBe('true');
})->skip(fn(): bool => ! function_exists('posix_setrlimit') || posix_getuid() === 0, 'only a user other than root is held to a limit of processes');
