<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Web\Cards;

use BootDesk\ChatSDK\Core\Cards\Card;

class WebProductCard extends Card
{
    public function __construct(
        public readonly string $url,
        public readonly string $title,
        public readonly float $price,
        public readonly string $currency = 'USD',
        public readonly ?string $badge = null,
        public readonly ?array $actions = null,
    ) {}

    public function toArray(): array
    {
        $result = [
            'type' => 'product',
            'url' => $this->url,
            'title' => $this->title,
            'price' => $this->price,
            'currency' => $this->currency,
        ];

        if ($this->badge !== null) {
            $result['badge'] = $this->badge;
        }
        if ($this->actions !== null) {
            $result['actions'] = $this->actions;
        }

        return $result;
    }

    public function getFallbackText(): string
    {
        $text = "{$this->title} - {$this->price} {$this->currency}";

        if ($this->badge !== null) {
            $text .= " [{$this->badge}]";
        }

        return $text;
    }
}
