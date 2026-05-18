<?php

namespace BootDesk\ChatSDK\Web\Tests;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Web\WebAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class WebAdapterTest extends TestCase
{
    private WebAdapter $adapter;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory;

        $this->adapter = new WebAdapter(
            userName: 'testbot',
            getUser: fn () => ['id' => 'u-test', 'name' => 'Test User'],
            psrFactory: $this->factory,
        );
    }

    // --- Construction ---

    public function test_get_name(): void
    {
        $this->assertSame('web', $this->adapter->getName());
    }

    public function test_initialize_sets_bot_user_id(): void
    {
        $chat = $this->createMock(Chat::class);
        $this->adapter->initialize($chat);
        $this->assertSame('testbot', $this->adapter->getBotUserId());
    }

    // --- Thread IDs ---

    public function test_thread_id_encode(): void
    {
        $id = $this->adapter->encodeThreadId(['userId' => 'u1', 'conversationId' => 'conv1']);
        $this->assertSame('web:u1:conv1', $id);
    }

    public function test_thread_id_decode(): void
    {
        $decoded = $this->adapter->decodeThreadId('web:u1:conv1');
        $this->assertSame('u1', $decoded['userId']);
        $this->assertSame('conv1', $decoded['conversationId']);
    }

    public function test_thread_id_decode_invalid(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->decodeThreadId('not-a-web-thread');
    }

    public function test_thread_id_decode_wrong_prefix(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->decodeThreadId('slack:C123:1.2');
    }

    public function test_channel_id_is_thread_id(): void
    {
        $this->assertSame('web:u1:conv1', $this->adapter->channelIdFromThreadId('web:u1:conv1'));
    }

    public function test_custom_thread_id_for(): void
    {
        $adapter = new WebAdapter(
            userName: 'bot',
            getUser: fn () => ['id' => 'u1'],
            threadIdFor: fn (string $userId, string $convId) => "custom:{$userId}:{$convId}",
        );

        $id = $adapter->encodeThreadId(['userId' => 'u1', 'conversationId' => 'c1']);
        $this->assertSame('custom:u1:c1', $id);
    }

    // --- Webhook verification ---

    public function test_verify_valid_request(): void
    {
        $request = $this->makeRequest([
            'id' => 'conv-1',
            'messages' => [
                ['id' => 'msg-1', 'role' => 'user', 'text' => 'hello'],
            ],
        ]);

        $response = $this->adapter->verifyWebhook($request);
        $this->assertNull($response);
        $this->assertTrue($this->adapter->hasResolvedUser());
    }

    public function test_verify_invalid_json(): void
    {
        $request = $this->factory->createServerRequest('POST', '/api/chat')
            ->withBody($this->factory->createStream('{not json'));

        $response = $this->adapter->verifyWebhook($request);
        $this->assertNotNull($response);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertErrorContains($response, 'Invalid JSON');
    }

    public function test_verify_missing_messages(): void
    {
        $response = $this->adapter->verifyWebhook($this->makeRequest(['id' => 'x']));
        $this->assertNotNull($response);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertErrorContains($response, 'messages array');
    }

    public function test_verify_empty_messages(): void
    {
        $response = $this->adapter->verifyWebhook($this->makeRequest(['messages' => []]));
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_verify_no_user_message(): void
    {
        $response = $this->adapter->verifyWebhook($this->makeRequest([
            'messages' => [
                ['id' => 'a1', 'role' => 'assistant', 'text' => 'hi'],
            ],
        ]));
        $this->assertSame(400, $response->getStatusCode());
        $this->assertErrorContains($response, 'No user message');
    }

    public function test_verify_unauthorized_user(): void
    {
        $adapter = new WebAdapter(
            userName: 'bot',
            getUser: fn () => null,
            psrFactory: $this->factory,
        );

        $response = $adapter->verifyWebhook($this->makeRequest([
            'messages' => [['id' => 'm1', 'role' => 'user', 'text' => 'hi']],
        ]));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_verify_user_id_with_colon(): void
    {
        $adapter = new WebAdapter(
            userName: 'bot',
            getUser: fn () => ['id' => 'user:bad'],
            psrFactory: $this->factory,
        );

        $response = $adapter->verifyWebhook($this->makeRequest([
            'messages' => [['id' => 'm1', 'role' => 'user', 'text' => 'hi']],
        ]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertErrorContains($response, 'user id');
    }

    public function test_verify_conversation_id_with_colon(): void
    {
        $response = $this->adapter->verifyWebhook($this->makeRequest([
            'id' => 'bad:id',
            'messages' => [['id' => 'm1', 'role' => 'user', 'text' => 'hi']],
        ]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertErrorContains($response, 'conversation id');
    }

    public function test_verify_resets_state_between_requests(): void
    {
        $callCount = 0;
        $adapter = new WebAdapter(
            userName: 'bot',
            getUser: function () use (&$callCount) {
                $callCount++;

                return ['id' => "u-{$callCount}", 'name' => "User {$callCount}"];
            },
            psrFactory: $this->factory,
        );

        $adapter->verifyWebhook($this->makeRequest([
            'id' => 'conv-1',
            'messages' => [['id' => 'm1', 'role' => 'user', 'text' => 'first']],
        ]));
        $this->assertSame('u-1', $adapter->parseWebhook(
            $this->makeRequest(['id' => 'conv-1', 'messages' => [['id' => 'm1', 'role' => 'user', 'text' => 'first']]])
        )->author->id);

        // Second call resets state
        $adapter->verifyWebhook($this->makeRequest([
            'id' => 'conv-2',
            'messages' => [['id' => 'm2', 'role' => 'user', 'text' => 'second']],
        ]));
    }

    // --- Parse webhook ---

    public function test_parse_webhook_extracts_message(): void
    {
        $request = $this->makeRequest([
            'id' => 'conv-1',
            'messages' => [
                ['id' => 'msg-1', 'role' => 'user', 'text' => 'hello world'],
            ],
        ]);

        $this->adapter->verifyWebhook($request);
        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('msg-1', $message->id);
        $this->assertSame('hello world', $message->text);
        $this->assertSame('u-test', $message->author->id);
        $this->assertSame('Test User', $message->author->name);
        $this->assertFalse($message->author->isBot);
        $this->assertTrue($message->isDM);
        $this->assertSame('web:u-test:conv-1', $message->threadId);
    }

    public function test_parse_webhook_extracts_last_user_message(): void
    {
        $request = $this->makeRequest([
            'id' => 'conv-1',
            'messages' => [
                ['id' => 'm1', 'role' => 'user', 'text' => 'first'],
                ['id' => 'm2', 'role' => 'assistant', 'text' => 'reply'],
                ['id' => 'm3', 'role' => 'user', 'text' => 'second'],
            ],
        ]);

        $this->adapter->verifyWebhook($request);
        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('m3', $message->id);
        $this->assertSame('second', $message->text);
    }

    public function test_parse_generates_conversation_id_when_missing(): void
    {
        $request = $this->makeRequest([
            'messages' => [['id' => 'm1', 'role' => 'user', 'text' => 'hi']],
        ]);

        $this->adapter->verifyWebhook($request);
        $message = $this->adapter->parseWebhook($request);

        $this->assertStringStartsWith('web:u-test:', $message->threadId);
    }

    // --- Message operations ---

    public function test_post_message_buffers_text(): void
    {
        $sent = $this->adapter->postMessage('web:u1:c1', PostableMessage::text('Hello'));

        $this->assertNotEmpty($sent->id);
        $this->assertSame('web:u1:c1', $sent->threadId);
        $this->assertSame('Hello', $this->adapter->getBufferedReply());
    }

    public function test_post_message_appends_multiple(): void
    {
        $this->adapter->postMessage('web:u1:c1', PostableMessage::text('Hello '));
        $this->adapter->postMessage('web:u1:c1', PostableMessage::text('World'));

        $this->assertSame('Hello World', $this->adapter->getBufferedReply());
    }

    public function test_post_message_with_card_uses_fallback(): void
    {
        $card = Card::make()
            ->header('Deploy')
            ->section(fn ($s) => $s->text('Build passed'))
            ->actions([Button::primary('Deploy', 'deploy')]);

        $this->adapter->postMessage('web:u1:c1', PostableMessage::card($card));

        $this->assertStringContainsString('Deploy', $this->adapter->getBufferedReply());
    }

    public function test_stream_collects_and_buffers(): void
    {
        $sent = $this->adapter->stream('web:u1:c1', ['Hello ', 'World', '!']);

        $this->assertNotNull($sent);
        $this->assertSame('Hello World!', $this->adapter->getBufferedReply());
    }

    public function test_stream_empty_returns_null(): void
    {
        $sent = $this->adapter->stream('web:u1:c1', []);
        $this->assertNull($sent);
    }

    // --- Unsupported operations ---

    public function test_edit_message_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->editMessage('web:u1:c1', 'm1', PostableMessage::text('x'));
    }

    public function test_delete_message_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->deleteMessage('web:u1:c1', 'm1');
    }

    public function test_add_reaction_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->addReaction('web:u1:c1', 'm1', '👍');
    }

    public function test_remove_reaction_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->removeReaction('web:u1:c1', 'm1', '👍');
    }

    // --- Supported no-ops ---

    public function test_start_typing_is_noop(): void
    {
        $this->adapter->startTyping('web:u1:c1');
        $this->assertTrue(true);
    }

    public function test_disconnect_is_noop(): void
    {
        $this->adapter->disconnect();
        $this->assertTrue(true);
    }

    // --- Fetch operations ---

    public function test_fetch_messages_returns_empty(): void
    {
        $result = $this->adapter->fetchMessages('web:u1:c1');
        $this->assertCount(0, $result->messages);
        $this->assertNull($result->nextCursor);
    }

    public function test_fetch_thread(): void
    {
        $info = $this->adapter->fetchThread('web:u1:c1');
        $this->assertSame('web:u1:c1', $info->id);
        $this->assertSame('web:u1:c1', $info->channelId);
        $this->assertSame(0, $info->messageCount);
    }

    public function test_fetch_channel_info_returns_null(): void
    {
        $this->assertNull($this->adapter->fetchChannelInfo('web:u1:c1'));
    }

    public function test_get_user_returns_null(): void
    {
        $this->assertNull($this->adapter->getUser('u1'));
    }

    public function test_open_dm(): void
    {
        $threadId = $this->adapter->openDM('u1');
        $this->assertStringStartsWith('web:u1:', $threadId);
    }

    public function test_get_format_converter(): void
    {
        $this->assertNotNull($this->adapter->getFormatConverter());
    }

    // --- Response building ---

    public function test_create_response(): void
    {
        $request = $this->makeRequest([
            'id' => 'conv-1',
            'messages' => [['id' => 'm1', 'role' => 'user', 'text' => 'hi']],
        ]);
        $this->adapter->verifyWebhook($request);
        $this->adapter->postMessage('web:u-test:conv-1', PostableMessage::text('Hello!'));

        $response = $this->adapter->createResponse();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('conv-1', $data['id']);
        $this->assertSame('assistant', $data['role']);
        $this->assertSame('Hello!', $data['text']);
    }

    // --- Helpers ---

    private function makeRequest(array $body): ServerRequestInterface
    {
        return $this->factory->createServerRequest('POST', '/api/chat')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream(json_encode($body)));
    }

    private function assertErrorContains(ResponseInterface $response, string $needle): void
    {
        $data = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString($needle, $data['error']);
    }
}
