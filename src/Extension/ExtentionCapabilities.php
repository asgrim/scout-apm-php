<?php

declare(strict_types=1);

namespace Scoutapm\Extension;

interface ExtentionCapabilities
{
    /** @return RecordedCall[]|array<int, RecordedCall> */
    public function getCalls(): array;

    public function clearRecordedCalls(): void;

    public function version(): ?Version;
}
