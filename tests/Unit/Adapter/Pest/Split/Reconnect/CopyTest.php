<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Copier;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Copy;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Reach;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Reconnect\Session;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Connection;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Driver;
use NightWorksIO\MutationGate\Core\NotGiven;

it('drops its database by its copier, through the admin connection, and leaves a drop that fails for the server to clear', function (): void {
    $admin = Connection::of(Driver::MySql, 'mysql:host=127.0.0.1', 'root', '', [], []);
    $copier = new class implements Copier {
        /** @var list<array{Connection, string}> */
        public private(set) array $dropped = [];

        public function holdsState(Session $session): bool
        {
            return false;
        }

        public function settings(Session $session): NotGiven
        {
            return NotGiven::value();
        }

        public function copied(Connection $original, Reach $reach, string $token, string $directory): NotGiven
        {
            return NotGiven::value();
        }

        public function drop(Connection $admin, string $copy): void
        {
            $this->dropped[] = [$admin, $copy];

            throw new PDOException('The server has gone away.');
        }
    };

    new Copy('testing_mg_a1', $admin, $copier)->dropped();

    expect($copier->dropped)->toBe([[$admin, 'testing_mg_a1']]);
});
