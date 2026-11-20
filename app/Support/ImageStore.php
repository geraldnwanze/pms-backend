<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores user-supplied images (sent by the SPA as `data:image/...;base64,` URLs)
 * on the public disk and hands back a root-relative URL (`/storage/<dir>/<file>`),
 * so the stored value survives a change of APP_URL.
 *
 * A "photo list" is a mix of three kinds of entry:
 *   - a data URL         → decoded, verified as a real image, stored, replaced by its URL
 *   - a colour hint      → `#rrggbb` placeholder swatch (the seed data uses these); kept as-is
 *   - one of our URLs    → `/storage/<dir>/<file>` already stored; kept as-is
 * Anything else is dropped.
 */
class ImageStore
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @param  array<int,mixed>  $entries
     * @return array<int,string>
     */
    public static function persistAll(array $entries, string $dir, string $field = 'photos'): array
    {
        $out = [];

        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            if (str_starts_with($entry, 'data:')) {
                $out[] = self::storeDataUrl($entry, $dir, $field);
            } elseif (self::isColourHint($entry) || self::isStoredUrl($entry, $dir)) {
                $out[] = $entry;
            }
        }

        return array_values(array_unique($out));
    }

    /** Delete the stored files in `$previous` that no longer appear in `$kept`. */
    public static function deleteRemoved(array $previous, array $kept, string $dir): void
    {
        foreach (array_diff($previous, $kept) as $url) {
            if (is_string($url) && self::isStoredUrl($url, $dir)) {
                Storage::disk('public')->delete(Str::after($url, '/storage/'));
            }
        }
    }

    public static function isStoredUrl(string $value, string $dir): bool
    {
        return (bool) preg_match('#^/storage/'.preg_quote($dir, '#').'/[A-Za-z0-9_-]+\.(jpg|png|webp)$#D', $value);
    }

    private static function isColourHint(string $value): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{3,8}$/', $value);
    }

    private static function storeDataUrl(string $dataUrl, string $dir, string $field): string
    {
        if (! preg_match('#^data:(image/(?:jpeg|png|webp));base64,(.+)$#s', $dataUrl, $m)) {
            throw self::invalid($field, 'Photos must be JPEG, PNG or WebP images.');
        }

        $binary = base64_decode($m[2], true);

        if ($binary === false || $binary === '') {
            throw self::invalid($field, 'A photo could not be read.');
        }

        if (strlen($binary) > self::MAX_BYTES) {
            throw self::invalid($field, 'Each photo must be 5 MB or smaller.');
        }

        // Trust the bytes, not the declared type: confirm it is a real image and
        // pick the extension from what it actually is.
        $info = @getimagesizefromstring($binary);
        $extension = $info ? (self::EXTENSIONS[$info['mime']] ?? null) : null;

        if ($extension === null) {
            throw self::invalid($field, 'A photo is not a valid JPEG, PNG or WebP image.');
        }

        $name = Str::lower(Str::random(24)).'.'.$extension;
        Storage::disk('public')->put("{$dir}/{$name}", $binary);

        return "/storage/{$dir}/{$name}";
    }

    private static function invalid(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => [$message]]);
    }
}
