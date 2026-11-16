<?php

declare(strict_types=1);

use Library\Booted;

// An application as Laravel's bootstrap returns one: its console kernel's
// bootstrap readies its services, running Booted's sum as it boots.
return new class {
    public int $services = 0;

    public function make(string $abstract): object
    {
        return new class ($this) {
            public function __construct(private object $application)
            {
            }

            public function bootstrap(): void
            {
                $this->application->services = new Booted()->readied(1);
            }
        };
    }
};
