<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\Zygote;
use NightWorksIO\MutationGate\Tests\Support\FreshProcess;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A project whose `bootstrap/app.php` counts each boot and each kernel bootstrapped, and throws on the boots
 * named; where it should not boot at all, it returns what is no application.
 *
 * @param list<int> $throwsOn
 */
function zygoteProject(array $throwsOn = [], bool $application = true): string
{
    $project = Scratch::directory();
    Scratch::write($project, 'bootstrap/app.php', sprintf(<<<'PHP'
        <?php

        $GLOBALS['boots'] = ($GLOBALS['boots'] ?? 0) + 1;

        if (in_array($GLOBALS['boots'], %s, strict: true)) {
            throw new RuntimeException('The application does not boot.');
        }

        return %s ? new class {
            public function make(string $abstract): object
            {
                return new class {
                    public function bootstrap(): void
                    {
                        $GLOBALS['kernels'] = ($GLOBALS['kernels'] ?? 0) + 1;
                    }
                };
            }
        } : 'no application';
        PHP, var_export($throwsOn, return: true), var_export($application, return: true)));

    return $project;
}

/** What a fresh PHP process that asks the zygote for the project's application twice says it booted and got. */
function zygoteAsked(string $project): string
{
    return FreshProcess::answer(sprintf(<<<'PHP'
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Zygote;

        $first = Zygote::application(%s);
        $second = Zygote::application(%s);

        echo json_encode([$GLOBALS['boots'], $GLOBALS['kernels'] ?? 0, $first === $second, is_object($first)]);
        PHP, var_export(sprintf('%s/bootstrap/app.php', $project), return: true), var_export(sprintf('%s/bootstrap/app.php', $project), return: true)));
}

it('points the application\'s base path at a bootstrap that asks it for the application, where the project has one to boot', function (): void {
    $project = zygoteProject();
    $shim = sprintf('%s/shim', Scratch::directory());

    expect(FreshProcess::answer(sprintf(<<<'PHP'
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Zygote;

        $_ENV['APP_BASE_PATH'] = %s;
        $prepared = Zygote::prepared(%s);
        $named = [getenv('APP_BASE_PATH'), $_SERVER['APP_BASE_PATH']];
        unset($_ENV['APP_BASE_PATH']);
        $_SERVER['APP_BASE_PATH'] = %s;
        $unbooted = Zygote::prepared(%s);

        echo json_encode([$prepared, ...$named, $unbooted]);
        PHP, var_export($project, return: true), var_export($shim, return: true), var_export(Scratch::directory(), return: true), var_export(sprintf('%s/other', $shim), return: true))))
        ->json()->toBe([true, $shim, $shim, false])
        ->and((string) file_get_contents(sprintf('%s/bootstrap/app.php', $shim)))
        ->toContain(sprintf('Zygote::application(%s)', var_export(sprintf('%s/bootstrap/app.php', $project), return: true)));
});

it('finds the project\'s bootstrap where it runs, with no base path named', function (): void {
    $project = zygoteProject();
    $here = (string) getcwd();
    chdir($project);

    $prepared = Zygote::prepared(sprintf('%s/shim', Scratch::directory()));
    chdir($here);
    putenv('APP_BASE_PATH');
    unset($_ENV['APP_BASE_PATH'], $_SERVER['APP_BASE_PATH']);

    expect($prepared)->toBeTrue();
});

it('boots the application once, and hands a fresh one where no stream forks the test', function (): void {
    expect(zygoteAsked(zygoteProject()))->json()->toBe([3, 1, false, true]);
});

it('hands a fresh application each time where the one it boots throws or is no application', function (bool $throws, bool $application): void {
    expect(zygoteAsked(zygoteProject($throws ? [1] : [], $application)))->json()->toBe([3, 0, ! $application, $application]);
})->with([
    'it throws' => [true, true],
    'it is no application' => [false, false],
]);

it('hands the booted application to the test\'s own process forked from the stream, and skips the test in the stream', function (): void {
    $project = zygoteProject();
    $root = Scratch::directory();
    $results = sprintf('%s/results.jsonl', $root);
    Scratch::write($root, 'plan.json', (string) json_encode(['results' => $results, 'sites' => []]));

    expect(FreshProcess::answer(sprintf(<<<'CODE'
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Stream;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Zygote;
        use PHPUnit\Framework\SkippedTest;

        Stream::from(%s, new \NightWorksIO\MutationGate\Adapter\Process\LiveProcesses());
        Stream::began('T::booted');

        try {
            echo is_object(Zygote::application(%s)) ? 'taken:' : 'fresh:', $GLOBALS['boots'], ';';
            Stream::ended();
        } catch (SkippedTest) {
            echo 'skipped';
        }

        Stream::finished();
        CODE, var_export(sprintf('%s/plan.json', $root), return: true), var_export(sprintf('%s/bootstrap/app.php', $project), return: true))))
        ->toBe('taken:1;skipped');
})->skip(fn(): bool => ! function_exists('pcntl_fork') || ! function_exists('posix_kill'), 'a split stream needs a PHP that forks');
