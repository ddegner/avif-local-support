<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport\DTO;

defined('ABSPATH') || exit;

/**
 * Standardized result from an encoder.
 * Note: readonly keyword removed for WordPress.org SVN compatibility.
 */
final class ConversionResult
{
    public bool $success;
    public ?string $error;
    public ?string $suggestion;

    public function __construct(
        bool $success,
        ?string $error = null,
        ?string $suggestion = null
    ) {
        $this->success = $success;
        $this->error = $error;
        $this->suggestion = $suggestion;
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
