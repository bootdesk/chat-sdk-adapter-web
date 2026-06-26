<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Web\Cards;

use BootDesk\ChatSDK\Core\Cards\Card;

class WebLocationCard extends Card
{
    public function __construct(
        public readonly float $lat,
        public readonly float $lng,
        public readonly ?string $title = null,
        public readonly ?string $address = null,
        public readonly ?int $zoom = null,
    ) {}

    public function toArray(): array
    {
        $result = [
            'type' => 'location',
            'lat' => $this->lat,
            'lng' => $this->lng,
        ];

        if ($this->title !== null) {
            $result['title'] = $this->title;
        }
        if ($this->address !== null) {
            $result['address'] = $this->address;
        }
        if ($this->zoom !== null) {
            $result['zoom'] = $this->zoom;
        }

        return $result;
    }

    public function getFallbackText(): string
    {
        $parts = [];

        if ($this->title !== null) {
            $parts[] = $this->title;
        }
        if ($this->address !== null) {
            $parts[] = $this->address;
        }

        $parts[] = "{$this->lat}, {$this->lng}";

        return implode("\n", $parts);
    }
}
