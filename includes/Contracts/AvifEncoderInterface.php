<?php

declare(strict_types=1);

namespace Ddegner\AvifLocalSupport\Contracts;

use Ddegner\AvifLocalSupport\DTO\AvifSettings;
use Ddegner\AvifLocalSupport\DTO\ConversionResult;

defined('ABSPATH') || exit;

interface AvifEncoderInterface
{
    /**
     * Convert a source image to AVIF.
     *
     * @param string $source Path to source image.
     * @param string $destination Path to destination AVIF.
     * @param AvifSettings $settings Conversion settings.
     * @param array|null $dimensions Optional target dimensions ['width' => int, 'height' => int].
     */
    public function convert(string $source, string $destination, AvifSettings $settings, ?array $dimensions = null): ConversionResult;

    /**
     * Check if this encoder is available on the current system.
     */
    public function isAvailable(): bool;

    /**
     * Get the human-readable name of this encoder.
     */
    public function getName(): string;
}
