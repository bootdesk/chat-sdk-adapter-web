<?php

namespace BootDesk\ChatSDK\Web;

use BootDesk\ChatSDK\Core\Attachment;
use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\Broadcasting\BroadcastEvent;
use BootDesk\ChatSDK\Core\Broadcasting\DirectMessageRequestedEvent;
use BootDesk\ChatSDK\Core\Broadcasting\MessageDeletedEvent;
use BootDesk\ChatSDK\Core\Broadcasting\MessageEditedEvent;
use BootDesk\ChatSDK\Core\Broadcasting\MessagePostedEvent;
use BootDesk\ChatSDK\Core\Broadcasting\ReactionAddedEvent;
use BootDesk\ChatSDK\Core\Broadcasting\ReactionRemovedEvent;
use BootDesk\ChatSDK\Core\Broadcasting\StreamingChunkEvent;
use BootDesk\ChatSDK\Core\Broadcasting\TypingStartedEvent;
use BootDesk\ChatSDK\Core\ChannelInfo;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\BroadcastAdapter;
use BootDesk\ChatSDK\Core\Contracts\FileUploadConverter;
use BootDesk\ChatSDK\Core\Contracts\FormatConverter;
use BootDesk\ChatSDK\Core\Contracts\HandlesActions;
use BootDesk\ChatSDK\Core\Contracts\HandlesReactions;
use BootDesk\ChatSDK\Core\Contracts\HandlesSlashCommands;
use BootDesk\ChatSDK\Core\Contracts\HasAuthorInfo;
use BootDesk\ChatSDK\Core\Contracts\HasDynamicSyncPreference;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\FetchOptions;
use BootDesk\ChatSDK\Core\FetchResult;
use BootDesk\ChatSDK\Core\LocalizationType;
use BootDesk\ChatSDK\Core\LocalizationValue;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
use BootDesk\ChatSDK\Core\Support\EmojiResolver;
use BootDesk\ChatSDK\Core\Support\NullFileUploadConverter;
use BootDesk\ChatSDK\Core\ThreadInfo;
use BootDesk\ChatSDK\Core\UserInfo;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class WebAdapter implements Adapter, HandlesActions, HandlesReactions, HandlesSlashCommands, HasAuthorInfo, HasDynamicSyncPreference
{
    protected ?string $botUserId = null;

    protected WebFormatConverter $formatConverter;

    protected ?string $resolvedUserId = null;

    protected ?string $resolvedUserName = null;

    protected ?string $conversationId = null;

    protected string $bufferedReply = '';

    protected array $bufferedAttachments = [];

    protected FileUploadConverter $fileUploadConverter;

    protected ?BroadcastAdapter $broadcaster = null;

    protected bool $asyncMode = false;

    protected array $accumulatedEvents = [];

    protected string $currentUserId = '';

    public function __construct(
        protected readonly string $userName,
        WebAdapterConfig|string $config = new WebAdapterConfig,
        protected readonly ?Psr17Factory $psrFactory = null,
        ?FileUploadConverter $fileUploadConverter = null,
        ?BroadcastAdapter $broadcaster = null,
        bool $asyncMode = false,
    ) {
        if (is_string($config)) {
            if (! class_exists($config)) {
                throw new AdapterException("WebAdapter config class '{$config}' does not exist");
            }
            $config = new $config;
        }
        $this->config = $config;
        $this->formatConverter = new WebFormatConverter;
        $this->fileUploadConverter = $fileUploadConverter ?? new NullFileUploadConverter;
        $this->broadcaster = $broadcaster;
        $this->asyncMode = $asyncMode;
    }

    protected readonly WebAdapterConfig $config;

    public function getName(): string
    {
        return 'web';
    }

    public function getBotUserId(): ?string
    {
        return $this->botUserId;
    }

    public function requiresSyncResponse(): bool
    {
        return ! $this->asyncMode;
    }

    public function verifyWebhook(ServerRequestInterface $request): ?ResponseInterface
    {
        $this->resetState();

        // Verify signature
        $signatureResult = $this->config->verifySignature($request);
        if ($signatureResult !== true) {
            return $this->jsonError(401, (string) $signatureResult);
        }

        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return $this->jsonError(400, 'Invalid JSON body');
        }

        // Action payloads skip message validation
        if (isset($payload['action'])) {
            return $this->resolveUserAndConversation($payload, $request);
        }

        // Reaction payloads skip message validation
        if (isset($payload['reaction'])) {
            return $this->resolveUserAndConversation($payload, $request);
        }

        $validationError = $this->validatePayload($payload);
        if ($validationError instanceof ResponseInterface) {
            return $validationError;
        }

        if (! isset($payload['messages']) || ! is_array($payload['messages']) || $payload['messages'] === []) {
            return $this->jsonError(400, 'Request body must include a messages array');
        }

        $user = $this->config->getUser($request);
        if ($user === null) {
            return $this->jsonError(401, 'Unauthorized');
        }

        if (str_contains($user['id'], ':')) {
            return $this->jsonError(400, 'Invalid user id');
        }

        $conversationId = $payload['id'] ?? $this->generateId();
        if (str_contains($conversationId, ':')) {
            return $this->jsonError(400, 'Invalid conversation id');
        }

        $lastUserMsg = $this->findLastUserMessage($payload['messages']);
        if ($lastUserMsg === null) {
            return $this->jsonError(400, 'No user message found in messages array');
        }

        $this->resolvedUserId = $user['id'];
        $this->resolvedUserName = $user['name'] ?? $user['id'];
        $this->conversationId = $conversationId;
        $this->currentUserId = $user['id'];

        return null;
    }

    protected function validatePayload(array $payload): ?ResponseInterface
    {
        $messages = $payload['messages'] ?? [];

        foreach ($messages as $i => $msg) {
            if (! isset($msg['role']) || ! in_array($msg['role'], ['user', 'assistant'], true)) {
                return $this->jsonError(400, "Message at index {$i} must have role 'user' or 'assistant'");
            }

            if (! isset($msg['text']) || ! is_string($msg['text'])) {
                return $this->jsonError(400, "Message at index {$i} must have text content");
            }

            $textLength = mb_strlen($msg['text']);
            if ($textLength > 10000) {
                return $this->jsonError(400, "Message at index {$i} exceeds maximum length of 10000 characters");
            }

            if (isset($msg['attachments']) && is_array($msg['attachments'])) {
                foreach ($msg['attachments'] as $j => $att) {
                    if (! isset($att['url']) || ! is_string($att['url'])) {
                        return $this->jsonError(400, "Attachment at index {$i}.{$j} must have a url");
                    }

                    if (mb_strlen($att['url']) > 2048) {
                        return $this->jsonError(400, "Attachment url at index {$i}.{$j} exceeds maximum length of 2048 characters");
                    }
                }
            }
        }

        return null;
    }

    public function parseWebhook(ServerRequestInterface $request): Message
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        $messages = $payload['messages'] ?? [];
        $lastUserMsg = $this->findLastUserMessage($messages);

        $text = $lastUserMsg['text'] ?? '';
        $msgId = $lastUserMsg['id'] ?? $this->generateId();

        $threadId = $this->encodeThreadId([
            'userId' => $this->resolvedUserId ?? 'unknown',
            'conversationId' => $this->conversationId ?? $this->generateId(),
        ]);

        // Parse attachments from payload
        $attachments = [];
        if (isset($lastUserMsg['attachments']) && is_array($lastUserMsg['attachments'])) {
            foreach ($lastUserMsg['attachments'] as $att) {
                if (is_array($att) && isset($att['url'])) {
                    $attachments[] = new Attachment(
                        type: $att['type'] ?? 'url',
                        url: $att['url'],
                        name: $att['name'] ?? null,
                        mimeType: $att['mime_type'] ?? $att['mimeType'] ?? null,
                        size: $att['size'] ?? null,
                    );
                }
            }
        }

        $author = new Author(
            id: $this->resolvedUserId ?? 'unknown',
            name: $this->resolvedUserName ?? 'unknown',
            isBot: false,
        );

        $localizations = $this->extractLocalizationHeaders($request);
        if ($localizations !== []) {
            $author = $author->withLocalizations(...$localizations);
        }

        return new Message(
            id: $msgId,
            threadId: $threadId,
            author: $author,
            text: $text,
            attachments: $attachments,
            isDM: true,
            raw: $body,
        );
    }

    public function encodeThreadId(mixed $platformData): string
    {
        $userId = $platformData['userId'] ?? '';
        $conversationId = $platformData['conversationId'] ?? '';

        return $this->config->threadIdFor($userId, $conversationId);
    }

    public function decodeThreadId(string $threadId): mixed
    {
        $parts = explode(':', $threadId, 3);

        if (count($parts) < 3 || $parts[0] !== 'web') {
            throw new AdapterException("Invalid web thread id: {$threadId}");
        }

        return [
            'userId' => $parts[1],
            'conversationId' => $parts[2],
        ];
    }

    public function channelIdFromThreadId(string $threadId): string
    {
        return $threadId;
    }

    public function postMessage(string $threadId, PostableMessage $message): SentMessage
    {
        // Convert files to attachments via the registered converter
        if ($message->files !== []) {
            $converted = [];
            foreach ($message->files as $file) {
                $converted[] = $this->fileUploadConverter->upload($file, $this);
            }
            $message = new PostableMessage(
                content: $message->content,
                replyToMessageId: $message->replyToMessageId,
                attachments: array_merge($message->attachments, $converted),
            );
        }

        $text = $message->isCard()
            ? $message->content->getFallbackText()
            : (string) $message->content;

        $text = EmojiResolver::convertPlaceholders($text, 'gchat');

        // Store attachments for JSON response
        $this->bufferedAttachments = $message->attachments;

        if ($text !== '') {
            $this->bufferedReply .= $text;
        }

        $id = $this->generateId();

        $sentMessage = new SentMessage(
            id: $id,
            threadId: $threadId,
        );

        $card = $message->isCard() ? $message->content : null;

        $this->broadcastEvent(new MessagePostedEvent(
            threadId: $threadId,
            messageId: $sentMessage->id,
            text: $text,
            author: [
                'id' => $this->userName,
                'name' => $this->userName,
                'isBot' => true,
            ],
            card: $card,
            attachments: $message->attachments,
        ));

        return $sentMessage;
    }

    public function editMessage(string $threadId, string $messageId, PostableMessage $message): SentMessage
    {
        $text = $message->isCard()
            ? $message->content->getFallbackText()
            : (string) $message->content;

        $text = EmojiResolver::convertPlaceholders($text, 'gchat');

        $card = $message->isCard() ? $message->content : null;

        $this->broadcastEvent(new MessageEditedEvent(
            threadId: $threadId,
            messageId: $messageId,
            newText: $text,
            card: $card,
        ));

        return new SentMessage(
            id: $messageId,
            threadId: $threadId,
        );
    }

    public function deleteMessage(string $threadId, string $messageId): void
    {
        $this->broadcastEvent(new MessageDeletedEvent(
            threadId: $threadId,
            messageId: $messageId,
        ));
    }

    public function addReaction(string $threadId, string $messageId, string $emoji): void
    {
        $this->broadcastEvent(new ReactionAddedEvent(
            threadId: $threadId,
            messageId: $messageId,
            emoji: EmojiResolver::default()->toGChat($emoji),
            user: ['id' => $this->userName],
        ));
    }

    public function removeReaction(string $threadId, string $messageId, string $emoji): void
    {
        $this->broadcastEvent(new ReactionRemovedEvent(
            threadId: $threadId,
            messageId: $messageId,
            emoji: EmojiResolver::default()->toGChat($emoji),
            user: ['id' => $this->userName],
        ));
    }

    public function startTyping(string $threadId): void
    {
        $this->broadcastEvent(new TypingStartedEvent(
            threadId: $threadId,
            userId: $this->userName,
        ));
    }

    public function fetchMessages(string $threadId, ?FetchOptions $options = null): FetchResult
    {
        return $this->config->fetchMessages($threadId, $options);
    }

    public function fetchThread(string $threadId): ThreadInfo
    {
        $info = $this->config->fetchThread($threadId);

        $this->validateThreadIdFormat($info->id, 'ThreadInfo::id');
        $this->validateThreadIdFormat($info->channelId, 'ThreadInfo::channelId');

        return $info;
    }

    public function fetchChannelInfo(string $channelId): ?ChannelInfo
    {
        $info = $this->config->fetchChannelInfo($channelId);

        if ($info instanceof ChannelInfo) {
            $this->validateThreadIdFormat($info->id, 'ChannelInfo::id');
        }

        return $info;
    }

    public function getUser(string $userId): ?UserInfo
    {
        return null;
    }

    public function getAuthorInfo(Author $author): Author
    {
        return $this->config->getAuthorInfo($author);
    }

    public function openDM(string $userId): ?string
    {
        $threadId = $this->encodeThreadId([
            'userId' => $userId,
            'conversationId' => $this->generateId(),
        ]);

        $this->broadcastEvent(new DirectMessageRequestedEvent(
            threadId: $threadId,
            userId: $userId,
        ), $userId);

        return $threadId;
    }

    public function getFormatConverter(): ?FormatConverter
    {
        return $this->formatConverter;
    }

    public function initialize(Chat $chat): void
    {
        $this->botUserId = $this->userName;
    }

    public function disconnect(): void
    {
        // No persistent connection
    }

    public function stream(string $threadId, iterable $textStream, array $options = []): ?SentMessage
    {
        $messageId = $this->generateId();
        $fullText = '';
        foreach ($textStream as $chunk) {
            $fullText .= $chunk;

            $this->broadcastEvent(new StreamingChunkEvent(
                threadId: $threadId,
                messageId: $messageId,
                chunk: $chunk,
                isFinal: false,
            ), $this->currentUserId);
        }

        $this->broadcastEvent(new StreamingChunkEvent(
            threadId: $threadId,
            messageId: $messageId,
            chunk: '',
            isFinal: true,
        ), $this->currentUserId);

        if ($fullText === '') {
            return null;
        }

        $this->bufferedReply .= $fullText;

        return new SentMessage(
            id: $messageId,
            threadId: $threadId,
        );
    }

    public function getBufferedReply(): string
    {
        return $this->bufferedReply;
    }

    public function createResponse(): ResponseInterface
    {
        $factory = $this->psrFactory ?? new Psr17Factory;

        // Convert Attachment objects to arrays for JSON response
        $attachments = array_map(fn ($att): array => [
            'type' => $att->type,
            'url' => $att->url,
            'name' => $att->name,
            'mime_type' => $att->mimeType,
            'size' => $att->size,
        ], $this->bufferedAttachments);

        $payload = [
            'id' => $this->conversationId ?? $this->generateId(),
            'role' => 'assistant',
            'text' => $this->bufferedReply,
            'attachments' => $attachments,
            'events' => $this->accumulatedEvents,
        ];

        return $factory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode($payload)));
    }

    public function getAccumulatedEvents(): array
    {
        return $this->accumulatedEvents;
    }

    public function hasResolvedUser(): bool
    {
        return $this->resolvedUserId !== null;
    }

    protected function broadcastEvent(BroadcastEvent $event, ?string $targetUserId = null): void
    {
        if (! $this->broadcaster instanceof BroadcastAdapter) {
            return;
        }

        if ($this->asyncMode) {
            if ($targetUserId !== null) {
                $this->broadcaster->broadcastToUser($event->threadId, $targetUserId, $event);
            } else {
                $this->broadcaster->broadcast($event->threadId, $event);
            }
        } else {
            $this->accumulatedEvents[] = $event->toArray();
        }
    }

    protected function resetState(): void
    {
        $this->resolvedUserId = null;
        $this->resolvedUserName = null;
        $this->conversationId = null;
        $this->bufferedReply = '';
        $this->bufferedAttachments = [];
        $this->accumulatedEvents = [];
        $this->currentUserId = '';
    }

    protected function extractLocalizationHeaders(ServerRequestInterface $request): array
    {
        $values = [];

        $locale = $request->getHeaderLine('X-Locale');
        if ($locale === '') {
            $acceptLanguage = $request->getHeaderLine('Accept-Language');
            if ($acceptLanguage !== '') {
                $locale = explode(',', $acceptLanguage)[0];
                $locale = explode(';', $locale)[0];
            }
        }
        if ($locale !== '') {
            $values[] = new LocalizationValue(LocalizationType::Locale, $locale);
        }

        $timezone = $request->getHeaderLine('X-Timezone');
        if ($timezone !== '') {
            $values[] = new LocalizationValue(LocalizationType::Timezone, $timezone);
        }

        $language = $request->getHeaderLine('X-Language');
        if ($language !== '') {
            $values[] = new LocalizationValue(LocalizationType::Language, $language);
        }

        return $values;
    }

    protected function findLastUserMessage(array $messages): ?array
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                return $messages[$i];
            }
        }

        return null;
    }

    protected function resolveUserAndConversation(array $payload, ServerRequestInterface $request): ?ResponseInterface
    {
        $user = $this->config->getUser($request);
        if ($user === null) {
            return $this->jsonError(401, 'Unauthorized');
        }

        if (str_contains($user['id'], ':')) {
            return $this->jsonError(400, 'Invalid user id');
        }

        $conversationId = $payload['id'] ?? $this->generateId();
        if (str_contains($conversationId, ':')) {
            return $this->jsonError(400, 'Invalid conversation id');
        }

        $this->resolvedUserId = $user['id'];
        $this->resolvedUserName = $user['name'] ?? $user['id'];
        $this->conversationId = $conversationId;
        $this->currentUserId = $user['id'];

        return null;
    }

    public function parseAction(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload) || ! isset($payload['action'])) {
            return null;
        }

        $action = $payload['action'];

        if (! isset($action['actionId']) || ! is_string($action['actionId'])) {
            return null;
        }

        $author = new Author(id: $this->resolvedUserId ?? '', name: $this->resolvedUserName ?? '');
        $localizations = $this->extractLocalizationHeaders($request);
        if ($localizations !== []) {
            $author = $author->withLocalizations(...$localizations);
        }

        return [
            'author' => $author,
            'actionId' => $action['actionId'],
            'value' => $action['value'] ?? null,
            'threadId' => $this->encodeThreadId([
                'userId' => $this->resolvedUserId ?? 'unknown',
                'conversationId' => $this->conversationId ?? $this->generateId(),
            ]),
            'messageId' => (string) ($action['messageId'] ?? ''),
            'userId' => $this->resolvedUserId ?? '',
            'isBot' => false,
            'isMe' => false,
            'triggerId' => null,
            'raw' => $body,
            'callbackQueryId' => null,
            'originId' => null,
        ];
    }

    public function parseReaction(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload) || ! isset($payload['reaction'])) {
            return null;
        }

        $reaction = $payload['reaction'];

        if (! isset($reaction['messageId'], $reaction['emoji'])) {
            return null;
        }

        $threadId = $this->encodeThreadId([
            'userId' => $this->resolvedUserId ?? 'unknown',
            'conversationId' => $this->conversationId ?? $this->generateId(),
        ]);

        $author = new Author(
            id: $this->resolvedUserId ?? '',
            name: $this->resolvedUserName ?? '',
        );

        $localizations = $this->extractLocalizationHeaders($request);
        if ($localizations !== []) {
            $author = $author->withLocalizations(...$localizations);
        }

        return [
            'author' => $author,
            'emoji' => EmojiResolver::default()->fromGChat($reaction['emoji']),
            'rawEmoji' => $reaction['emoji'],
            'added' => $reaction['added'] ?? true,
            'threadId' => $threadId,
            'messageId' => $reaction['messageId'],
            'userId' => $this->resolvedUserId ?? '',
            'raw' => $payload,
            'originId' => null,
        ];
    }

    public function acknowledgeAction(?string $callbackQueryId): ?ResponseInterface
    {
        return null;
    }

    public function parseSlashCommand(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload) || ! isset($payload['messages'])) {
            return null;
        }

        $lastUserMsg = $this->findLastUserMessage($payload['messages']);
        if ($lastUserMsg === null) {
            return null;
        }

        $text = $lastUserMsg['text'] ?? '';
        if ($text === '' || $text[0] !== '/') {
            return null;
        }

        $parts = explode(' ', $text, 2);
        $command = $parts[0];
        $args = $parts[1] ?? '';

        $author = new Author(id: $this->resolvedUserId ?? '', name: $this->resolvedUserName ?? '');
        $localizations = $this->extractLocalizationHeaders($request);
        if ($localizations !== []) {
            $author = $author->withLocalizations(...$localizations);
        }

        return [
            'author' => $author,
            'command' => $command,
            'text' => $args,
            'userId' => $this->resolvedUserId ?? '',
            'isBot' => false,
            'isMe' => false,
            'channelId' => $this->encodeThreadId([
                'userId' => $this->resolvedUserId ?? 'unknown',
                'conversationId' => $this->conversationId ?? $this->generateId(),
            ]),
            'triggerId' => null,
            'raw' => $body,
        ];
    }

    protected function jsonError(int $status, string $message): ResponseInterface
    {
        $factory = $this->psrFactory ?? new Psr17Factory;

        return $factory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode(['error' => $message])));
    }

    protected function generateId(): string
    {
        return 'web-'.bin2hex(random_bytes(8));
    }

    protected function validateThreadIdFormat(string $value, string $label): void
    {
        $parts = explode(':', $value, 3);

        if (count($parts) < 3 || $parts[0] !== 'web') {
            throw new AdapterException(
                "{$label} must be in the format 'web:{userId}:{conversationId}', got '{$value}'",
            );
        }
    }
}
