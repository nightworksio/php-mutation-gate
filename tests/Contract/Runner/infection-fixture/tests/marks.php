<?php

declare(strict_types=1);

// Where LIBRARY_HIDES_ERRORS is set, PHP's errors are shown nowhere, as a
// project's bootstrap, or its PHPUnit config's <ini>, can set before any
// test runs.
if (getenv('LIBRARY_HIDES_ERRORS') !== false) {
    ini_set('display_errors', '0');
}

// The runner contract's mark CONTRACT_WRAPPED, left only where the variable
// names a file. Composer's autoloader loads this file before PHPUnit reads
// its bootstrap, so the check waits for the run's end: by then a mutant's own
// run serves every file through Infection's user-space `file://` wrapper.
if (getenv('CONTRACT_WRAPPED') !== false) {
    register_shutdown_function(static function (): void {
        $self = fopen(__FILE__, 'r');

        if ($self !== false && stream_get_meta_data($self)['wrapper_type'] === 'user-space') {
            touch((string) getenv('CONTRACT_WRAPPED'));
        }
    });
}
