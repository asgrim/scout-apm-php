<?php

declare(strict_types=1);

namespace Scoutapm\UnitTests\Extension;

use PHPUnit\Framework\TestCase;
use Scoutapm\Extension\PotentiallyAvailableExtensionCapabilities;
use Scoutapm\Extension\RecordedCall;
use function extension_loaded;
use function file_get_contents;
use function reset;

/** @covers \Scoutapm\Extension\PotentiallyAvailableExtensionCapabilities */
final class PotentiallyAvailableExtensionCapabilitiesTest extends TestCase
{
    public function setUp() : void
    {
        parent::setUp();

        // First call is to clear any existing logged calls from the extension so we are in a known state
        /** @noinspection UnusedFunctionResultInspection */
        (new PotentiallyAvailableExtensionCapabilities())->getCalls();
    }

    public function testGetCallsReturnsEmptyArrayWhenExtensionNotAvailable() : void
    {
        if (extension_loaded('scoutapm')) {
            self::markTestSkipped('Test can only be run when extension is not available');

            return;
        }

        /** @noinspection UnusedFunctionResultInspection */
        file_get_contents(__FILE__);
        self::assertEquals([], (new PotentiallyAvailableExtensionCapabilities())->getCalls());
    }

    public function testGetCallsReturnsFileGetContentsCallWhenExtensionIsAvailable() : void
    {
        if (! extension_loaded('scoutapm')) {
            self::markTestSkipped('Test can only be run when extension is loaded');
        }

        /** @noinspection UnusedFunctionResultInspection */
        file_get_contents(__FILE__);

        $calls = (new PotentiallyAvailableExtensionCapabilities())->getCalls();

        self::assertCount(1, $calls);
        self::assertContainsOnlyInstancesOf(RecordedCall::class, $calls);

        $recordedCall = reset($calls);

        self::assertSame('file_get_contents', $recordedCall->functionName());
        self::assertGreaterThan(0, $recordedCall->timeTakenInSeconds());
    }

    public function testRecordedCallsAreClearedWhenExtensionIsAvailable() : void
    {
        if (! extension_loaded('scoutapm')) {
            self::markTestSkipped('Test can only be run when extension is loaded');
        }

        /** @noinspection UnusedFunctionResultInspection */
        file_get_contents(__FILE__);

        self::assertCount(1, (new PotentiallyAvailableExtensionCapabilities())->getCalls());

        self::assertCount(0, (new PotentiallyAvailableExtensionCapabilities())->getCalls());

        /** @noinspection UnusedFunctionResultInspection */
        file_get_contents(__FILE__);

        self::assertCount(1, (new PotentiallyAvailableExtensionCapabilities())->getCalls());

        self::assertCount(0, (new PotentiallyAvailableExtensionCapabilities())->getCalls());
    }
}
