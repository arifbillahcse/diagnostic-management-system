<?php

declare(strict_types=1);

namespace Modules\Messaging\Drivers;

final class SendResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $reference,
        public readonly ?string $error,
    ) {}

    public static function sent(?string $reference = null): self
    {
        return new self(true, $reference, null);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }
}
