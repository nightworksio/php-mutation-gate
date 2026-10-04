<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Psalm\PsalmXml;
use NightWorksIO\MutationGate\Core\Analysis\AnalyserSettings;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A config with these contents, read from this file of a project at this root. */
function psalmXml(string $contents, string $file = 'psalm.xml', string $root = '/work'): PsalmXml
{
    $read = PsalmXml::read(Contents::of($contents), Path::of($file), Root::of($root));

    return $read instanceof PsalmXml ? $read : throw new LogicException($read->why());
}

/** The configuration a config with these contents writes, read in a project at this root. */
function psalmWritten(string $contents, string $root): string
{
    $xml = PsalmXml::read(Contents::of($contents), Path::of('psalm.xml'), Root::of($root));
    $settings = $xml instanceof PsalmXml ? $xml->settings($root) : $xml;

    return $settings instanceof AnalyserSettings ? $settings->written() : throw new LogicException($settings->why());
}

$config = <<<'XML'
    <?xml version="1.0"?>
    <psalm
        errorLevel="1"
        errorBaseline="psalm-baseline.xml"
        autoloader="tests/bootstrap.php"
        xmlns="https://getpsalm.org/schema/config"
        xmlns:xi="http://www.w3.org/2001/XInclude"
    >
        <projectFiles>
            <directory name="src"/>
            <directory name="modules/*/Domain"/>
            <file name="bin/console.php"/>
            <file name="/opt/shared/Helpers.php"/>
            <ignoreFiles>
                <directory name="src/Legacy"/>
                <file name="src/Generated.php"/>
            </ignoreFiles>
        </projectFiles>
        <stubs>
            <file name="stubs/Redis.phpstub"/>
            <file name=""/>
        </stubs>
        <plugins>
            <plugin filename="tools/MyPlugin.php"/>
            <pluginClass class="Psalm\PhpUnitPlugin\Plugin"/>
        </plugins>
        <xi:include href="psalm.issues.xml"/>
    </psalm>
    XML;

it('analyses the files its project files name, less those they ignore, from the config\'s own directory', function () use ($config): void {
    $xml = psalmXml($config);

    expect($xml->holds('/work/src/Money.php'))->toBeTrue()
        ->and($xml->holds('/work/src/Deep/Wallet.php'))->toBeTrue()
        ->and($xml->holds('/work/modules/Billing/Domain/Invoice.php'))->toBeTrue()
        ->and($xml->holds('/work/bin/console.php'))->toBeTrue()
        ->and($xml->holds('/opt/shared/Helpers.php'))->toBeTrue()
        ->and($xml->holds('/work/src/Legacy/Old.php'))->toBeFalse()
        ->and($xml->holds('/work/src/Generated.php'))->toBeFalse()
        ->and($xml->holds('/work/modules/Billing/Http/Controller.php'))->toBeFalse()
        ->and($xml->holds('/work/srcs/Money.php'))->toBeFalse()
        ->and($xml->holds('/work/tests/MoneyTest.php'))->toBeFalse()
        ->and(psalmXml('<psalm><projectFiles><directory name="src"/></projectFiles></psalm>', 'build/psalm.xml')->holds('/work/build/src/A.php'))
        ->toBeTrue();
});

it('names the file itself, and the baseline, autoloader, stubs, file plugins and includes it reads, under the root', function () use ($config): void {
    $settings = psalmXml($config)->settings('/work');

    expect($settings instanceof AnalyserSettings ? $settings->references() : $settings)->toEqual(Paths::of(
        Path::of('psalm.xml'),
        Path::of('psalm-baseline.xml'),
        Path::of('tests/bootstrap.php'),
        Path::of('stubs/Redis.phpstub'),
        Path::of('tools/MyPlugin.php'),
        Path::of('psalm.issues.xml'),
    ))
        ->and(psalmXml($config)->file())->toEqual(Path::of('psalm.xml'));
});

it('writes the configuration as the element tree it holds, alike wherever the project lies', function (): void {
    $here = psalmWritten('<psalm errorLevel="2"><projectFiles><directory name="src"/></projectFiles>  </psalm>', '/work');

    expect($here)->toBe(psalmWritten('<psalm errorLevel="2"><projectFiles><directory name="src"/></projectFiles></psalm>', '/elsewhere'))
        ->and($here)->toBe(
            '{"attributes":{"errorLevel":"2"},"children":[{"attributes":[],"children":[{"attributes":{"name":"src"},'
            . '"children":[],"element":"directory"}],"element":"projectFiles"}],"element":"psalm"}',
        )
        ->and(psalmWritten('<psalm><issueHandlers><MixedReturn errorLevel="info">why</MixedReturn></issueHandlers></psalm>', '/work'))
        ->toContain('"text":"why"');
});

it('is no config where the file is not XML', function (): void {
    expect(PsalmXml::read(Contents::of('<psalm'), Path::of('psalm.xml'), Root::of('/work')))
        ->toEqual(CannotJudge::because('Psalm\'s config psalm.xml is not XML.'));
});

it('analyses nothing where it names no project files', function (): void {
    expect(psalmXml('<psalm/>')->holds('/work/src/Money.php'))->toBeFalse();
});

it('names a file in a directory it names through a link by the directory as named, as Psalm names it by where it is', function (): void {
    $project = Scratch::directory();
    $real = Scratch::directory();
    symlink($real, sprintf('%s/src', $project));
    $xml = psalmXml('<psalm><projectFiles><directory name="src"/><directory name="lib"/></projectFiles></psalm>', root: $project);
    $there = (string) realpath($real);

    expect($xml->spelt(sprintf('%s/Money.php', $there)))->toBe(sprintf('%s/src/Money.php', $project))
        ->and($xml->spelt($there))->toBe(sprintf('%s/src', $project))
        ->and($xml->spelt(sprintf('%s-other/Money.php', $there)))->toBe(sprintf('%s-other/Money.php', $there))
        ->and($xml->spelt(sprintf('%s/lib/Rate.php', $project)))->toBe(sprintf('%s/lib/Rate.php', $project));
});
