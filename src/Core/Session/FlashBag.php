<?php

declare(strict_types=1);

namespace N3\Core\Session;

final readonly class FlashBag
{
    public function __construct(private SessionStore $session)
    {
    }

    public function set(string $type, string $message): void
    {
        $this->session->put('_flash', ['type' => $type, 'message' => $message]);
    }

    /** @return array{type: string, message: string}|null */
    public function pull(): ?array
    {
        $flash = $this->session->get('_flash');
        $this->session->remove('_flash');

        return is_array($flash) && isset($flash['type'], $flash['message']) ? $flash : null;
    }
}
