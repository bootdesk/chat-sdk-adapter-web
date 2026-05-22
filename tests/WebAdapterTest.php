<?php

namespace BootDesk\ChatSDK\Web\Tests;

use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\Broadcasting\BroadcastEvent;
use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\ChannelInfo;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\BroadcastAdapter;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\FetchOptions;
use BootDesk\ChatSDK\Core\FetchResult;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\ThreadInfo;
use BootDesk\ChatSDK\Web\WebAdapter;
use BootDesk\ChatSDK\Web\WebAdapterConfig;
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

        $this->adapter = $this->makeAdapter();
    }

    private function makeAdapter(
        ?WebAdapterConfig $config = null,
        ?BroadcastAdapter $broadcaster = null,
        bool $asyncMode = false,
    ): WebAdapter {
        return new WebAdapter(
            userName: 'testbot',
            config: $config ?? new class extends WebAdapterConfig
            {
                public function getUser(ServerRequestInterface $request): ?array
                {
                    return ['id' => 'u-test', 'name' => 'Test User'];
                }
            },
            psrFactory: $this->factory,
            broadcaster: $broadcaster,
            asyncMode: $asyncMode,
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
            config: new class extends WebAdapterConfig
            {
                public function threadIdFor(string $userId, string $conversationId): string
                {
                    return "custom:{$userId}:{$conversationId}";
                }
            },
        );

        $id = $adapter->encodeThreadId(['userId' => 'u1', 'conversationId' => 'c1']);
        $this->assertSame('custom:u1:c1', $id);
    }

    public function test_config_from_class_name_string(): void
    {
        $adapter = new WebAdapter(
            userName: 'bot',
            config: TestWebAdapterConfig::class,
            psrFactory: $this->factory,
        );

        $id = $adapter->encodeThreadId(['userId' => 'u1', 'conversationId' => 'c1']);
        $this->assertSame('test:u1:c1', $id);
    }

    public function test_config_from_invalid_class_name_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('does not exist');

        new WebAdapter(
            userName: 'bot',
            config: 'NonExistentClass',
        );
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
        $adapter = $this->makeAdapter(new class extends WebAdapterConfig
        {
            public function getUser(ServerRequestInterface $request): ?array
            {
                return null;
            }
        });

        $response = $adapter->verifyWebhook($this->makeRequest([
            'messages' => [['id' => 'm1', 'role' => 'user', 'text' => 'hi']],
        ]));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_verify_user_id_with_colon(): void
    {
        $adapter = $this->makeAdapter(new class extends WebAdapterConfig
        {
            public function getUser(ServerRequestInterface $request): ?array
            {
                return ['id' => 'user:bad'];
            }
        });

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
        $adapter = new WebAdapter(
            userName: 'bot',
            config: new class extends WebAdapterConfig
            {
                public int $callCount = 0;

                public function getUser(ServerRequestInterface $request): ?array
                {
                    $this->callCount++;

                    return ['id' => "u-{$this->callCount}", 'name' => "User {$this->callCount}"];
                }
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

    public function test_verify_action_payload_skips_messages_validation(): void
    {
        $request = $this->makeRequest([
            'id' => 'conv-1',
            'action' => [
                'actionId' => 'confirm',
                'value' => 'yes',
                'messageId' => 'msg-1',
            ],
        ]);

        $response = $this->adapter->verifyWebhook($request);
        $this->assertNull($response);
        $this->assertTrue($this->adapter->hasResolvedUser());
    }

    public function test_verify_action_payload_without_conversation_id(): void
    {
        $request = $this->makeRequest([
            'action' => [
                'actionId' => 'cancel',
                'value' => null,
                'messageId' => 'msg-2',
            ],
        ]);

        $response = $this->adapter->verifyWebhook($request);
        $this->assertNull($response);
    }

    // --- Action handling ---

    public function test_parse_action_returns_correct_data(): void
    {
        $request = $this->makeRequest([
            'id' => 'conv-1',
            'action' => [
                'actionId' => 'deploy',
                'value' => 'production',
                'messageId' => 'msg-123',
            ],
        ]);

        $this->adapter->verifyWebhook($request);
        $actionData = $this->adapter->parseAction($request);

        $this->assertNotNull($actionData);
        $this->assertSame('deploy', $actionData['actionId']);
        $this->assertSame('production', $actionData['value']);
        $this->assertSame('msg-123', $actionData['messageId']);
        $this->assertSame('u-test', $actionData['userId']);
        $this->assertSame('web:u-test:conv-1', $actionData['threadId']);
        $this->assertFalse($actionData['isBot']);
        $this->assertFalse($actionData['isMe']);
    }

    public function test_parse_action_returns_null_without_action_key(): void
    {
        $request = $this->makeRequest([
            'messages' => [['id' => 'm1', 'role' => 'user', 'text' => 'hi']],
        ]);

        $this->adapter->verifyWebhook($request);
        $actionData = $this->adapter->parseAction($request);

        $this->assertNull($actionData);
    }

    public function test_parse_action_returns_null_without_action_id(): void
    {
        $request = $this->makeRequest([
            'action' => ['value' => 'yes', 'messageId' => 'msg-1'],
        ]);

        $this->adapter->verifyWebhook($request);
        $actionData = $this->adapter->parseAction($request);

        $this->assertNull($actionData);
    }

    public function test_acknowledge_action_returns_null(): void
    {
        $this->assertNull($this->adapter->acknowledgeAction('some-id'));
        $this->assertNull($this->adapter->acknowledgeAction(null));
    }

    // --- Slash commands ---

    public function test_parse_slash_command_detects_slash(): void
    {
        $request = $this->makeRequest([
            'id' => 'conv-1',
            'messages' => [
                ['id' => 'm1', 'role' => 'user', 'text' => '/help'],
            ],
        ]);

        $this->adapter->verifyWebhook($request);
        $data = $this->adapter->parseSlashCommand($request);

        $this->assertNotNull($data);
        $this->assertSame('/help', $data['command']);
        $this->assertSame('', $data['text']);
        $this->assertSame('u-test', $data['userId']);
    }

    public function test_parse_slash_command_with_args(): void
    {
        $request = $this->makeRequest([
            'id' => 'conv-1',
            'messages' => [
                ['id' => 'm1', 'role' => 'user', 'text' => '/deploy production --force'],
            ],
        ]);

        $this->adapter->verifyWebhook($request);
        $data = $this->adapter->parseSlashCommand($request);

        $this->assertNotNull($data);
        $this->assertSame('/deploy', $data['command']);
        $this->assertSame('production --force', $data['text']);
    }

    public function test_parse_slash_command_returns_null_for_normal_text(): void
    {
        $request = $this->makeRequest([
            'id' => 'conv-1',
            'messages' => [
                ['id' => 'm1', 'role' => 'user', 'text' => 'hello world'],
            ],
        ]);

        $this->adapter->verifyWebhook($request);
        $data = $this->adapter->parseSlashCommand($request);

        $this->assertNull($data);
    }

    public function test_parse_slash_command_returns_null_for_action_payload(): void
    {
        $request = $this->makeRequest([
            'id' => 'conv-1',
            'action' => ['actionId' => 'click', 'value' => 'yes', 'messageId' => 'm1'],
        ]);

        $this->adapter->verifyWebhook($request);
        $data = $this->adapter->parseSlashCommand($request);

        $this->assertNull($data);
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

    public function test_post_message_with_card_includes_card_in_event(): void
    {
        $broadcaster = $this->createMock(BroadcastAdapter::class);

        $adapter = $this->makeAdapter(broadcaster: $broadcaster, asyncMode: false);

        $card = Card::make()
            ->header('Deploy Status')
            ->section(fn ($s) => $s->text('Build passed')->fields(['Status' => 'success', 'Branch' => 'main']))
            ->actions([Button::primary('Deploy', 'deploy'), Button::danger('Cancel', 'cancel')]);

        $adapter->postMessage('web:u1:c1', PostableMessage::card($card));

        $response = $adapter->createResponse();
        $data = json_decode((string) $response->getBody(), true);

        $this->assertCount(1, $data['events']);
        $event = $data['events'][0];
        $this->assertSame('message.posted', $event['type']);
        $this->assertArrayHasKey('card', $event['data']);
        $this->assertSame('card', $event['data']['card']['type']);
        $this->assertSame('Deploy Status', $event['data']['card']['header']);
        $this->assertCount(1, $event['data']['card']['sections']);
        $this->assertSame('Build passed', $event['data']['card']['sections'][0]['text']);
        $this->assertCount(2, $event['data']['card']['sections'][0]['fields']);
        $this->assertCount(2, $event['data']['card']['actions']);
        $this->assertSame('deploy', $event['data']['card']['actions'][0]['id']);
        $this->assertSame('primary', $event['data']['card']['actions'][0]['style']);
    }

    public function test_post_message_without_card_has_no_card_in_event(): void
    {
        $broadcaster = $this->createMock(BroadcastAdapter::class);

        $adapter = $this->makeAdapter(broadcaster: $broadcaster, asyncMode: false);

        $adapter->postMessage('web:u1:c1', PostableMessage::text('Hello'));

        $response = $adapter->createResponse();
        $data = json_decode((string) $response->getBody(), true);

        $this->assertCount(1, $data['events']);
        $this->assertArrayNotHasKey('card', $data['events'][0]['data']);
    }

    public function test_edit_message_with_card_includes_card_in_event(): void
    {
        $broadcaster = $this->createMock(BroadcastAdapter::class);

        $adapter = $this->makeAdapter(broadcaster: $broadcaster, asyncMode: false);

        $card = Card::make()
            ->header('Updated')
            ->section(fn ($s) => $s->text('New content'));

        $adapter->editMessage('web:u1:c1', 'msg-1', PostableMessage::card($card));

        $response = $adapter->createResponse();
        $data = json_decode((string) $response->getBody(), true);

        $this->assertCount(1, $data['events']);
        $event = $data['events'][0];
        $this->assertSame('message.edited', $event['type']);
        $this->assertArrayHasKey('card', $event['data']);
        $this->assertSame('Updated', $event['data']['card']['header']);
    }

    public function test_card_to_array_serializes_all_elements(): void
    {
        $card = Card::make()
            ->header('Full Card')
            ->imageUrl('https://example.com/img.png', 'An image')
            ->section(fn ($s) => $s->text('Section text')->fields(['Key' => 'Value']))
            ->text('Standalone text')
            ->divider()
            ->link('GitHub', 'https://github.com')
            ->table(['Name', 'Email'], [['Alice', 'alice@example.com']])
            ->linkButton('Visit', 'https://example.com')
            ->actions([Button::primary('Action', 'act-1', ['foo' => 'bar'])]);

        $array = $card->toArray();

        $this->assertSame('card', $array['type']);
        $this->assertSame('Full Card', $array['header']);
        $this->assertSame('https://example.com/img.png', $array['image']['url']);
        $this->assertSame('An image', $array['image']['alt']);
        $this->assertCount(1, $array['sections']);
        $this->assertSame('Section text', $array['sections'][0]['text']);
        $this->assertCount(1, $array['sections'][0]['fields']);
        $this->assertSame('Key', $array['sections'][0]['fields'][0]['title']);
        $this->assertCount(1, $array['actions']);
        $this->assertSame('act-1', $array['actions'][0]['id']);
        $this->assertCount(5, $array['elements']);
        $this->assertSame('text', $array['elements'][0]['type']);
        $this->assertSame('divider', $array['elements'][1]['type']);
        $this->assertSame('link', $array['elements'][2]['type']);
        $this->assertSame('table', $array['elements'][3]['type']);
        $this->assertSame('link_button', $array['elements'][4]['type']);
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

    public function test_edit_message_succeeds(): void
    {
        $sent = $this->adapter->editMessage('web:u1:c1', 'm1', PostableMessage::text('edited'));
        $this->assertSame('m1', $sent->id);
        $this->assertSame('web:u1:c1', $sent->threadId);
    }

    public function test_delete_message_succeeds(): void
    {
        $this->adapter->deleteMessage('web:u1:c1', 'm1');
        $this->assertTrue(true);
    }

    public function test_add_reaction_succeeds(): void
    {
        $this->adapter->addReaction('web:u1:c1', 'm1', '👍');
        $this->assertTrue(true);
    }

    public function test_remove_reaction_succeeds(): void
    {
        $this->adapter->removeReaction('web:u1:c1', 'm1', '👍');
        $this->assertTrue(true);
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

    // --- Config delegation ---

    public function test_fetch_messages_delegates_to_config(): void
    {
        $config = new class extends WebAdapterConfig
        {
            public function fetchMessages(string $threadId, ?FetchOptions $options = null): FetchResult
            {
                return new FetchResult(messages: [
                    new Message(
                        id: 'm1',
                        threadId: $threadId,
                        author: new Author(id: 'u1'),
                        text: 'from config',
                    ),
                ]);
            }
        };

        $adapter = $this->makeAdapter(config: $config);
        $result = $adapter->fetchMessages('web:u1:c1');
        $this->assertCount(1, $result->messages);
        $this->assertSame('from config', $result->messages[0]->text);
    }

    public function test_fetch_thread_delegates_to_config(): void
    {
        $config = new class extends WebAdapterConfig
        {
            public function fetchThread(string $threadId): ThreadInfo
            {
                return new ThreadInfo(
                    id: 'web:u1:custom-id',
                    channelId: 'web:u1:custom-channel',
                    messageCount: 42,
                );
            }
        };

        $adapter = $this->makeAdapter(config: $config);
        $info = $adapter->fetchThread('web:u1:c1');
        $this->assertSame('web:u1:custom-id', $info->id);
        $this->assertSame('web:u1:custom-channel', $info->channelId);
        $this->assertSame(42, $info->messageCount);
    }

    public function test_fetch_channel_info_delegates_to_config(): void
    {
        $config = new class extends WebAdapterConfig
        {
            public function fetchChannelInfo(string $channelId): ?ChannelInfo
            {
                return new ChannelInfo(id: 'web:u1:custom-channel', name: 'Custom Channel');
            }
        };

        $adapter = $this->makeAdapter(config: $config);
        $info = $adapter->fetchChannelInfo('web:ch:1');
        $this->assertNotNull($info);
        $this->assertSame('web:u1:custom-channel', $info->id);
        $this->assertSame('Custom Channel', $info->name);
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

    public function test_fetch_thread_validates_id_format(): void
    {
        $config = new class extends WebAdapterConfig
        {
            public function fetchThread(string $threadId): ThreadInfo
            {
                return new ThreadInfo(id: 'invalid', channelId: 'web:u1:c1', messageCount: 0);
            }
        };

        $adapter = $this->makeAdapter(config: $config);

        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('ThreadInfo::id');
        $adapter->fetchThread('web:u1:c1');
    }

    public function test_fetch_thread_validates_channel_id_format(): void
    {
        $config = new class extends WebAdapterConfig
        {
            public function fetchThread(string $threadId): ThreadInfo
            {
                return new ThreadInfo(id: 'web:u1:c1', channelId: 'bad', messageCount: 0);
            }
        };

        $adapter = $this->makeAdapter(config: $config);

        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('ThreadInfo::channelId');
        $adapter->fetchThread('web:u1:c1');
    }

    public function test_fetch_channel_info_validates_id_format(): void
    {
        $config = new class extends WebAdapterConfig
        {
            public function fetchChannelInfo(string $channelId): ?ChannelInfo
            {
                return new ChannelInfo(id: 'no-prefix');
            }
        };

        $adapter = $this->makeAdapter(config: $config);

        $this->expectException(AdapterException::class);
        $this->expectExceptionMessage('ChannelInfo::id');
        $adapter->fetchChannelInfo('web:u1:c1');
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
        $this->assertArrayHasKey('events', $data);
        $this->assertIsArray($data['events']);
    }

    // --- Broadcast mode: sync (accumulated events) ---

    public function test_sync_mode_includes_events_in_response(): void
    {
        $broadcaster = $this->createMock(BroadcastAdapter::class);
        $adapter = $this->makeAdapter(broadcaster: $broadcaster, asyncMode: false);

        $adapter->postMessage('web:u1:c1', PostableMessage::text('Hello'));
        $adapter->editMessage('web:u1:c1', 'm1', PostableMessage::text('Edited'));
        $adapter->addReaction('web:u1:c1', 'm1', '👍');
        $adapter->startTyping('web:u1:c1');

        $response = $adapter->createResponse();
        $data = json_decode((string) $response->getBody(), true);

        $this->assertArrayHasKey('events', $data);
        $this->assertCount(4, $data['events']);
        $this->assertSame('message.posted', $data['events'][0]['type']);
        $this->assertSame('message.edited', $data['events'][1]['type']);
        $this->assertSame('reaction.added', $data['events'][2]['type']);
        $this->assertSame('typing.started', $data['events'][3]['type']);
    }

    public function test_sync_mode_streaming_emits_chunk_events(): void
    {
        $broadcaster = $this->createMock(BroadcastAdapter::class);
        $adapter = $this->makeAdapter(broadcaster: $broadcaster, asyncMode: false);

        $stream = (function () {
            yield 'Hello ';
            yield 'World';
        })();

        $adapter->stream('web:u1:c1', $stream);

        $events = $adapter->getAccumulatedEvents();
        $this->assertCount(3, $events); // 2 chunks + 1 final
        $this->assertSame('streaming.chunk', $events[0]['type']);
        $this->assertSame('Hello ', $events[0]['data']['chunk']);
        $this->assertFalse($events[0]['data']['isFinal']);
        $this->assertSame('World', $events[1]['data']['chunk']);
        $this->assertFalse($events[1]['data']['isFinal']);
        $this->assertSame('', $events[2]['data']['chunk']);
        $this->assertTrue($events[2]['data']['isFinal']);
    }

    public function test_sync_mode_without_broadcaster_accumulates_events(): void
    {
        $adapter = $this->makeAdapter(asyncMode: false);

        $adapter->postMessage('web:u1:c1', PostableMessage::text('Hello'));

        $response = $adapter->createResponse();
        $data = json_decode((string) $response->getBody(), true);

        $this->assertArrayHasKey('events', $data);
        $this->assertIsArray($data['events']);
        // With no broadcaster, no events are accumulated
        $this->assertCount(0, $data['events']);
    }

    // --- Broadcast mode: async (real-time broadcasting) ---

    public function test_async_mode_broadcasts_events(): void
    {
        $broadcastEvents = [];

        $broadcaster = $this->createMock(BroadcastAdapter::class);
        $broadcaster->method('isBroadcastingAvailable')->willReturn(true);
        $broadcaster->method('broadcast')->willReturnCallback(
            function ($threadId, BroadcastEvent $event) use (&$broadcastEvents) {
                $broadcastEvents[] = $event;
            }
        );

        $adapter = $this->makeAdapter(broadcaster: $broadcaster, asyncMode: true);

        $adapter->postMessage('web:u1:c1', PostableMessage::text('Hello'));
        $adapter->editMessage('web:u1:c1', 'm1', PostableMessage::text('Edited'));

        $this->assertCount(2, $broadcastEvents);
        $this->assertSame('message.posted', $broadcastEvents[0]->type);
        $this->assertSame('message.edited', $broadcastEvents[1]->type);
    }

    public function test_async_mode_broadcasts_card_in_event(): void
    {
        $broadcastEvents = [];

        $broadcaster = $this->createMock(BroadcastAdapter::class);
        $broadcaster->method('broadcast')->willReturnCallback(
            function ($threadId, BroadcastEvent $event) use (&$broadcastEvents) {
                $broadcastEvents[] = $event;
            }
        );

        $adapter = $this->makeAdapter(broadcaster: $broadcaster, asyncMode: true);

        $card = Card::make()
            ->header('Async Card')
            ->section(fn ($s) => $s->text('Broadcast live'))
            ->actions([Button::primary('Click', 'click')]);

        $adapter->postMessage('web:u1:c1', PostableMessage::card($card));

        $this->assertCount(1, $broadcastEvents);
        $event = $broadcastEvents[0];
        $this->assertSame('message.posted', $event->type);
        $this->assertArrayHasKey('card', $event->data);
        $this->assertSame('Async Card', $event->data['card']['header']);
    }

    public function test_async_mode_does_not_include_events_in_response(): void
    {
        $broadcaster = $this->createMock(BroadcastAdapter::class);
        $adapter = $this->makeAdapter(broadcaster: $broadcaster, asyncMode: true);

        $adapter->postMessage('web:u1:c1', PostableMessage::text('Hello'));

        $response = $adapter->createResponse();
        $data = json_decode((string) $response->getBody(), true);

        $this->assertArrayHasKey('events', $data);
        $this->assertCount(0, $data['events']); // Not accumulated in async mode
    }

    public function test_async_mode_streaming_broadcasts_chunks(): void
    {
        $broadcastEvents = [];

        $broadcaster = $this->createMock(BroadcastAdapter::class);
        $broadcaster->method('broadcast')->willReturnCallback(
            function ($threadId, BroadcastEvent $event) use (&$broadcastEvents) {
                $broadcastEvents[] = $event;
            }
        );
        $broadcaster->method('broadcastToUser')->willReturnCallback(
            function ($threadId, $userId, BroadcastEvent $event) use (&$broadcastEvents) {
                $broadcastEvents[] = $event;
            }
        );

        $adapter = $this->makeAdapter(broadcaster: $broadcaster, asyncMode: true);

        $stream = (function () {
            yield 'Hello ';
            yield 'World';
        })();

        $adapter->stream('web:u1:c1', $stream);

        $this->assertCount(3, $broadcastEvents);
        $this->assertSame('streaming.chunk', $broadcastEvents[0]->type);
        $this->assertSame('Hello ', $broadcastEvents[0]->data['chunk']);
        $this->assertSame('World', $broadcastEvents[1]->data['chunk']);
        $this->assertSame('', $broadcastEvents[2]->data['chunk']);
        $this->assertTrue($broadcastEvents[2]->data['isFinal']);
    }

    public function test_open_dm_broadcasts_event_in_sync_mode(): void
    {
        $broadcaster = $this->createMock(BroadcastAdapter::class);
        $adapter = $this->makeAdapter(broadcaster: $broadcaster, asyncMode: false);

        $threadId = $adapter->openDM('user123');

        $this->assertStringStartsWith('web:user123:', $threadId);
        $events = $adapter->getAccumulatedEvents();
        $this->assertCount(1, $events);
        $this->assertSame('dm.requested', $events[0]['type']);
        $this->assertSame('user123', $events[0]['data']['userId']);
    }

    public function test_open_dm_broadcasts_event_in_async_mode(): void
    {
        $broadcastEvents = [];

        $broadcaster = $this->createMock(BroadcastAdapter::class);
        $broadcaster->method('broadcast')->willReturnCallback(
            function ($threadId, $event) use (&$broadcastEvents) {
                $broadcastEvents[] = $event;
            }
        );
        $broadcaster->method('broadcastToUser')->willReturnCallback(
            function ($threadId, $userId, $event) use (&$broadcastEvents) {
                $broadcastEvents[] = $event;
            }
        );

        $adapter = $this->makeAdapter(broadcaster: $broadcaster, asyncMode: true);

        $threadId = $adapter->openDM('user123');

        $this->assertStringStartsWith('web:user123:', $threadId);
        $this->assertCount(1, $broadcastEvents);
        $this->assertSame('dm.requested', $broadcastEvents[0]->type);
        $this->assertSame('user123', $broadcastEvents[0]->data['userId']);
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
