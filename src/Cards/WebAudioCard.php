<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Web\Cards;

use BootDesk\ChatSDK\Core\Cards\Card;

class WebAudioCard extends Card
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $title = null,
        public readonly ?int $duration = null,
    ) {}

    public function toArray(): array
    {
        $result = ['type' => 'audio', 'url' => $this->url];

        if ($this->title !== null) {
            $result['title'] = $this->title;
        }
        if ($this->duration !== null) {
            $result['duration'] = $this->duration;
        }

        return $result;
    }

    public function getFallbackText(): string
    {
        return $this->title ?? 'Audio';
    }
}
