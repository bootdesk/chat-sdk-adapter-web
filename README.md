# bootdesk/chat-sdk-adapter-web

Generic web/REST adapter for the laravel-bootdesk multi-platform messaging framework.

## Install

```bash
composer require bootdesk/chat-sdk-adapter-web
```

## Configuration

```php
use BootDesk\ChatSDK\Web\WebAdapter;
use BootDesk\ChatSDK\Web\WebAdapterConfig;
use BootDesk\ChatSDK\Core\Contracts\BroadcastAdapter;
use Psr\Http\Message\ServerRequestInterface;

class MyAppWebConfig extends WebAdapterConfig
{
    public function getUser(ServerRequestInterface $request): ?array
    {
        return auth()->user()
            ? ['id' => (string) auth()->id(), 'name' => auth()->user()?->name]
            : null;
    }

    public function verifySignature(ServerRequestInterface $request): bool|string
    {
        $signature = $request->getHeaderLine('X-Signature');
        $payload = (string) $request->getBody();
        $expected = 'sha256=' . hash_hmac('sha256', $payload, config('app.webhook_secret'));
        return hash_equals($expected, $signature) ? true : 'Invalid signature';
    }
}

$adapter = new WebAdapter(
    userName: env('WEB_USER_NAME', 'Bot'),
    config: new MyAppWebConfig,
    broadcaster: $broadcaster,  // Optional: BroadcastAdapter instance
    asyncMode: true,             // Optional: Enable async broadcasting
);
```

**Laravel config** (`config/chat.php`):

```php
'web' => [
    'user_name' => env('BOT_USERNAME', 'Bot'),
    'config' => App\Chat\WebAdapterConfig::class,  // extends BootDesk\ChatSDK\Web\WebAdapterConfig
    'broadcaster' => fn () => app(BroadcastAdapter::class),
    'async_mode' => env('CHAT_WEB_ASYNC_MODE', false),
],
```

The `verifySignature()` method receives the PSR-7 request and must return `true` for valid, or an error message string for invalid. Called before user auth.

## Quick Example

```php
// Post a message in a web session
$adapter->postMessage('web:session-abc123', 'Hello from laravel-bootdesk!');

// Handle incoming webhook
// POST body: {"text": "Hi", "userId": "user-1", "sessionId": "session-abc123"}
```

## Thread ID Format

| Format                      | Description              |
| --------------------------- | ------------------------ |
| `web:{userId}:{conversationId}` | User-conversation threading |

## Webhook

Accepts POST requests with a JSON body:

```json
{
  "id": "optional-conversation-id",
  "messages": [
    {
      "role": "user",
      "id": "msg-1",
      "text": "Hello",
      "attachments": [
        {"url": "https://example.com/file.pdf", "name": "Document", "type": "file"}
      ]
    }
  ]
}
```

Authentication via `$getUser` closure — receives PSR-7 ServerRequestInterface, must return `['id' => string, 'name' => ?string]` or `null` for 401.

## Feature Matrix

| Feature            | Supported |
| ------------------ | --------- |
| Post messages      | ✓         |
| Edit messages      | ✓         |
| Delete messages    | ✓         |
| Reactions          | ✓         |
| Typing indicator   | ✓         |
| Fetch messages     | ✓         |
| Fetch thread info  | ✓         |
| Fetch channel info | ✗         |
| Get user           | ✗         |
| Open DM            | ✓         |
| Stream             | ✓         |
| Broadcasting       | ✓         |

## Broadcasting

WebAdapter supports real-time event broadcasting via `BroadcastAdapter`:

```php
use BootDesk\ChatSDK\Core\Contracts\BroadcastAdapter;

$broadcaster = app(BroadcastAdapter::class);

$adapter = new WebAdapter(
    userName: 'Bot',
    config: new MyAppWebConfig,
    broadcaster: $broadcaster,
    asyncMode: true,  // Broadcast immediately; sync mode accumulates events
);
```

Broadcast events: `MessagePostedEvent`, `MessageEditedEvent`, `MessageDeletedEvent`, `ReactionAddedEvent`, `ReactionRemovedEvent`, `TypingStartedEvent`, `StreamingChunkEvent`, `DirectMessageRequestedEvent`.

User-specific broadcasts (DMs, typing in DMs, streaming) use `broadcastToUser()` with `PrivateChannel`. Thread events use `broadcast()` with public `Channel`.

## Notes

Generic adapter for web/REST integrations. Responses returned via `createResponse()` as JSON: `{id, role: "assistant", text, events: []}`.

## Documentation

Full API documentation: https://bootdesk.github.io/chat-sdk

## License

MIT
