<?php

declare(strict_types=1);

namespace Scoutapm\UnitTests\CoreAgent;

use PHPUnit\Framework\TestCase;
use Scoutapm\Config;
use Scoutapm\Connector\ConnectionAddress;
use Scoutapm\CoreAgent\Launcher;
use Scoutapm\UnitTests\TestLogger;

/** @covers \Scoutapm\CoreAgent\Launcher */
final class LauncherTest extends TestCase
{
    private function connectionAddressFromString(string $connectionAddress): ConnectionAddress
    {
        $config = new Config();
        $config->set(Config\ConfigKey::CORE_AGENT_SOCKET_PATH, $connectionAddress);

        return ConnectionAddress::fromConfig($config);
    }

    public function testLaunchingCoreAgentWithInvalidGlibcIsCaught(): void
    {
        $logger = new TestLogger();

        $launcher = new Launcher(
            $logger,
            $this->connectionAddressFromString('socket-path.sock'),
            null,
            null,
            null
        );

        self::assertFalse($launcher->launch(__DIR__ . '/emulated-core-agent-glibc-error.sh'));
        $logger->hasDebugThatContains('core-agent currently needs at least glibc 2.18');
    }

    public function testLaunchCoreAgentWithNonZeroExitCodeIsCaught(): void
    {
        $logger = new TestLogger();

        $launcher = new Launcher(
            $logger,
            $this->connectionAddressFromString('socket-path.sock'),
            null,
            null,
            null
        );

        self::assertFalse($launcher->launch(__DIR__ . '/emulated-unknown-error.sh'));
        $logger->hasDebugThatContains('core-agent exited with non-zero status. Output: Something bad went wrong');
    }

    public function testCoreAgentCanBeLaunched(): void
    {
        $logger = new TestLogger();

        $launcher = new Launcher(
            $logger,
            $this->connectionAddressFromString('/tmp/socket-path.sock'),
            'TRACE',
            '/tmp/core-agent.log',
            '/tmp/core-agent-config.ini'
        );

        self::assertTrue($launcher->launch(__DIR__ . '/emulated-happy-path.sh'));
    }
}
