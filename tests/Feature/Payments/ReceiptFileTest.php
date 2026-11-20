<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\PmsTestCase;
use Tests\Support\Images;
use Tests\Support\World;

/** Proof-of-payment files: what may be uploaded, where it goes, and who may open it (and for how long). */
class ReceiptFileTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-15 09:00:00');
    }

    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
    }

    private function dataUrl(string $bytes, string $mime): string
    {
        return "data:{$mime};base64,".base64_encode($bytes);
    }

    private function submit(array $over = [], ?User $as = null)
    {
        if (! isset($over['invoiceId'])) {
            $this->w->invoice->update(['status' => 'unpaid']);
            Payment::query()->where('invoiceId', $this->w->invoice->id)->delete();   // one open payment per invoice
        }

        return $this->as($as ?? $this->w->tenantUser)->postJson('/api/v1/payments/submit', $over + [
            'invoiceId' => $this->w->invoice->id, 'type' => 'rent', 'method' => 'bank-transfer', 'amount' => 50_000,
            'paymentDate' => '2026-11-14', 'reference' => 'REF-1',
        ]);
    }

    private function linkFor(string $paymentId, ?User $as = null): ?string
    {
        return $this->as($as ?? $this->w->tenantUser)->getJson("/api/v1/payments/{$paymentId}/receipt-link")->assertOk()->json('data.url');
    }

    // ---- What can be uploaded -----------------------------------------------

    public function test_images_and_pdfs_are_stored_under_the_payments_id_with_their_real_extension(): void
    {
        $cases = ['png' => [Images::bytes('png'), 'png'], 'jpeg' => [Images::bytes('jpeg'), 'jpg'], 'webp' => [Images::bytes('webp'), 'webp'], 'pdf' => [$this->pdfBytes(), 'pdf']];

        foreach ($cases as $name => [$bytes, $ext]) {
            $id = 'pay_'.$name;
            $this->submit(['id' => $id, 'receiptDataUrl' => $this->dataUrl($bytes, 'application/octet-stream')])->assertCreated();

            $this->assertSame("receipts/{$id}.{$ext}", Payment::query()->find($id)->receiptPath, $name);
            $this->assertSame($bytes, Storage::disk('local')->get("receipts/{$id}.{$ext}"), "{$name} bytes are kept exactly");
        }
    }

    public function test_what_the_file_really_is_decides_not_what_the_upload_claims(): void
    {
        $this->submit(['id' => 'pay_mislabelled', 'receiptDataUrl' => $this->dataUrl(Images::bytes('png'), 'application/pdf')])->assertCreated();

        $this->assertSame('receipts/pay_mislabelled.png', Payment::query()->find('pay_mislabelled')->receiptPath);
    }

    public function test_anything_else_is_refused_and_nothing_is_stored_or_recorded(): void
    {
        $bad = [
            'a web page' => $this->dataUrl('<script>alert(1)</script>', 'text/html'),
            'a web page posing as a PNG' => $this->dataUrl('<html><script>alert(1)</script></html>', 'image/png'),
            'plain text' => $this->dataUrl('hello', 'text/plain'),
            'an SVG' => $this->dataUrl('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'image/svg+xml'),
            'a program' => $this->dataUrl("MZ\x90\x00", 'application/x-msdownload'),
            'an empty file' => $this->dataUrl('', 'image/png'),
            'a truncated signature' => $this->dataUrl("\x89PNG", 'image/png'),
        ];

        foreach ($bad as $what => $url) {
            $this->submit(['id' => 'pay_bad', 'receiptDataUrl' => $url])->assertStatus(422, $what)->assertJsonValidationErrors('receiptDataUrl');
            $this->assertNull(Payment::query()->find('pay_bad'), "{$what}: no payment is recorded");
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_broken_data_urls_are_refused(): void
    {
        $this->submit(['receiptDataUrl' => 'data:image/png;base64,!!!not-base64!!!'])->assertStatus(422);
        $this->submit(['receiptDataUrl' => 'data:image/png,rawbytes'])->assertStatus(422);
        $this->submit(['receiptDataUrl' => 'https://evil.test/x.png'])->assertStatus(422);
    }

    public function test_a_payment_without_a_file_is_still_fine(): void
    {
        $id = $this->submit()->assertCreated()->json('data.id');

        $this->assertNull(Payment::query()->find($id)->receiptPath);
        $this->assertNull($this->linkFor($id), 'nothing to link to');
    }

    public function test_an_oversized_file_is_refused(): void
    {
        ini_set('memory_limit', '768M');

        $this->submit(['receiptDataUrl' => $this->dataUrl(Images::bytes('png').str_repeat('a', 8 * 1024 * 1024), 'image/png')])
            ->assertStatus(422)->assertJsonValidationErrors('receiptDataUrl');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_file_just_under_the_limit_is_accepted(): void
    {
        ini_set('memory_limit', '768M');

        $this->submit(['id' => 'pay_big', 'receiptDataUrl' => $this->dataUrl(Images::bytes('png').str_repeat('a', 7 * 1024 * 1024), 'image/png')])->assertCreated();

        $this->assertTrue(Storage::disk('local')->exists('receipts/pay_big.png'));
    }

    public function test_a_payment_id_cannot_steer_the_file_somewhere_else(): void
    {
        foreach (['../../evil', '../evil', 'a/b', 'a\\b', 'with space', str_repeat('x', 65), 'dot.dot', "new\nline"] as $id) {
            $status = $this->submit(['id' => $id, 'receiptDataUrl' => $this->dataUrl(Images::bytes('png'), 'image/png')])->status();
            $this->assertSame(422, $status, 'id '.json_encode($id).' was accepted');
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, Payment::query()->where('invoiceId', $this->w->invoice->id)->count());
    }

    public function test_ordinary_ids_still_work(): void
    {
        $this->submit(['id' => 'pay_Abc-123_x', 'receiptDataUrl' => $this->dataUrl(Images::bytes('png'), 'image/png')])->assertCreated();
        $this->submit()->assertCreated();      // no id: the server makes one
    }

    // ---- Getting the file back -------------------------------------------------

    public function test_the_payer_and_the_staff_of_that_property_get_a_short_lived_signed_link(): void
    {
        $id = $this->submit(['id' => 'pay_1', 'receiptDataUrl' => $this->dataUrl(Images::bytes('png'), 'image/png')])->assertCreated()->json('data.id');

        foreach (['tenantUser', 'financeA', 'managerA', 'ownerA', 'admin'] as $who) {
            $url = $this->linkFor($id, $this->w->{$who});
            $this->assertNotNull($url, $who);
            $this->assertStringContainsString('signature=', $url);
            $this->assertStringContainsString('expires=', $url);
        }
    }

    public function test_nobody_else_can_ask_for_a_link(): void
    {
        $id = $this->submit(['id' => 'pay_1', 'receiptDataUrl' => $this->dataUrl(Images::bytes('png'), 'image/png')])->assertCreated()->json('data.id');
        $otherTenant = World::user('tenant', 'bee@tenant.test');
        World::member($this->w->pB, $otherTenant, 'tenant', 'ten_bee');

        foreach ([$this->w->ownerB, $this->w->stranger, $otherTenant] as $who) {
            $this->as($who)->getJson("/api/v1/payments/{$id}/receipt-link")->assertStatus(403);
        }
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/payments/{$id}/receipt-link")->assertUnauthorized();
        $this->as($this->w->managerA)->getJson('/api/v1/payments/pay_nope/receipt-link')->assertNotFound();
    }

    public function test_the_link_needs_no_login_and_serves_the_file_safely(): void
    {
        $bytes = Images::bytes('png');
        $id = $this->submit(['id' => 'pay_1', 'receiptFileName' => 'proof.png', 'receiptDataUrl' => $this->dataUrl($bytes, 'image/png')])->assertCreated()->json('data.id');
        $url = $this->linkFor($id);

        $this->app['auth']->forgetGuards();
        $res = $this->get($url);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', $res->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $res->headers->get('Content-Security-Policy'));
        $this->assertSame($bytes, $res->streamedContent());
    }

    public function test_pdfs_are_served_as_pdfs(): void
    {
        $id = $this->submit(['id' => 'pay_1', 'receiptDataUrl' => $this->dataUrl($this->pdfBytes(), 'application/pdf')])->assertCreated()->json('data.id');

        $this->assertSame('application/pdf', $this->get($this->linkFor($id))->headers->get('Content-Type'));
    }

    public function test_an_old_file_of_an_unknown_type_is_downloaded_never_displayed(): void
    {
        // earlier versions stored anything as ".bin" and served it inline
        $id = $this->submit(['id' => 'pay_old'])->assertCreated()->json('data.id');
        Storage::disk('local')->put('receipts/pay_old.bin', '<script>alert(1)</script>');
        Payment::query()->find($id)->update(['receiptPath' => 'receipts/pay_old.bin']);

        $res = $this->get($this->linkFor($id));

        $this->assertSame('application/octet-stream', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', $res->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
    }

    public function test_the_link_expires_after_fifteen_minutes(): void
    {
        $id = $this->submit(['id' => 'pay_1', 'receiptDataUrl' => $this->dataUrl(Images::bytes('png'), 'image/png')])->assertCreated()->json('data.id');
        $url = $this->linkFor($id);

        Carbon::setTestNow(now()->addMinutes(14));
        $this->assertSame(200, $this->get($url)->getStatusCode());

        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertSame(403, $this->get($url)->getStatusCode());
    }

    public function test_a_tampered_or_borrowed_link_is_refused(): void
    {
        $a = $this->submit(['id' => 'pay_a', 'receiptDataUrl' => $this->dataUrl(Images::bytes('png'), 'image/png')])->assertCreated()->json('data.id');
        $second = $this->w->invoice(['id' => 'inv_two']);
        $b = $this->submit(['id' => 'pay_b', 'invoiceId' => $second->id, 'receiptDataUrl' => $this->dataUrl(Images::bytes('jpeg'), 'image/jpeg')])->assertCreated()->json('data.id');
        $url = $this->linkFor($a);

        $this->assertSame(403, $this->get(str_replace('pay_a', 'pay_b', $url))->getStatusCode(), 'a link for one payment opens another');
        $this->assertSame(403, $this->get(preg_replace('/signature=[^&]+/', 'signature=forged', $url))->getStatusCode());
        $this->assertSame(403, $this->get(preg_replace('/&?signature=[^&]+/', '', $url))->getStatusCode(), 'no signature');
        $this->assertSame(403, $this->get(preg_replace('/expires=\d+/', 'expires=9999999999', $url))->getStatusCode(), 'extended expiry');
        $this->assertSame(403, $this->get("/api/v1/payments/{$b}/receipt")->getStatusCode(), 'the bare URL');
    }

    public function test_a_valid_signature_for_a_payment_with_no_file_is_a_404(): void
    {
        $id = $this->submit(['id' => 'pay_none'])->assertCreated()->json('data.id');
        $url = URL::temporarySignedRoute('v1.payments.receipt', now()->addMinutes(5), ['id' => $id]);

        $this->assertSame(404, $this->get($url)->getStatusCode());
    }
}
