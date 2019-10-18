<?php

declare(strict_types=1);

namespace Scoutapm\UnitTests\Events\Request;

use PHPUnit\Framework\TestCase;
use Scoutapm\Events\Request\Request;
use function json_decode;
use function json_encode;
use function next;
use function reset;
use function str_repeat;
use function time;

/** @covers \Scoutapm\Events\Request\Request */
final class RequestTest extends TestCase
{
    public function testCanBeInitialized() : void
    {
        $request = new Request();
        self::assertNotNull($request);
    }

    public function testCanBeStopped() : void
    {
        $request = new Request();

        self::assertNull(json_decode(json_encode($request), true)['BatchCommand']['commands'][1]['FinishRequest']['timestamp']);

        $request->stop();

        self::assertIsString(json_decode(json_encode($request), true)['BatchCommand']['commands'][3]['FinishRequest']['timestamp']);
    }

    public function testRequestIsStoppedIfRunning() : void
    {
        $request = new Request();

        self::assertNull(json_decode(json_encode($request), true)['BatchCommand']['commands'][1]['FinishRequest']['timestamp']);

        $request->stopIfRunning();

        self::assertIsString(json_decode(json_encode($request), true)['BatchCommand']['commands'][3]['FinishRequest']['timestamp']);
    }

    public function testRequestFinishTimestampIsNotChangedWhenStopIfRunningIsCalledOnAStoppedRequest() : void
    {
        $request = new Request();
        $request->stop(time() - 100.0);
        $originalStopTime = json_decode(json_encode($request), true)['BatchCommand']['commands'][3]['FinishRequest']['timestamp'];

        $request->stopIfRunning();

        self::assertSame($originalStopTime, json_decode(json_encode($request), true)['BatchCommand']['commands'][3]['FinishRequest']['timestamp']);
    }

    public function testMemoryUsageIsTaggedWhenRequestStopped() : void
    {
        $request = new Request();

        /** @noinspection PhpUnusedLocalVariableInspection */
        $block = str_repeat('a', 1000000);

        $request->stopIfRunning();

        $tagRequest = json_decode(json_encode($request), true)['BatchCommand']['commands'][1]['TagRequest'];

        self::assertSame('memory_delta', $tagRequest['tag']);
        self::assertGreaterThan(0, $tagRequest['value']);
    }

    public function testRequestUriFromServerGlobalIsTaggedWhenRequestStopped() : void
    {
        $_SERVER['REQUEST_URI'] = '/request-uri-from-server';

        $request = new Request();
        $request->stopIfRunning();

        $tagRequest = json_decode(json_encode($request), true)['BatchCommand']['commands'][2]['TagRequest'];

        self::assertSame('path', $tagRequest['tag']);
        self::assertSame('/request-uri-from-server', $tagRequest['value']);
    }

    public function testOrigPathInfoFromServerGlobalIsTaggedWhenRequestStopped() : void
    {
        $_SERVER['REQUEST_URI']    = null;
        $_SERVER['ORIG_PATH_INFO'] = '/orig-path-info-from-server';

        $request = new Request();
        $request->stopIfRunning();

        $tagRequest = json_decode(json_encode($request), true)['BatchCommand']['commands'][2]['TagRequest'];

        self::assertSame('path', $tagRequest['tag']);
        self::assertSame('/orig-path-info-from-server', $tagRequest['value']);
    }

    public function testRequestUriFromOverrideIsTaggedWhenRequestStopped() : void
    {
        $_SERVER['REQUEST_URI'] = '/request-uri-from-server';

        $request = new Request();
        $request->overrideRequestUri('/overridden-request-uri');
        $request->stopIfRunning();

        $tagRequest = json_decode(json_encode($request), true)['BatchCommand']['commands'][2]['TagRequest'];

        self::assertSame('path', $tagRequest['tag']);
        self::assertSame('/overridden-request-uri', $tagRequest['value']);
    }

    public function testJsonSerializes() : void
    {
        // Make a request with some interesting content.
        $request = new Request();
        $request->tag('t', 'v');
        $span = $request->startSpan('foo');
        $span->tag('spantag', 'spanvalue');
        $request->stopSpan();
        $request->stop();

        $serialized = $request->jsonSerialize();
        self::assertIsArray($serialized);

        self::assertArrayHasKey('BatchCommand', $serialized);
        self::assertArrayHasKey('commands', $serialized['BatchCommand']);
        $commands = $serialized['BatchCommand']['commands'];

        self::assertArrayHasKey('StartRequest', reset($commands));
        self::assertArrayHasKey('TagRequest', next($commands));

        self::assertArrayHasKey('StartSpan', next($commands));
        self::assertArrayHasKey('TagSpan', next($commands));
        self::assertArrayHasKey('StopSpan', next($commands));

        self::assertArrayHasKey('TagRequest', next($commands));
        self::assertArrayHasKey('TagRequest', next($commands));

        self::assertArrayHasKey('FinishRequest', next($commands));
    }
}
