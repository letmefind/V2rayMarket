<?php

namespace App\Actions;

final class ApprovePendingOrderResult
{
    public function __construct(
        public bool $success,
        public string $title,
        public ?string $body = null,
        public ?string $deferralKind = null,
    ) {}

    public static function ok(string $title, ?string $body = null, ?string $deferralKind = null): self
    {
        return new self(true, $title, $body, $deferralKind);
    }

    public static function fail(string $title, ?string $body = null): self
    {
        return new self(false, $title, $body, null);
    }
}
