<?php

declare(strict_types=1);

namespace Vsh\Core\Push;

/**
 * Message push : titre et texte affichés sur l'écran verrouillé (jamais de donnée médicale),
 * données techniques permettant à l'application d'ouvrir l'écran concerné.
 */
final class PushMessage
{
    /** @var string */
    private $title;

    /** @var string */
    private $body;

    /** @var array<string,string> */
    private $data;

    /**
     * @param array<string,string> $data
     */
    public function __construct(string $title, string $body, array $data = [])
    {
        $this->title = $title;
        $this->body = $body;
        $this->data = $data;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @return array<string,string>
     */
    public function data(): array
    {
        return $this->data;
    }
}
