# adapter-web

Web adapter for bootdesk/chat-sdk-core — browser chat UI via JSON request/response. Namespace: `BootDesk\ChatSDK\Web`

## files
- `WebAdapter` — implements `Adapter` for JSON-based browser chat
- `WebFormatConverter` — plain text ↔ CommonMark AST

## registration
`src/register.php` registers `'web' => WebAdapter::class` via `AdapterRegistry`

## constructor
```php
new WebAdapter(
    string $userName,
    Closure $getUser,              // fn(ServerRequestInterface): ?array{id: string, name?: string}
    ?Closure $threadIdFor = null,  // fn(string $userId, string $conversationId): string
    ?Psr17Factory $psrFactory = null,
);
```

## thread ID format
`web:{userId}:{conversationId}` — or custom via `$threadIdFor` closure

## webhook flow
1. `verifyWebhook` — validates JSON body has `messages` array, calls `$getUser` for auth, extracts last user message
2. `parseWebhook` — returns Message from last user message in conversation array

## unique behavior
- Request body format: `{id?: string, messages: [{role: "user"|"assistant", id?: string, text: string}]}`
- `postMessage` buffers reply text (doesn't send — waits for `createResponse`)
- `createResponse` returns JSON: `{id, role: "assistant", text}` with buffered reply
- No editMessage, deleteMessage, addReaction, removeReaction (throws AdapterException)
- No fetchMessages (returns empty)
- No fetchChannelInfo (returns null)
- Streaming: also buffers to reply text
- No HTTP client dependency (pure JSON in/out)

## config (laravel)
```php
'web' => [
    'user_name' => env('BOT_USERNAME', 'Bot'),
],
```
The `getUser` closure must be bound via service provider or middleware.
