<?php

declare(strict_types=1);

$phpunit = __DIR__ . '/../vendor/bin/phpunit';
$config  = __DIR__ . '/phpunit.xml';
$args    = implode(' ', array_map('escapeshellarg', array_slice($argv, 1)));

passthru(escapeshellarg($phpunit) . ' --configuration ' . escapeshellarg($config) . ' ' . $args, $code);
exit($code);
