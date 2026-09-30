<?php

declare(strict_types=1);

// The fixture's bootstrap, which an analyser loads as it loads a project's.
// It stops the analyser when it can see the variable the contract suite sets
// and withholds, so an adapter that hands its environment on unfiltered
// cannot answer. The name is StaticCheckerFake::LEAK's.
if (getenv('MUTATION_GATE_CONTRACT_TOKEN') !== false) {
    fwrite(STDERR, "The analyser can see MUTATION_GATE_CONTRACT_TOKEN, which it runs without.\n");

    exit(1);
}
