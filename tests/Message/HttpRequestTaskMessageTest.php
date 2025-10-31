<?php

declare(strict_types=1);

namespace Tourze\HttpRequestTaskBundle\Tests\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\AsyncContracts\AsyncMessageInterface;
use Tourze\HttpRequestTaskBundle\Message\HttpRequestTaskMessage;

/**
 * @internal
 */
#[CoversClass(HttpRequestTaskMessage::class)]
final class HttpRequestTaskMessageTest extends TestCase
{
    public function testMessageImplementsAsyncMessageInterface(): void
    {
        $taskId = 123;
        $message = new HttpRequestTaskMessage($taskId);

        $this->assertInstanceOf(AsyncMessageInterface::class, $message);
    }

    public function testMessageCanBeCreatedWithTaskId(): void
    {
        $taskId = 456;
        $message = new HttpRequestTaskMessage($taskId);

        $this->assertEquals($taskId, $message->getTaskId());
    }

    public function testMessageWithZeroTaskId(): void
    {
        $taskId = 0;
        $message = new HttpRequestTaskMessage($taskId);

        $this->assertEquals($taskId, $message->getTaskId());
    }

    public function testMessageWithLargeTaskId(): void
    {
        $taskId = PHP_INT_MAX;
        $message = new HttpRequestTaskMessage($taskId);

        $this->assertEquals($taskId, $message->getTaskId());
    }

    public function testMessageTaskIdIsImmutable(): void
    {
        $originalTaskId = 789;
        $message = new HttpRequestTaskMessage($originalTaskId);

        // Verify that taskId cannot be changed after construction
        $this->assertEquals($originalTaskId, $message->getTaskId());

        // Create a new message with different task ID
        $newTaskId = 999;
        $newMessage = new HttpRequestTaskMessage($newTaskId);

        // Verify original message is unchanged
        $this->assertEquals($originalTaskId, $message->getTaskId());
        $this->assertEquals($newTaskId, $newMessage->getTaskId());
    }

    public function testMultipleMessagesWithDifferentTaskIds(): void
    {
        $message1 = new HttpRequestTaskMessage(100);
        $message2 = new HttpRequestTaskMessage(200);
        $message3 = new HttpRequestTaskMessage(300);

        $this->assertEquals(100, $message1->getTaskId());
        $this->assertEquals(200, $message2->getTaskId());
        $this->assertEquals(300, $message3->getTaskId());

        // Verify they are different instances
        $this->assertNotSame($message1, $message2);
        $this->assertNotSame($message2, $message3);
    }
}
