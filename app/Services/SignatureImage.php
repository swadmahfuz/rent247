<?php

namespace App\Services;

use GdImage;

class SignatureImage
{
    /**
     * Height every stored signature is normalised to, so invoice PDFs can
     * render them at a fixed size regardless of how the file was scanned.
     */
    private const TARGET_HEIGHT = 120;

    private const MAX_WIDTH = 420;

    /** Pixels lighter than this are treated as paper rather than ink. */
    private const INK_LUMINANCE = 225;

    public function normalize(string $absolutePath): bool
    {
        if (! function_exists('imagecreatefromstring')) {
            return false;
        }

        $contents = @file_get_contents($absolutePath);
        if ($contents === false) {
            return false;
        }

        $source = @imagecreatefromstring($contents);
        if (! $source) {
            return false;
        }

        $trimmed = $this->trim($source);
        $normalized = $this->scale($trimmed);

        imagealphablending($normalized, false);
        imagesavealpha($normalized, true);
        $written = imagepng($normalized, $absolutePath);

        imagedestroy($source);
        if ($trimmed !== $source) {
            imagedestroy($trimmed);
        }
        if ($normalized !== $trimmed) {
            imagedestroy($normalized);
        }

        return $written;
    }

    private function trim(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $left = $width;
        $top = $height;
        $right = -1;
        $bottom = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if (! $this->isInk($image, $x, $y)) {
                    continue;
                }
                $left = min($left, $x);
                $right = max($right, $x);
                $top = min($top, $y);
                $bottom = max($bottom, $y);
            }
        }

        if ($right < $left || $bottom < $top) {
            return $image;
        }

        $padX = max(1, (int) round(($right - $left + 1) * 0.02));
        $padY = max(1, (int) round(($bottom - $top + 1) * 0.06));

        $crop = [
            'x' => max(0, $left - $padX),
            'y' => max(0, $top - $padY),
            'width' => min($width, $right + $padX + 1) - max(0, $left - $padX),
            'height' => min($height, $bottom + $padY + 1) - max(0, $top - $padY),
        ];

        return imagecrop($image, $crop) ?: $image;
    }

    private function scale(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($height < 1) {
            return $image;
        }

        $targetHeight = self::TARGET_HEIGHT;
        $targetWidth = max(1, (int) round($width * ($targetHeight / $height)));

        if ($targetWidth > self::MAX_WIDTH) {
            $targetWidth = self::MAX_WIDTH;
            $targetHeight = max(1, (int) round($height * (self::MAX_WIDTH / $width)));
        }

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $canvas;
    }

    private function isInk(GdImage $image, int $x, int $y): bool
    {
        $color = imagecolorat($image, $x, $y);
        $alpha = ($color >> 24) & 0x7F;

        if ($alpha > 96) {
            return false;
        }

        $red = ($color >> 16) & 0xFF;
        $green = ($color >> 8) & 0xFF;
        $blue = $color & 0xFF;
        $luminance = 0.299 * $red + 0.587 * $green + 0.114 * $blue;

        return $luminance < self::INK_LUMINANCE;
    }
}
