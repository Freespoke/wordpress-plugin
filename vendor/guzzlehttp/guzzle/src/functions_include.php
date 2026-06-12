<?php

namespace FreespokeDeps;

// Don't redefine the functions if included multiple times.
if (!\function_exists('FreespokeDeps\GuzzleHttp\describe_type')) {
    require __DIR__ . '/functions.php';
}
