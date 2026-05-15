<?php

declare(strict_types=1);

namespace GsppManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

abstract class UnitTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_SERVER  = array_merge($_SERVER, [
            'REQUEST_METHOD'   => 'GET',
            'REQUEST_URI'      => '/',
            'REMOTE_ADDR'      => '127.0.0.1',
            'HTTP_USER_AGENT'  => 'PHPUnit',
        ]);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }
}
