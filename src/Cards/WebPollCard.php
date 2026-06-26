<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Web\Cards;

use BootDesk\ChatSDK\Core\Cards\Card;

class WebPollCard extends Card
{
    public function __construct(
        public readonly string $question,
        public readonly array $options,
        public readonly bool $allowMultiple = false,
        public readonly ?array $results = null,
    ) {}

    public function toArray(): array
    {
        $result = [
            'type' => 'poll',
            'question' => $this->question,
            'options' => $this->options,
            'allowMultiple' => $this->allowMultiple,
        ];

        if ($this->results !== null) {
            $result['results'] = $this->results;
        }

        return $result;
    }

    public function getFallbackText(): string
    {
        $lines = [$this->question];

        foreach ($this->options as $option) {
            $lines[] = "- {$option['label']}";
        }

        return implode("\n", $lines);
    }
}
