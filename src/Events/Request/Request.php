<?php

declare(strict_types=1);

namespace Scoutapm\Events\Request;

use DateInterval;
use Exception;
use Scoutapm\Config;
use Scoutapm\Config\ConfigKey;
use Scoutapm\Connector\Command;
use Scoutapm\Connector\CommandWithChildren;
use Scoutapm\Events\Request\Exception\SpanLimitReached;
use Scoutapm\Events\Span\Span;
use Scoutapm\Events\Tag\Tag;
use Scoutapm\Events\Tag\TagRequest;
use Scoutapm\Helper\FetchRequestHeaders;
use Scoutapm\Helper\FormatUrlPathAndQuery;
use Scoutapm\Helper\MemoryUsage;
use Scoutapm\Helper\RecursivelyCountSpans;
use Scoutapm\Helper\Timer;

use function array_key_exists;
use function array_map;
use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function microtime;
use function strpos;
use function substr;

/** @internal */
class Request implements CommandWithChildren
{
    private const MAX_COMPLETE_SPANS = 3000;

    /** @var Timer */
    private $timer;
    /** @var Command[]|array<int, Command> */
    private $children = [];
    /** @var CommandWithChildren */
    private $currentCommand;
    /** @var RequestId */
    private $id;
    /** @var MemoryUsage */
    private $startMemory;
    /** @var string|null */
    private $requestUriOverride;
    /** @var int */
    private $spanCount = 0;
    /** @var int */
    private $leafNodeDepth = 0;
    /**
     * @psalm-var ConfigKey::URI_REPORTING_*
     * @var string
     */
    private $uriReportingOption;
    /**
     * @psalm-var list<string>
     * @var string[]
     */
    private $filteredParameters;

    /**
     * @deprecated Constructor will be made private in future, use {@see \Scoutapm\Events\Request\Request::fromConfigAndOverrideTime}
     *
     * @param string[] $filteredParameters
     *
     * @throws Exception
     *
     * @psalm-param Config\ConfigKey::URI_REPORTING_* $uriReportingOption
     * @psalm-param list<string> $filteredParameters
     */
    public function __construct(string $uriReportingOption, array $filteredParameters, ?float $override = null)
    {
        $this->id = RequestId::new();

        $this->timer       = new Timer($override);
        $this->startMemory = MemoryUsage::record();

        $this->currentCommand = $this;

        $this->uriReportingOption = $uriReportingOption;
        $this->filteredParameters = $filteredParameters;
    }

    /** @psalm-return ConfigKey::URI_REPORTING_* */
    private static function requireValidUriReportingValue(Config $config): string
    {
        /** @var mixed $uriReportingConfiguration */
        $uriReportingConfiguration = $config->get(ConfigKey::URI_REPORTING);

        if (! in_array($uriReportingConfiguration, [ConfigKey::URI_REPORTING_PATH_ONLY, ConfigKey::URI_REPORTING_FULL_PATH, ConfigKey::URI_REPORTING_FILTERED], true)) {
            $uriReportingConfiguration = (string) (new Config\Source\DefaultSource())->get(ConfigKey::URI_REPORTING);
        }

        /** @psalm-var ConfigKey::URI_REPORTING_* $uriReportingConfiguration */
        return $uriReportingConfiguration;
    }

    /** @psalm-return list<string> */
    private static function requireValidFilteredUriParameters(Config $config): array
    {
        /** @var mixed $uriFilteredParameters */
        $uriFilteredParameters = $config->get(ConfigKey::URI_FILTERED_PARAMETERS);

        /** @var list<string> $defaultFilteredParameters */
        $defaultFilteredParameters = (new Config\Source\DefaultSource())->get(ConfigKey::URI_FILTERED_PARAMETERS);

        if (! is_array($uriFilteredParameters)) {
            return $defaultFilteredParameters;
        }

        foreach ($uriFilteredParameters as $filteredParameter) {
            if (! is_string($filteredParameter)) {
                return $defaultFilteredParameters;
            }
        }

        /** @psalm-var array<array-key, string> $uriFilteredParameters */
        return array_values($uriFilteredParameters);
    }

    public static function fromConfigAndOverrideTime(Config $config, ?float $override = null): self
    {
        return new self(
            self::requireValidUriReportingValue($config),
            self::requireValidFilteredUriParameters($config),
            $override
        );
    }

    public function cleanUp(): void
    {
        array_map(
            static function (Command $command): void {
                $command->cleanUp();
            },
            $this->children
        );
        unset(
            $this->timer,
            $this->children,
            $this->currentCommand,
            $this->id,
            $this->startMemory,
            $this->requestUriOverride,
            $this->spanCount
        );
    }

    public function overrideRequestUri(string $newRequestUri): void
    {
        $this->requestUriOverride = $newRequestUri;
    }

    private function determineRequestPathFromServerGlobal(): string
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? null;

        if (is_string($requestUri)) {
            return $requestUri;
        }

        $origPathInfo = $_SERVER['ORIG_PATH_INFO'] ?? null;

        if (is_string($origPathInfo)) {
            return $origPathInfo;
        }

        return '/';
    }

    /**
     * Convert an ambiguous float timestamp that could be in nanoseconds, microseconds, milliseconds, or seconds to
     * nanoseconds. Return 0.0 for values in the more than 10 years ago.
     *
     * @throws Exception
     */
    private function convertAmbiguousTimestampToSeconds(float $timestamp, float $currentTimestamp): float
    {
        $tenYearsAgo = Timer::utcDateTimeFromFloatTimestamp($currentTimestamp)
            ->sub(new DateInterval('P10Y'));

        $cutoffTimestamp = (float) $tenYearsAgo
            ->setDate((int) $tenYearsAgo->format('Y'), 1, 1)
            ->format('U.u');

        if ($timestamp > $cutoffTimestamp * 1000000000.0) {
            return $timestamp / 1000000000;
        }

        if ($timestamp > $cutoffTimestamp * 1000000.0) {
            return $timestamp / 1000000;
        }

        if ($timestamp > $cutoffTimestamp * 1000.0) {
            return $timestamp / 1000.0;
        }

        if ($timestamp > $cutoffTimestamp) {
            return $timestamp;
        }

        return 0.0;
    }

    /** @throws Exception */
    private function tagRequestIfRequestQueueTimeHeaderExists(float $currentTimeInSeconds): void
    {
        $headers = FetchRequestHeaders::fromServerGlobal();

        foreach (['X-Queue-Start', 'X-Request-Start'] as $headerToCheck) {
            if (! array_key_exists($headerToCheck, $headers)) {
                continue;
            }

            $headerValue = $headers[$headerToCheck];

            if (strpos($headerValue, 't=') === 0) {
                $headerValue = substr($headerValue, 2);
            }

            $headerValueInSeconds = $this->convertAmbiguousTimestampToSeconds((float) $headerValue, $currentTimeInSeconds);

            if ($headerValueInSeconds === 0.0) {
                continue;
            }

            // Time tags should be in nanoseconds, so multiply seconds by 1e9 (1,000,000,000)
            $this->tag(
                Tag::TAG_QUEUE_TIME,
                ($this->timer->getStartAsMicrotime() - $headerValueInSeconds) * 1e9
            );
        }
    }

    public function stopIfRunning(?float $overrideTimestamp = null): void
    {
        if ($this->timer->getStop() !== null) {
            return;
        }

        $this->stop($overrideTimestamp);
    }

    public function stop(?float $overrideTimestamp = null, ?float $currentTime = null): void
    {
        $this->timer->stop($overrideTimestamp);

        $this->tag(Tag::TAG_MEMORY_DELTA, MemoryUsage::record()->usedDifferenceInMegabytes($this->startMemory));
        $this->tag(
            Tag::TAG_REQUEST_PATH,
            FormatUrlPathAndQuery::forUriReportingConfiguration(
                $this->uriReportingOption,
                $this->filteredParameters,
                $this->requestUriOverride ?? $this->determineRequestPathFromServerGlobal()
            )
        );

        $this->tagRequestIfRequestQueueTimeHeaderExists($currentTime ?? microtime(true));
    }

    /**
     * @throws SpanLimitReached
     * @throws Exception
     */
    public function startSpan(string $operation, ?float $overrideTimestamp = null, bool $leafSpan = false): Span
    {
        if ($this->spanCount >= self::MAX_COMPLETE_SPANS) {
            throw SpanLimitReached::forOperation($operation, self::MAX_COMPLETE_SPANS);
        }

        if ($this->currentCommand instanceof Span && $this->currentCommand->isLeaf()) {
            $this->leafNodeDepth++;

            return $this->currentCommand;
        }

        $this->spanCount++;

        $span = new Span($this->currentCommand, $operation, $this->id, $overrideTimestamp, $leafSpan);

        $this->currentCommand->appendChild($span);

        $this->currentCommand = $span;

        return $span;
    }

    public function appendChild(Command $span): void
    {
        $this->children[] = $span;
    }

    /**
     * Stop the currently "running" span.
     * You can still tag it if needed up until the request as a whole is finished.
     */
    public function stopSpan(?float $overrideTimestamp = null): void
    {
        $command = $this->currentCommand;
        if (! $command instanceof Span) {
            $this->stopIfRunning($overrideTimestamp);

            return;
        }

        if ($command->isLeaf() && $this->leafNodeDepth > 0) {
            $this->leafNodeDepth--;

            return;
        }

        $command->stop($overrideTimestamp);

        $this->currentCommand = $command->parent();
    }

    /**
     * Add a tag to the request as a whole
     *
     * @param mixed $value
     */
    public function tag(string $tagName, $value): void
    {
        $this->appendChild(new TagRequest($tagName, $value, $this->id));
    }

    /**
     * turn this object into a list of commands to send to the CoreAgent
     *
     * @return array<string, array<string, array<int, array<string, (string|array|bool|null)>>>>
     *
     * @todo document more of the command structures better:
     * @psalm-suppress InvalidReturnType
     * @psalm-return array{
     *      BatchCommand: array{
     *          commands: list<
     *              array{
     *                  StartRequest: array{
     *                      request_id: string,
     *                      timestamp: string|null,
     *                  }
     *              }|array{
     *                  FinishRequest: array{
     *                      request_id: string,
     *                      timestamp: string|null,
     *                  }
     *              }
     *          >
     *      }
     * }
     */
    public function jsonSerialize(): array
    {
        $commands   = [];
        $commands[] = [
            'StartRequest' => [
                'request_id' => $this->id->toString(),
                'timestamp' => $this->timer->getStart(),
            ],
        ];

        foreach ($this->children as $child) {
            foreach ($child->jsonSerialize() as $value) {
                $commands[] = $value;
            }
        }

        $commands[] = [
            'FinishRequest' => [
                'request_id' => $this->id->toString(),
                'timestamp' => $this->timer->getStop(),
            ],
        ];

        /** @psalm-suppress InvalidReturnStatement */
        return [
            'BatchCommand' => ['commands' => $commands],
        ];
    }

    public function collectedSpans(): int
    {
        return RecursivelyCountSpans::forCommands($this->children);
    }

    /**
     * You probably don't need this, it's used in testing.
     * Returns all events that have occurred in this Request.
     *
     * @internal
     * @deprecated
     *
     * @return Command[]|array<int, Command>
     *
     * @todo remove
     */
    public function getEvents(): array
    {
        return $this->children;
    }
}
