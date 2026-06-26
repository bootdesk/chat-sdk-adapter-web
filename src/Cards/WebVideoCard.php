<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Web\Cards;

use BootDesk\ChatSDK\Core\Cards\Card;

class WebVideoCard extends Card
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $thumbnail = null,
        public readonly ?string $title = null,
        public readonly ?int $duration = null,
        public readonly ?string $platform = null,
    ) {}

    public function toArray(): array
    {
        $result = ['type' => 'video', 'url' => $this->url];

        if ($this->thumbnail !== null) {
            $result['thumbnail'] = $this->thumbnail;
        }
        if ($this->title !== null) {
            $result['title'] = $this->title;
        }
        if ($this->duration !== null) {
            $result['duration'] = $this->duration;
        }
        if ($this->platform !== null) {
            $result['platform'] = $this->platform;
        }

        return $result;
    }

    public function getFallbackText(): string
    {
        return $this->title ?? 'Video';
    }
}
