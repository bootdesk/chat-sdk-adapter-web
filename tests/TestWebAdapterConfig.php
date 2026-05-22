<?php

namespace BootDesk\ChatSDK\Web\Tests;

use BootDesk\ChatSDK\Web\WebAdapterConfig;
use Psr\Http\Message\ServerRequestInterface;

class TestWebAdapterConfig extends WebAdapterConfig
{
    public function getUser(ServerRequestInterface $request): ?array
    {
        return ['id' => 'u-test', 'name' => 'Test User'];
    }

    public function threadIdFor(string $userId, string $conversationId): string
    {
        return "test:{$userId}:{$conversationId}";
    }
}
