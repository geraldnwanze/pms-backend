<?php

namespace Tests\Unit;

use App\Support\ImageStore;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Images;
use Tests\TestCase;

class ImageStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function rejects(string $entry, string $expectedMessage, string $field = 'photos'): void
    {
        try {
            ImageStore::persistAll([$entry], 'units', $field);
            $this->fail('Expected the image to be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
            $this->assertStringContainsString($expectedMessage, $e->errors()[$field][0]);
        }
        $this->assertSame([], Storage::disk('public')->allFiles(), 'nothing may be written for a rejected image');
    }

    // ---- Accepting ----------------------------------------------------------

    public function test_jpeg_png_and_webp_are_stored_and_replaced_by_root_relative_urls(): void
    {
        foreach (['jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp'] as $type => $ext) {
            [$url] = ImageStore::persistAll([Images::dataUrl($type)], 'units');

            $this->assertMatchesRegularExpression("#^/storage/units/[a-z0-9]{24}\\.{$ext}$#", $url);
            Storage::disk('public')->assertExists('units/'.basename($url));
            $this->assertSame(Images::bytes($type), Storage::disk('public')->get('units/'.basename($url)));
        }
    }

    public function test_the_extension_comes_from_the_bytes_not_the_declared_type(): void
    {
        [$url] = ImageStore::persistAll([Images::dataUrl('png', 'jpeg')], 'units');   // PNG bytes labelled image/jpeg

        $this->assertStringEndsWith('.png', $url);
    }

    public function test_colour_hints_and_our_own_stored_urls_pass_through_untouched(): void
    {
        $out = ImageStore::persistAll(['#1e293b', '#fff', '/storage/units/abc123_-X.jpg'], 'units');

        $this->assertSame(['#1e293b', '#fff', '/storage/units/abc123_-X.jpg'], $out);
    }

    public function test_a_mixed_list_keeps_its_order_and_drops_duplicates(): void
    {
        $out = ImageStore::persistAll(['#aabbcc', Images::dataUrl('png'), '#aabbcc', '/storage/units/keep.jpg'], 'units');

        $this->assertCount(3, $out);
        $this->assertSame('#aabbcc', $out[0]);
        $this->assertStringStartsWith('/storage/units/', $out[1]);
        $this->assertSame('/storage/units/keep.jpg', $out[2]);
    }

    public function test_an_empty_list_is_fine(): void
    {
        $this->assertSame([], ImageStore::persistAll([], 'units'));
    }

    public function test_exactly_five_megabytes_is_allowed(): void
    {
        $png = Images::bytes('png');
        // pad after the image data: still a valid PNG to getimagesizefromstring, but exactly at the limit
        $padded = $png.str_repeat("\0", 5 * 1024 * 1024 - strlen($png));
        $this->assertSame(5 * 1024 * 1024, strlen($padded));

        [$url] = ImageStore::persistAll(['data:image/png;base64,'.base64_encode($padded)], 'units');

        $this->assertStringStartsWith('/storage/units/', $url);
    }

    // ---- Dropping -----------------------------------------------------------

    public function test_anything_that_is_not_ours_or_a_hint_is_silently_dropped(): void
    {
        $out = ImageStore::persistAll([
            'https://evil.example/x.jpg',          // remote URL
            '/storage/properties/other.jpg',        // a different folder
            '/storage/units/../../.env',            // traversal
            '/storage/units/nested/dir.jpg',        // sub-path
            '/storage/units/script.php',            // wrong extension
            'javascript:alert(1)',
            'plain text',
            '',
            42, null, ['nested'], true,              // not strings at all
        ], 'units');

        $this->assertSame([], $out);
    }

    // ---- Rejecting ----------------------------------------------------------

    public function test_other_image_types_are_rejected_even_when_the_bytes_are_a_real_image(): void
    {
        $this->rejects(Images::dataUrl('gif'), 'JPEG, PNG or WebP');
        $this->rejects('data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'JPEG, PNG or WebP');
        $this->rejects('data:text/html;base64,'.base64_encode('<script>alert(1)</script>'), 'JPEG, PNG or WebP');
    }

    public function test_a_gif_hidden_behind_an_image_png_label_is_rejected(): void
    {
        $this->rejects(Images::dataUrl('gif', 'png'), 'not a valid JPEG, PNG or WebP');
    }

    public function test_non_image_bytes_dressed_up_as_an_image_are_rejected(): void
    {
        foreach (['<?php system($_GET["c"]);', '%PDF-1.4 fake', 'just words', str_repeat('A', 2000)] as $payload) {
            $this->rejects('data:image/png;base64,'.base64_encode($payload), 'not a valid JPEG, PNG or WebP');
        }
    }

    public function test_corrupt_or_empty_base64_is_rejected(): void
    {
        $this->rejects('data:image/png;base64,@@@not-base64@@@', 'could not be read');
        $this->rejects('data:image/png;base64,', 'JPEG, PNG or WebP');   // nothing after the comma
        $this->rejects('data:image/png;base64,====', 'could not be read');
        $this->rejects('data:image/png,rawnotbase64', 'JPEG, PNG or WebP');
        $this->rejects('data:', 'JPEG, PNG or WebP');
    }

    public function test_more_than_five_megabytes_is_rejected(): void
    {
        $png = Images::bytes('png');
        $big = $png.str_repeat("\0", 5 * 1024 * 1024 - strlen($png) + 1);

        $this->rejects('data:image/png;base64,'.base64_encode($big), '5 MB');
    }

    public function test_one_bad_photo_rejects_the_whole_list_and_stores_nothing_from_after_it(): void
    {
        try {
            ImageStore::persistAll([Images::dataUrl('png'), 'data:image/png;base64,@@@', Images::dataUrl('jpeg')], 'units');
            $this->fail('expected a validation error');
        } catch (ValidationException) {
            // the first photo may have been written before the bad one was reached, but the third never is
            $this->assertCount(1, Storage::disk('public')->allFiles());
        }
    }

    public function test_the_error_is_reported_against_the_requested_field(): void
    {
        $this->rejects(Images::dataUrl('gif'), 'JPEG, PNG or WebP', 'images');
    }

    // ---- Deleting -----------------------------------------------------------

    public function test_removed_photos_are_deleted_and_kept_ones_are_not(): void
    {
        [$keep, $drop] = ImageStore::persistAll([Images::dataUrl('png'), Images::dataUrl('jpeg')], 'units');

        ImageStore::deleteRemoved([$keep, $drop, '#abcdef'], [$keep], 'units');

        Storage::disk('public')->assertExists('units/'.basename($keep));
        Storage::disk('public')->assertMissing('units/'.basename($drop));
    }

    public function test_deleting_never_touches_files_outside_its_own_folder(): void
    {
        Storage::disk('public')->put('properties/precious.jpg', 'x');
        Storage::disk('public')->put('secret.txt', 'x');

        ImageStore::deleteRemoved(['/storage/properties/precious.jpg', '/storage/units/../secret.txt', '/storage/secret.txt', 'https://x.test/a.jpg', 7], [], 'units');

        Storage::disk('public')->assertExists('properties/precious.jpg');
        Storage::disk('public')->assertExists('secret.txt');
    }

    public function test_deleting_a_file_that_is_already_gone_is_harmless(): void
    {
        ImageStore::deleteRemoved(['/storage/units/missing.jpg'], [], 'units');

        $this->assertTrue(true);
    }

    public function test_is_stored_url_is_strict(): void
    {
        $this->assertTrue(ImageStore::isStoredUrl('/storage/units/abc_DEF-123.webp', 'units'));
        $this->assertFalse(ImageStore::isStoredUrl('/storage/units/abc.gif', 'units'));
        $this->assertFalse(ImageStore::isStoredUrl('/storage/units/abc.jpg.php', 'units'));
        $this->assertFalse(ImageStore::isStoredUrl('/storage/units/abc.jpg', 'buildings'));
        $this->assertFalse(ImageStore::isStoredUrl('http://host/storage/units/abc.jpg', 'units'));
        $this->assertFalse(ImageStore::isStoredUrl("/storage/units/abc.jpg\n", 'units'), 'a trailing newline must not slip past the pattern');
    }
}
