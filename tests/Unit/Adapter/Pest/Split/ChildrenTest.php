<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Tests\Support\FreshProcess;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('takes no child it did not fork, and drops each copy a child still running holds as its process ends', function (): void {
    $directory = Scratch::directory();
    $file = sprintf('%s/main.sqlite', $directory);
    touch($file);

    $answer = json_decode(FreshProcess::answer(sprintf(<<<'PHP'
        use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildLimit;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildPlaces;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Children;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Descriptors;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Copies;
        use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlan;
        use NightWorksIO\MutationGate\Tests\Support\LaravelTesting;

        $manager = LaravelTesting::managing(['main' => ['driver' => 'sqlite', 'database' => %1$s]]);
        $manager->getConnections()['main']->getPdo()->exec('SELECT 1');
        $copies = Copies::taken(Descriptors::held(), %2$s);
        $children = new Children(new StreamPlan(%2$s . '/stream.jsonl', [], [], [], ChildPlaces::fixed(1), new ChildLimit(1.0, 1.0, 10.0)));
        $other = pcntl_fork();

        if ($other === 0) {
            exit(0);
        }

        usleep(300000);
        $reaped = $children->reaped(blocking: false);
        $pid = $children->forked('m1', 't1', $copies instanceof Copies ? $copies : Copies::none());

        if ($pid === 0) {
            usleep(300000);
            exit(0);
        }

        echo json_encode([$copies instanceof Copies, $reaped, $children->areRunning(), count(glob(%2$s . '/*.sqlite'))]);
        PHP, var_export($file, return: true), var_export($directory, return: true))), associative: true);

    expect($answer)->toBe([true, [], true, 2])
        ->and(glob(sprintf('%s/*.sqlite', $directory)))->toBe([$file]);
});
