<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Web\Cards;

use BootDesk\ChatSDK\Core\Cards\Card;

class WebCarouselCard extends Card
{
    /** @param Card[] $items */
    public function __construct(
        public readonly array $items,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'carousel',
            'items' => array_map(
                fn (Card $item): array => $item->toArray(),
                $this->items,
            ),
        ];
    }

    public function getFallbackText(): string
    {
        $parts = [];

        foreach ($this->items as $item) {
            $parts[] = $item->getFallbackText();
        }

        return implode("\n---\n", $parts);
    }
}
