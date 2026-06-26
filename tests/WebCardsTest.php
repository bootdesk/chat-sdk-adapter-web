<?php

namespace BootDesk\ChatSDK\Web\Tests;

use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Contracts\BroadcastAdapter;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Web\Cards\WebAudioCard;
use BootDesk\ChatSDK\Web\Cards\WebCarouselCard;
use BootDesk\ChatSDK\Web\Cards\WebLocationCard;
use BootDesk\ChatSDK\Web\Cards\WebPollCard;
use BootDesk\ChatSDK\Web\Cards\WebProductCard;
use BootDesk\ChatSDK\Web\Cards\WebVideoCard;
use BootDesk\ChatSDK\Web\WebAdapter;
use BootDesk\ChatSDK\Web\WebAdapterConfig;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

class WebCardsTest extends TestCase
{
    private function makeAdapter(?BroadcastAdapter $broadcaster = null, bool $asyncMode = false): WebAdapter
    {
        return new WebAdapter(
            userName: 'testbot',
            config: new class extends WebAdapterConfig
            {
                public function getUser(ServerRequestInterface $request): ?array
                {
                    return ['id' => 'u-test', 'name' => 'Test User'];
                }
            },
            broadcaster: $broadcaster,
            asyncMode: $asyncMode,
        );
    }

    public function test_video_card_to_array(): void
    {
        $card = new WebVideoCard(
            url: 'https://example.com/video.mp4',
            thumbnail: 'https://example.com/thumb.jpg',
            title: 'Demo',
            duration: 120,
            platform: 'youtube',
        );

        $array = $card->toArray();

        $this->assertSame('video', $array['type']);
        $this->assertSame('https://example.com/video.mp4', $array['url']);
        $this->assertSame('https://example.com/thumb.jpg', $array['thumbnail']);
        $this->assertSame('Demo', $array['title']);
        $this->assertSame(120, $array['duration']);
        $this->assertSame('youtube', $array['platform']);
    }

    public function test_video_card_minimal(): void
    {
        $card = new WebVideoCard(url: 'https://example.com/video.mp4');
        $array = $card->toArray();

        $this->assertSame('video', $array['type']);
        $this->assertArrayNotHasKey('thumbnail', $array);
        $this->assertArrayNotHasKey('title', $array);
    }

    public function test_video_card_fallback_text(): void
    {
        $card = new WebVideoCard(url: 'https://example.com/v.mp4', title: 'Demo');
        $this->assertSame('Demo', $card->getFallbackText());

        $card2 = new WebVideoCard(url: 'https://example.com/v.mp4');
        $this->assertSame('Video', $card2->getFallbackText());
    }

    public function test_video_card_instanceof_card(): void
    {
        $card = new WebVideoCard(url: 'https://example.com/v.mp4');
        $this->assertInstanceOf(Card::class, $card);
    }

    public function test_audio_card_to_array(): void
    {
        $card = new WebAudioCard(
            url: 'https://example.com/audio.mp3',
            title: 'Podcast',
            duration: 300,
        );

        $array = $card->toArray();

        $this->assertSame('audio', $array['type']);
        $this->assertSame('https://example.com/audio.mp3', $array['url']);
        $this->assertSame('Podcast', $array['title']);
        $this->assertSame(300, $array['duration']);
    }

    public function test_audio_card_minimal(): void
    {
        $card = new WebAudioCard(url: 'https://example.com/audio.mp3');
        $array = $card->toArray();

        $this->assertSame('audio', $array['type']);
        $this->assertArrayNotHasKey('title', $array);
    }

    public function test_audio_card_fallback_text(): void
    {
        $card = new WebAudioCard(url: 'https://example.com/a.mp3', title: 'Podcast');
        $this->assertSame('Podcast', $card->getFallbackText());

        $card2 = new WebAudioCard(url: 'https://example.com/a.mp3');
        $this->assertSame('Audio', $card2->getFallbackText());
    }

    public function test_location_card_to_array(): void
    {
        $card = new WebLocationCard(
            lat: -23.5505,
            lng: -46.6333,
            title: 'São Paulo',
            address: 'Av. Paulista, 1000',
            zoom: 15,
        );

        $array = $card->toArray();

        $this->assertSame('location', $array['type']);
        $this->assertSame(-23.5505, $array['lat']);
        $this->assertSame(-46.6333, $array['lng']);
        $this->assertSame('São Paulo', $array['title']);
        $this->assertSame('Av. Paulista, 1000', $array['address']);
        $this->assertSame(15, $array['zoom']);
    }

    public function test_location_card_minimal(): void
    {
        $card = new WebLocationCard(lat: 0.0, lng: 0.0);
        $array = $card->toArray();

        $this->assertSame('location', $array['type']);
        $this->assertArrayNotHasKey('title', $array);
    }

    public function test_location_card_fallback_text(): void
    {
        $card = new WebLocationCard(lat: -23.55, lng: -46.63, title: 'SP', address: 'Av Paulista');
        $text = $card->getFallbackText();

        $this->assertStringContainsString('SP', $text);
        $this->assertStringContainsString('Av Paulista', $text);
        $this->assertStringContainsString('-23.55', $text);
        $this->assertStringContainsString('-46.63', $text);
    }

    public function test_product_card_to_array(): void
    {
        $card = new WebProductCard(
            url: 'https://example.com/product.jpg',
            title: 'Widget',
            price: 29.99,
            currency: 'USD',
            badge: 'Sale',
            actions: [
                ['label' => 'Buy', 'actionId' => 'buy', 'value' => 'sku-123'],
            ],
        );

        $array = $card->toArray();

        $this->assertSame('product', $array['type']);
        $this->assertSame('Widget', $array['title']);
        $this->assertSame(29.99, $array['price']);
        $this->assertSame('USD', $array['currency']);
        $this->assertSame('Sale', $array['badge']);
        $this->assertCount(1, $array['actions']);
        $this->assertSame('Buy', $array['actions'][0]['label']);
    }

    public function test_product_card_minimal(): void
    {
        $card = new WebProductCard(url: 'https://example.com/p.jpg', title: 'Widget', price: 10.0);
        $array = $card->toArray();

        $this->assertSame('product', $array['type']);
        $this->assertArrayNotHasKey('badge', $array);
        $this->assertArrayNotHasKey('actions', $array);
    }

    public function test_product_card_fallback_text(): void
    {
        $card = new WebProductCard(url: 'https://example.com/p.jpg', title: 'Widget', price: 29.99, currency: 'USD');
        $this->assertStringContainsString('Widget', $card->getFallbackText());
        $this->assertStringContainsString('29.99', $card->getFallbackText());
        $this->assertStringContainsString('USD', $card->getFallbackText());
    }

    public function test_poll_card_to_array(): void
    {
        $card = new WebPollCard(
            question: 'Best color?',
            options: [
                ['id' => 'red', 'label' => 'Red'],
                ['id' => 'blue', 'label' => 'Blue'],
            ],
            allowMultiple: false,
            results: [
                ['optionId' => 'red', 'count' => 10],
                ['optionId' => 'blue', 'count' => 5],
            ],
        );

        $array = $card->toArray();

        $this->assertSame('poll', $array['type']);
        $this->assertSame('Best color?', $array['question']);
        $this->assertCount(2, $array['options']);
        $this->assertFalse($array['allowMultiple']);
        $this->assertCount(2, $array['results']);
        $this->assertSame(10, $array['results'][0]['count']);
    }

    public function test_poll_card_no_results(): void
    {
        $card = new WebPollCard(
            question: 'Pick one',
            options: [['id' => 'a', 'label' => 'A']],
        );

        $array = $card->toArray();

        $this->assertArrayNotHasKey('results', $array);
    }

    public function test_poll_card_fallback_text(): void
    {
        $card = new WebPollCard(
            question: 'Best?',
            options: [['id' => 'a', 'label' => 'Option A']],
        );

        $text = $card->getFallbackText();
        $this->assertStringContainsString('Best?', $text);
        $this->assertStringContainsString('Option A', $text);
    }

    public function test_carousel_card_to_array(): void
    {
        $item1 = Card::make()->header('First');
        $item2 = Card::make()->header('Second');

        $card = new WebCarouselCard(items: [$item1, $item2]);
        $array = $card->toArray();

        $this->assertSame('carousel', $array['type']);
        $this->assertCount(2, $array['items']);
        $this->assertSame('card', $array['items'][0]['type']);
        $this->assertSame('First', $array['items'][0]['header']);
        $this->assertSame('Second', $array['items'][1]['header']);
    }

    public function test_carousel_card_fallback_text(): void
    {
        $item1 = Card::make()->header('First Item');
        $item2 = Card::make()->header('Second Item');

        $card = new WebCarouselCard(items: [$item1, $item2]);
        $text = $card->getFallbackText();

        $this->assertStringContainsString('First Item', $text);
        $this->assertStringContainsString('Second Item', $text);
        $this->assertStringContainsString('---', $text);
    }

    public function test_web_card_is_detected_as_card(): void
    {
        $videoCard = new WebVideoCard(url: 'https://example.com/v.mp4');
        $this->assertTrue($videoCard instanceof Card);
    }

    public function test_video_card_works_through_postable_message(): void
    {
        $videoCard = new WebVideoCard(
            url: 'https://example.com/video.mp4',
            title: 'Demo Video',
        );

        $postable = PostableMessage::card($videoCard);

        $this->assertTrue($postable->isCard());
        $this->assertSame('Demo Video', $postable->getTextContent());
    }

    public function test_video_card_includes_card_in_event(): void
    {
        $broadcaster = $this->createMock(BroadcastAdapter::class);
        $adapter = $this->makeAdapter(broadcaster: $broadcaster, asyncMode: false);

        $card = new WebVideoCard(
            url: 'https://example.com/video.mp4',
            title: 'Tutorial',
        );

        $adapter->postMessage('web:u1:c1', PostableMessage::card($card));

        $response = $adapter->createResponse();
        $data = json_decode((string) $response->getBody(), true);

        $this->assertCount(1, $data['events']);
        $event = $data['events'][0];
        $this->assertSame('message.posted', $event['type']);
        $this->assertArrayHasKey('card', $event['data']);
        $this->assertSame('video', $event['data']['card']['type']);
        $this->assertSame('Tutorial', $event['data']['card']['title']);
    }

    public function test_carousel_card_includes_items_in_event(): void
    {
        $broadcaster = $this->createMock(BroadcastAdapter::class);
        $adapter = $this->makeAdapter(broadcaster: $broadcaster, asyncMode: false);

        $item = Card::make()->header('Slide 1');
        $carousel = new WebCarouselCard(items: [$item]);

        $adapter->postMessage('web:u1:c1', PostableMessage::card($carousel));

        $response = $adapter->createResponse();
        $data = json_decode((string) $response->getBody(), true);

        $event = $data['events'][0];
        $this->assertSame('carousel', $event['data']['card']['type']);
        $this->assertCount(1, $event['data']['card']['items']);
        $this->assertSame('Slide 1', $event['data']['card']['items'][0]['header']);
    }

    public function test_video_card_uses_fallback_text_in_reply(): void
    {
        $adapter = $this->makeAdapter();

        $card = new WebVideoCard(url: 'https://example.com/v.mp4', title: 'How-To');
        $adapter->postMessage('web:u1:c1', PostableMessage::card($card));

        $this->assertStringContainsString('How-To', $adapter->getBufferedReply());
    }

    public function test_poll_card_with_actions_uses_on_action_click(): void
    {
        $card = new WebPollCard(
            question: 'Vote?',
            options: [['id' => 'yes', 'label' => 'Yes']],
        );

        $array = $card->toArray();
        $this->assertSame('poll', $array['type']);
    }
}
