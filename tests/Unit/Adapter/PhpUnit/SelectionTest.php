<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Selection;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

it('selects tests by their ids where PHPUnit can read each back from a line', function (): void {
    expect(Selection::of(TestIds::of(TestId::of('Tests\MoneyTest::testAdds'), TestId::of("Tests\\MoneyTest::testAdds#a\rb"))))
        ->toBe(Selection::Ids)
        ->and(Selection::of(TestIds::none()))->toBe(Selection::Ids);
});

it('selects tests by their files where an id has a line break or ends in a carriage return', function (string $id): void {
    expect(Selection::of(TestIds::of(TestId::of('Tests\MoneyTest::testAdds'), TestId::of($id))))->toBe(Selection::Files);
})->with([
    'a line break' => ["Tests\\MoneyTest::testAdds#a\nb"],
    'a carriage return at the end' => ["Tests\\MoneyTest::testAdds#a\r"],
]);

it('has PHPUnit read each selection from its file', function (): void {
    expect(Selection::Ids->option('/g/ids.txt'))->toBe('--test-id-filter-file=/g/ids.txt')
        ->and(Selection::Files->option('/g/files.txt'))->toBe('--test-files-file=/g/files.txt');
});
