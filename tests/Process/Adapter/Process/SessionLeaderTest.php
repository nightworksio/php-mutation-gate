<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Process\SessionLeader;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ProcessHold;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$holds = [
    'holds:src/Adapter/Process/SessionLeader.php',
];

afterEach(function (): void {
    putenv(SessionLeader::VARIABLE);
    Scratch::sweep();
});

it('leads by this PHP where the PATH holds no setsid and that PHP can leave a session, asking it once, and requires none it cannot', function (): void {
    $path = (string) getenv('PATH');
    putenv(sprintf('PATH=%s', Scratch::directory()));

    try {
        $auto = SessionLeader::toldHere(ProcessHold::Auto);
        $leader = getenv(SessionLeader::VARIABLE);
        $again = SessionLeader::toldHere(ProcessHold::Auto);
        $required = SessionLeader::toldHere(ProcessHold::Require);
    } finally {
        putenv(sprintf('PATH=%s', $path));
    }

    expect([$auto, $again])->toBe([true, true])
        ->and($leader === false ? $required instanceof CannotJudge : [$leader, $required])
        ->toBe($leader === false ? true : [PHP_BINARY, true]);
})->group(...$holds);
