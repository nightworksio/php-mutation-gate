<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpSpec\Launch;
use NightWorksIO\MutationGate\Adapter\PhpSpec\LaunchOption;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;
use PhpSpec\Runner\Maintainer\ErrorMaintainer;

afterEach(function (): void {
    putenv('SHELL_VERBOSITY');
    unset($_ENV['SHELL_VERBOSITY'], $_SERVER['SHELL_VERBOSITY']);
    Scratch::sweep();
});

/**
 * A scratch project of one spec, of a class of its own, whose one example
 * passes and other fails: its config's path, and the spec's class.
 *
 * @return array{string, string}
 */
function launchedSpecs(): array
{
    $root = Scratch::directory();
    $class = sprintf('Launched%sSpec', hash('xxh3', $root));
    Scratch::write($root, 'phpspec.yml', "suites:\n  scratch:\n    spec_path: '%paths.config%'\n    src_path: '%paths.config%'\n");
    Scratch::write($root, sprintf('spec/%s.php', $class), sprintf(<<<'PHP'
        <?php

        namespace spec;

        use PhpSpec\Exception\Example\FailureException;
        use PhpSpec\ObjectBehavior;

        final class %s extends ObjectBehavior
        {
            public function it_passes(): void
            {
                $this->beAnInstanceOf(\stdClass::class);
            }

            public function it_fails(): void
            {
                throw new FailureException('It fails.');
            }
        }
        PHP, $class));

    return [sprintf('%s/phpspec.yml', $root), sprintf('spec\\%s', $class)];
}

/**
 * Every error handler PHP holds, the one it calls first last, each taken off.
 *
 * @return list<callable>
 */
function takenErrorHandlers(): array
{
    $handlers = [];
    $current = set_error_handler(null);
    restore_error_handler();

    while ($current !== null) {
        $handlers = [$current, ...$handlers];
        restore_error_handler();
        $current = set_error_handler(null);
        restore_error_handler();
    }

    return $handlers;
}

/**
 * What a launch answers, with the error handlers it left taken off: PhpSpec's
 * error maintainer sets the handler it found again as each example ends,
 * where it would restore it, so each example run leaves its own handler and
 * another copy of the one it found above those it found.
 *
 * @param list<string> $arguments
 */
function launched(array $arguments): int
{
    $exit = Launch::run('mutation-gate-phpspec', Tree::at('vendor/autoload.php'), $arguments);
    $found = true;

    foreach (takenErrorHandlers() as $handler) {
        $found = $found && (!is_array($handler) || !array_any($handler, static fn(mixed $part): bool => $part instanceof ErrorMaintainer));

        if ($found) {
            set_error_handler($handler);
        }
    }

    return $exit;
}

it('runs PhpSpec on its own arguments, with the gate\'s extension loaded, and answers what PhpSpec exits with', function (): void {
    [$config, $class] = launchedSpecs();
    $listing = sprintf('%s/listing.json', dirname($config));
    $listed = launched(['run', sprintf('--config=%s', $config), '--quiet', '--no-interaction', LaunchOption::Listing->with($listing)]);
    $failed = launched(['run', sprintf('--config=%s', $config), '--quiet', '--no-interaction']);
    $filtered = launched(['run', sprintf('--config=%s', $config), '--quiet', '--no-interaction', LaunchOption::Filter->with('passes$')]);

    expect($listed)->toBe(0)
        ->and(json_decode((string) file_get_contents($listing), associative: true))
        ->toBe(['listed' => [sprintf('%s::it_passes', $class), sprintf('%s::it_fails', $class)], 'groups' => []])
        ->and($failed)->toBe(1)
        ->and($filtered)->toBe(0);
});
