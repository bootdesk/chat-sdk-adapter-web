# bootdesk/chat-sdk-adapter-web

Generic web/REST adapter for the laravel-bootdesk multi-platform messaging framework.

## Install

```bash
composer require bootdesk/chat-sdk-adapter-web
```

## Configuration

| Variable | Description | Example |
|----------|-------------|---------|
| `user_name` | Display name for the bot (optional) | `Bot` |

```php
use BootDesk\ChatSDK\Web\WebAdapter;

$adapter = new WebAdapter([
    'user_name' => env('WEB_USER_NAME', 'Bot'),
]);
```

## Quick Example

```php
// Post a message in a web session
$adapter->postMessage('web:session-abc123', 'Hello from laravel-bootdesk!');

// Handle incoming webhook
// POST body: {"text": "Hi", "userId": "user-1", "sessionId": "session-abc123"}
```

## Thread ID Format

| Format | Description |
|--------|-------------|
| `web:{sessionId}` | Per-session threading |

## Webhook

Accepts POST requests with a JSON body containing `text`, `userId`, and `sessionId` fields.

## Feature Matrix

| Feature | Supported |
|---------|-----------|
| Post messages | ✓ |
| Edit messages | ✗ |
| Delete messages | ✗ |
| Reactions | ✗ |
| Typing indicator | ✗ |
| Fetch messages | ✗ |
| Fetch thread info | ✗ |
| Fetch channel info | ✗ |
| Get user | ✗ |
| Open DM | ✗ |
| Stream | ✓ |

## Notes

Generic adapter for web/REST integrations. Use as a foundation for building custom adapters.

## Documentationn
Full API documentation: https://bootdesk.github.io/chat-sdk

## License

MIT
