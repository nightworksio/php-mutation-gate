<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\Descriptors;
use NightWorksIO\MutationGate\Tests\Support\Databases;
use NightWorksIO\MutationGate\Tests\Support\FreshProcess;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('shares nothing it held at the start, and shares a socket opened since, until it closes', function (): void {
    expect(FreshProcess::answer(<<<'PHP'
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Descriptors;

        $start = Descriptors::held();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $opened = Descriptors::held()->shares($start);

        foreach (is_array($pair) ? $pair : [] as $socket) {
            fclose($socket);
        }

        echo json_encode([$opened, Descriptors::held()->shares($start)]);
        PHP))->json()->toBe([true, false]);
});

it('shares a file open for writing, held since the start or not, and no file open only for reading', function (): void {
    expect(FreshProcess::answer(<<<'PHP'
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Descriptors;

        $start = Descriptors::held();
        $read = fopen(__FILE__, 'r');
        $reading = Descriptors::held()->shares($start);
        $written = tmpfile();
        $writing = Descriptors::held()->shares($start);
        $sinceTheStart = Descriptors::held()->shares(Descriptors::held());

        echo json_encode([$reading, $writing, $sinceTheStart]);
        PHP))->json()->toBe([false, true, true]);
});

it('shares a file-backed SQLite database a test opened, where the system lists its descriptors', function (): void {
    $start = Descriptors::held();
    $database = Databases::made(sprintf('sqlite:%s/held.sqlite', Scratch::directory()), 'CREATE TABLE held (value INTEGER)');
    $shared = Descriptors::held()->shares($start);
    unset($database);

    expect($shared)->toBeTrue();
})->skip(
    fn(): bool => ! is_dir('/proc/self/fd') && ! function_exists('outbreak_descriptors'),
    'only the system\'s list of descriptors, or outbreak.so, sees a database client\'s own',
);

it('takes what outbreak.so counts of the descriptors a process holds, where it is loaded', function (): void {
    expect(FreshProcess::answer(<<<'PHP'
        function outbreak_descriptors(array $runner): array { return $GLOBALS['counted']; }

        use NightWorksIO\MutationGate\Adapter\Pest\Split\Descriptors;

        $GLOBALS['counted'] = [0, 0, 0];
        $start = Descriptors::held();
        $GLOBALS['counted'] = [0, 1, 0];
        $piped = Descriptors::held()->shares($start);
        $GLOBALS['counted'] = [0, 0, 0];

        echo json_encode([$piped, Descriptors::held()->shares($start)]);
        PHP))->json()->toBe([true, false]);
});

it('reads what PHP\'s own streams hold where the system lists no descriptors: files open for writing and sockets', function (): void {
    expect(FreshProcess::answer(<<<'PHP'
        use NightWorksIO\MutationGate\Adapter\Pest\Split\RunnerFiles;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Descriptors;

        $streams = new ReflectionMethod(Descriptors::class, 'streams');
        $start = $streams->invoke(null, RunnerFiles::ofThisProcess());
        $read = fopen(__FILE__, 'r');
        $reading = $streams->invoke(null, RunnerFiles::ofThisProcess())->shares($start);
        $written = tmpfile();
        $writing = $streams->invoke(null, RunnerFiles::ofThisProcess())->shares($start);
        fclose($written);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $socket = $streams->invoke(null, RunnerFiles::ofThisProcess())->shares($start);

        echo json_encode([$reading, $writing, $socket]);
        PHP))->json()->toBe([false, true, true]);
});
