<?php

namespace BootDesk\ChatSDK\Web;

use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\ChannelInfo;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\FileUploadConverter;
use BootDesk\ChatSDK\Core\Contracts\FormatConverter;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\FetchOptions;
use BootDesk\ChatSDK\Core\FetchResult;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
use BootDesk\ChatSDK\Core\Support\NullFileUploadConverter;
use BootDesk\ChatSDK\Core\ThreadInfo;
use BootDesk\ChatSDK\Core\UserInfo;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class WebAdapter implements Adapter
{
    protected ?string $botUserId = null;

    protected WebFormatConverter $formatConverter;

    protected ?string $resolvedUserId = null;

    protected ?string $resolvedUserName = null;

    protected ?string $conversationId = null;

    protected string $bufferedReply = '';

    protected FileUploadConverter $fileUploadConverter;

    public function __construct(
        protected readonly string $userName,
        protected readonly \Closure $getUser,
        protected readonly ?\Closure $threadIdFor = null,
        protected readonly ?Psr17Factory $psrFactory = null,
        ?FileUploadConverter $fileUploadConverter = null,
    ) {
        $this->formatConverter = new WebFormatConverter;
        $this->fileUploadConverter = $fileUploadConverter ?? new NullFileUploadConverter;
    }

    public function getName(): string
    {
        return 'web';
    }

    public function getBotUserId(): ?string
    {
        return $this->botUserId;
    }

    public function verifyWebhook(ServerRequestInterface $request): ?ResponseInterface
    {
        $this->resetState();

        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return $this->jsonError(400, 'Invalid JSON body');
        }

        if (! isset($payload['messages']) || ! is_array($payload['messages']) || $payload['messages'] === []) {
            return $this->jsonError(400, 'Request body must include a messages array');
        }

        $user = ($this->getUser)($request);
        if ($user === null) {
            return $this->jsonError(401, 'Unauthorized');
        }

        if (str_contains($user['id'] ?? '', ':')) {
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

        return new Message(
            id: $msgId,
            threadId: $threadId,
            author: new Author(
                id: $this->resolvedUserId ?? 'unknown',
                name: $this->resolvedUserName ?? 'unknown',
                isBot: false,
            ),
            text: $text,
            isDM: true,
            raw: $body,
        );
    }

    public function encodeThreadId(mixed $platformData): string
    {
        $userId = $platformData['userId'] ?? '';
        $conversationId = $platformData['conversationId'] ?? '';

        if ($this->threadIdFor instanceof \Closure) {
            return ($this->threadIdFor)($userId, $conversationId);
        }

        return "web:{$userId}:{$conversationId}";
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

        foreach ($message->attachments as $att) {
            $name = $att->name ?? 'Attachment';
            $text .= "\n".($att->url !== null ? "{$name}: {$att->url}" : $name);
        }

        if ($text !== '') {
            $this->bufferedReply .= $text;
        }

        $id = $this->generateId();

        return new SentMessage(
            id: $id,
            threadId: $threadId,
        );
    }

    public function editMessage(string $threadId, string $messageId, PostableMessage $message): SentMessage
    {
        throw new AdapterException('WebAdapter.editMessage is not supported — every assistant turn is a fresh response.');
    }

    public function deleteMessage(string $threadId, string $messageId): void
    {
        throw new AdapterException('WebAdapter.deleteMessage is not supported.');
    }

    public function addReaction(string $threadId, string $messageId, string $emoji): void
    {
        throw new AdapterException('WebAdapter.addReaction is not supported.');
    }

    public function removeReaction(string $threadId, string $messageId, string $emoji): void
    {
        throw new AdapterException('WebAdapter.removeReaction is not supported.');
    }

    public function startTyping(string $threadId): void
    {
        // No-op: web clients derive streaming status from the response itself
    }

    public function fetchMessages(string $threadId, ?FetchOptions $options = null): FetchResult
    {
        return new FetchResult(messages: []);
    }

    public function fetchThread(string $threadId): ThreadInfo
    {
        return new ThreadInfo(
            id: $threadId,
            channelId: $this->channelIdFromThreadId($threadId),
            messageCount: 0,
        );
    }

    public function fetchChannelInfo(string $channelId): ?ChannelInfo
    {
        return null;
    }

    public function getUser(string $userId): ?UserInfo
    {
        return null;
    }

    public function openDM(string $userId): ?string
    {
        return $this->encodeThreadId([
            'userId' => $userId,
            'conversationId' => $this->generateId(),
        ]);
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
        $fullText = '';
        foreach ($textStream as $chunk) {
            $fullText .= $chunk;
        }

        if ($fullText === '') {
            return null;
        }

        $this->bufferedReply .= $fullText;

        return new SentMessage(
            id: $this->generateId(),
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

        $payload = [
            'id' => $this->conversationId ?? $this->generateId(),
            'role' => 'assistant',
            'text' => $this->bufferedReply,
        ];

        return $factory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode($payload)));
    }

    public function hasResolvedUser(): bool
    {
        return $this->resolvedUserId !== null;
    }

    protected function resetState(): void
    {
        $this->resolvedUserId = null;
        $this->resolvedUserName = null;
        $this->conversationId = null;
        $this->bufferedReply = '';
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
}
