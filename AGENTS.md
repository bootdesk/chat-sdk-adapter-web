# adapter-web

Web adapter for bootdesk/chat-sdk-core — browser chat UI via JSON request/response. Namespace: `BootDesk\ChatSDK\Web`

## files

- `WebAdapter` — implements `Adapter` for JSON-based browser chat
- `WebAdapterConfig` — overridable config class (extend to customize)
- `WebFormatConverter` — plain text ↔ CommonMark AST

## registration

`src/register.php` registers `'web' => WebAdapter::class` via `AdapterRegistry`

## concurrency

WebAdapter implements `HasDynamicSyncPreference` (not static markers like `RequiresSyncResponse`). The `asyncMode` constructor param determines behavior:

- `asyncMode: false` (default): `requiresSyncResponse()` returns `true` — messages processed inline
- `asyncMode: true`: `requiresSyncResponse()` returns `false` — messages deferred to configured concurrency strategy

## constructor

```php
new WebAdapter(
    string $userName,
    WebAdapterConfig|string $config = new WebAdapterConfig,  // instance or class name string
    ?Psr17Factory $psrFactory = null,
    ?FileUploadConverter $fileUploadConverter = null,
    ?BroadcastAdapter $broadcaster = null,            // Event broadcaster for real-time updates
    bool $asyncMode = false,                          // true=immediate broadcast + async concurrency, false=accumulate + sync inline
);
```

If `$config` is a string, it must be a class name extending `WebAdapterConfig`. Throws `AdapterException` if class doesn't exist.

**Important**: When created via Laravel's container (e.g., via `ChatFactory`), `$broadcaster` and `$psrFactory` are auto-resolved from the container. `$broadcaster` resolves to `LaravelBroadcastAdapter` if `BroadcastServiceProvider` is registered. In sync mode (`asyncMode=false`), events are accumulated into response `events` array but NOT broadcasted live. In async mode, events are broadcasted immediately.

## WebAdapterConfig

Extend this class and override only the methods you need:

```php
class MyAppConfig extends WebAdapterConfig
{
    public function getUser(ServerRequestInterface $request): ?array
    {
        // Return ['id' => string, 'name' => ?string] or null
    }

    public function threadIdFor(string $userId, string $conversationId): string
    {
        // Default: "web:{$userId}:{$conversationId}"
    }

    public function verifySignature(ServerRequestInterface $request): bool|string
    {
        // Return true if valid, or error message string if invalid
    }
}
```

## webhook flow

1. `verifySignature` — config method to verify request signature/HMAC (401 if fails)
2. `verifyWebhook` — validates payload. Three payload formats accepted:
   - `messages`: validates messages array, calls `config->getUser()`, extracts last user message
   - `action`: skips message validation, resolves user + conversation
   - `reaction`: skips message validation, resolves user + conversation
3. `parseWebhook` — returns Message from last user message (for `messages` payloads)
4. `parseAction` — returns action data array (for `action` payloads)
5. `parseReaction` — returns reaction data array (for `reaction` payloads)

## incoming reaction format

```json
{
  "id": "conv-abc",
  "reaction": { "messageId": "msg-123", "emoji": "👍", "added": true }
}
```

## thread ID format

`web:{userId}:{conversationId}` — or custom via `threadIdFor()` override

## unique behavior

- Request body format: `{id?: string, messages: [{role: "user"|"assistant", id?: string, text: string, attachments?: [{url, name?, type?}]}]}`
- `postMessage` buffers reply text and attachments separately (doesn't send — waits for `createResponse`)
- `createResponse` returns JSON: `{id, role: "assistant", text, attachments: [{type, url, name, mime_type, size}], events: []}`
- Attachments from input parsed as `Attachment` objects with URL, name, type, mimeType
- Attachments in output included only in JSON response array (not appended to text)
- `editMessage`, `deleteMessage`, `addReaction`, `removeReaction` broadcast events via `BroadcastAdapter` (if provided)
- `fetchMessages` returns empty `FetchResult`
- `fetchChannelInfo` returns null
- `startTyping` broadcasts thread-wide via `BroadcastAdapter::broadcast()` (same channel as `postMessage`)
- `stream` broadcasts chunks to `currentUserId`
- `openDM` broadcasts `DirectMessageRequestedEvent` to target user
- No HTTP client dependency (pure JSON in/out)

## broadcasting

When `BroadcastAdapter` provided (auto-resolved via container):

- **sync mode** (`asyncMode=false`, default): events accumulated in `getAccumulatedEvents()`, included in `createResponse` JSON. NOT broadcasted live — frontend processes them from webhook response.
- **async mode** (`asyncMode=true`): events broadcast immediately via `broadcast()` or `broadcastToUser()`.
- User-targeted: `DirectMessageRequestedEvent`, `TypingStartedEvent` (in DMs), `StreamingChunkEvent`
- Thread-wide: `MessagePostedEvent`, `MessageEditedEvent`, `MessageDeletedEvent`, reaction events

## config (laravel)

```php
'web' => [
    'user_name' => env('BOT_USERNAME', 'Bot'),
    'config' => App\Chat\WebAdapterConfig::class,  // extends BootDesk\ChatSDK\Web\WebAdapterConfig
    'broadcaster' => fn () => app(\BootDesk\ChatSDK\Core\Contracts\BroadcastAdapter::class),
    'async_mode' => false,
],
```
