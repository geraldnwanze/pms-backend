<?php

namespace Tests\Support;

/** Tiny real images (generated with GD) as raw bytes and as data URLs. */
final class Images
{
    public static function bytes(string $type = 'png', int $w = 4, int $h = 4): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        ob_start();
        match ($type) {
            'png' => imagepng($img),
            'jpeg' => imagejpeg($img),
            'webp' => imagewebp($img),
            'gif' => imagegif($img),
        };

        return (string) ob_get_clean();
    }

    public static function dataUrl(string $type = 'png', ?string $declaredAs = null): string
    {
        return 'data:image/'.($declaredAs ?? $type).';base64,'.base64_encode(self::bytes($type));
    }
}
