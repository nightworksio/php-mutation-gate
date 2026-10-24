<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Plugin;
use NightWorksIO\MutationGate\Adapter\PhpUnit\IssueKillers;
use NightWorksIO\MutationGate\Cli\PestPlugin;
use NightWorksIO\MutationGate\Core\Test\RunIssues;
use NightWorksIO\MutationGate\Tests\Support\Environment;
use NightWorksIO\MutationGate\Tests\Support\GateEnvironment;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Contracts\Plugins\Bootable;
use Pest\Contracts\Plugins\HandlesArguments;

it('is the Pest plugin Pest boots and hands arguments, handling them as the Pest adapter\'s plugin does', function (): void {
    $arguments = ['vendor/bin/pest', '--filter=adds'];

    expect(new PestPlugin())->toBeInstanceOf(Bootable::class)
        ->and(new PestPlugin())->toBeInstanceOf(HandlesArguments::class)
        ->and(new PestPlugin()->handleArguments($arguments))
        ->toBe(new Plugin(static fn(): RunIssues => new IssueKillers())->handleArguments($arguments));
});

it('boots the Pest adapter\'s plugin as Pest boots it, loading the bridges a run names', function (): void {
    $file = sprintf('%s/bridges.php', Scratch::untilExit());
    file_put_contents($file, "<?php\n\nfunction pestPluginBootedTheAdaptersPlugin(): string\n{\n    return 'booted';\n}\n");

    Environment::during(
        [...array_fill_keys(GateEnvironment::names(), null), GateVariable::Mutators->value => $file],
        static function (): PestPlugin {
            $plugin = new PestPlugin();
            $plugin->boot();

            return $plugin;
        },
    );

    expect(function_exists('pestPluginBootedTheAdaptersPlugin'))->toBeTrue();
});
