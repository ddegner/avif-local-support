<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport\DTO;

defined('ABSPATH') || exit;

/**
 * Standardized result from an encoder.
 */
readonly class ConversionResult
{
    public function __construct(
        public bool $success,
        public ?string $error = null,
        public ?string $suggestion = null
    ) {
    }

    public static function success(): self
    {
        return new self(true);
    }

    public static function failure(string $error, ?string $suggestion = null): self
    {
        return new self(false, $error, $suggestion);
    }
}
