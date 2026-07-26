<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Support\HistoryEventPayloadContract;

final class HistoryEventPayloadContractTest extends TestCase
{
    public function testEveryHistoryEventTypeHasAPayloadContractEntry(): void
    {
        $registered = array_keys(HistoryEventPayloadContract::payloadKeys());
        $eventTypes = array_map(
            static fn (HistoryEventType $eventType): string => $eventType->value,
            HistoryEventType::cases(),
        );

        sort($registered);
        sort($eventTypes);

        $this->assertSame($eventTypes, $registered);
    }

    public function testPayloadContractRejectsUnknownProducerKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WorkflowCompleted history payload contains undocumented key(s): surprise');

        HistoryEventPayloadContract::assertKnownPayloadKeys(HistoryEventType::WorkflowCompleted, [
            'output' => 'ok',
            'surprise' => true,
        ]);
    }
}
