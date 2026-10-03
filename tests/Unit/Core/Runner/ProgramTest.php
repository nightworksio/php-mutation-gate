<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Program;

it('says what each program is called', function (Program $program, string $title): void {
    expect($program->title())->toBe($title);
})->with([
    [Program::Pest, 'Pest'],
    [Program::Infection, 'Infection'],
    [Program::PhpUnit, 'PHPUnit'],
]);
