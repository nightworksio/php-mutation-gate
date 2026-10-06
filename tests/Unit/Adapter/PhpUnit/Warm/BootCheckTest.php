<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Boot;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\BootCheck;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Refusal;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Declared;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A directory listing open files by their numbers, as the system lists a
 * process's, with a socket under each name; and the sockets, to close once
 * read.
 *
 * @return array{string, list<resource>}
 */
function bootCheckListing(string ...$names): array
{
    $directory = Scratch::directory();
    $sockets = [];

    foreach ($names as $name) {
        $socket = stream_socket_server(sprintf('unix://%s/%s', $directory, $name));
        $sockets = is_resource($socket) ? [...$sockets, $socket] : $sockets;
    }

    return [$directory, $sockets];
}

/** A directory listing no open file. */
function bootCheckSockets(): string
{
    return Scratch::directory();
}

function bootCheckBoot(): Boot
{
    return Boot::begun(Tree::at('vendor/autoload.php'))->done();
}

it('lets a boot be forked from that holds no socket open and loaded no file the run mutates', function (): void {
    expect(BootCheck::of([Tree::at('src/Core/NotGiven.php.missing')], bootCheckBoot(), bootCheckSockets())->refusal())
        ->toEqual(NotGiven::value());
});

it('refuses a boot that holds a socket open, beyond the standard streams, naming the file of the boot that ran last', function (): void {
    [$listing, $sockets] = bootCheckListing('2', 'name', '7');
    $refusal = BootCheck::of([], bootCheckBoot(), $listing)->refusal();

    foreach ($sockets as $socket) {
        fclose($socket);
    }

    expect($refusal)->toEqual(Refusal::guarded(
        'The boot left 1 socket open once vendor/autoload.php ran, which every forked child would share, so each mutant ran fresh.',
    ));
});

it('refuses a boot that loaded a file the run mutates, naming where', function (): void {
    $refusal = BootCheck::of([Tree::at('src/Core/NotGiven.php')], bootCheckBoot(), bootCheckSockets())->refusal();

    expect($refusal)->toEqual(Refusal::guarded(
        'The boot loaded src/Core/NotGiven.php, which this run mutates, at vendor/autoload.php, so each mutant ran fresh.',
    ));
});

it('counts the sockets among PHP\'s own streams where the system lists no open files', function (): void {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $refusal = BootCheck::of([], bootCheckBoot(), sprintf('%s/nowhere', Scratch::directory()))->refusal();
    if (is_resource($socket)) {
        fclose($socket);
    }

    expect($refusal instanceof Refusal ? $refusal->reason() : '')->toStartWith('The boot left ');
});

it('takes no file that resolves to nowhere for one the run mutates, though the process loaded a file since removed', function (): void {
    $gone = sprintf('%s/gone.php', Scratch::directory());
    file_put_contents($gone, "<?php\n");
    require $gone;
    unlink($gone);

    expect(BootCheck::of([sprintf('%s/missing.php', Scratch::directory())], bootCheckBoot(), bootCheckSockets())->refusal())
        ->toEqual(NotGiven::value());
});

it('refuses a boot that started PHPUnit\'s events, naming the file of the boot that ran last', function (): void {
    $events = Declared::name('BootCheckEvents');
    $boot = Boot::begun(Tree::at('vendor/autoload.php'), $events);
    Declared::class($events);

    expect(BootCheck::of([], $boot->done(), bootCheckSockets())->refusal())->toEqual(Refusal::guarded(
        'The boot started PHPUnit\'s events once vendor/autoload.php ran, which every child would inherit, so each mutant ran fresh.',
    ));
});

it('counts only the sockets among the open files the system lists', function (): void {
    $listing = bootCheckSockets();
    file_put_contents(sprintf('%s/5', $listing), 'a file');
    mkdir(sprintf('%s/6', $listing));

    expect(BootCheck::of([], bootCheckBoot(), $listing)->refusal())->toEqual(NotGiven::value());
});
