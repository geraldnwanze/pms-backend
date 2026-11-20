<?php

namespace Tests\Feature\Maintenance;

use App\Models\Building;
use App\Models\Unit;
use Illuminate\Support\Facades\Storage;
use Tests\PmsTestCase;
use Tests\Support\Images;

/** Photos through the real endpoints: properties, buildings and units store, replace and clean up their images. */
class PhotoUploadTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_a_building_stores_uploaded_photos_as_urls_not_data(): void
    {
        $res = $this->as($this->w->ownerA)->postJson('/api/v1/buildings', [
            'propertyId' => 'prop_a', 'name' => 'Block P', 'code' => 'BLD-P', 'photos' => [Images::dataUrl('jpeg'), '#aabbcc'],
        ])->assertCreated();

        $photos = Building::query()->find($res->json('data.id'))->photos;
        $this->assertCount(2, $photos);
        $this->assertStringStartsWith('/storage/buildings/', $photos[0]);
        $this->assertSame('#aabbcc', $photos[1]);
        Storage::disk('public')->assertExists('buildings/'.basename($photos[0]));
        $this->assertStringNotContainsString('base64', json_encode($photos));
    }

    public function test_a_bad_image_is_a_422_on_the_photos_field_and_nothing_is_saved(): void
    {
        $this->as($this->w->ownerA)->postJson('/api/v1/buildings', [
            'propertyId' => 'prop_a', 'name' => 'Bad block', 'code' => 'BLD-X', 'photos' => [Images::dataUrl('gif')],
        ])->assertStatus(422)->assertJsonValidationErrors('photos');

        $this->assertSame(0, Building::query()->where('name', 'Bad block')->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_replacing_photos_on_update_deletes_the_ones_removed(): void
    {
        $this->as($this->w->ownerA);
        $unit = $this->postJson('/api/v1/units', [
            'propertyId' => 'prop_a', 'buildingId' => 'bld_a', 'code' => 'P01', 'type' => 'Studio', 'status' => 'available',
            'photos' => [Images::dataUrl('png'), Images::dataUrl('jpeg')],
        ])->assertCreated()->json('data');
        [$first, $second] = $unit['photos'];

        $this->putJson("/api/v1/units/{$unit['id']}", ['photos' => [$second, Images::dataUrl('webp')]])->assertOk();

        $now = Unit::query()->find($unit['id'])->photos;
        $this->assertCount(2, $now);
        $this->assertContains($second, $now);
        Storage::disk('public')->assertMissing('units/'.basename($first));
        Storage::disk('public')->assertExists('units/'.basename($second));
    }

    public function test_an_update_without_photos_leaves_them_alone(): void
    {
        $this->as($this->w->ownerA);
        $bld = $this->postJson('/api/v1/buildings', ['propertyId' => 'prop_a', 'name' => 'Keep', 'code' => 'BLD-K', 'photos' => [Images::dataUrl('png')]])->json('data');

        $this->putJson("/api/v1/buildings/{$bld['id']}", ['name' => 'Kept'])->assertOk();

        Storage::disk('public')->assertExists('buildings/'.basename($bld['photos'][0]));
    }

    public function test_a_client_cannot_point_a_record_at_files_it_does_not_own(): void
    {
        $this->as($this->w->ownerA);
        $bld = $this->postJson('/api/v1/buildings', [
            'propertyId' => 'prop_a', 'name' => 'Sneaky', 'code' => 'BLD-S',
            'photos' => ['/storage/properties/someone-elses.jpg', '/storage/buildings/../../.env', 'https://evil.example/x.jpg'],
        ])->assertCreated()->json('data');

        $this->assertSame([], $bld['photos']);
    }

    public function test_deleting_a_record_removes_its_files(): void
    {
        $bld = $this->as($this->w->admin)->postJson('/api/v1/buildings', [
            'propertyId' => 'prop_a', 'name' => 'Doomed', 'code' => 'BLD-D', 'photos' => [Images::dataUrl('png')],
        ])->json('data');
        $file = 'buildings/'.basename($bld['photos'][0]);
        Storage::disk('public')->assertExists($file);

        $this->deleteJson("/api/v1/buildings/{$bld['id']}")->assertOk();

        Storage::disk('public')->assertMissing($file);
    }

    public function test_photos_on_a_property_land_in_the_properties_folder(): void
    {
        $id = $this->as($this->w->stranger)->postJson('/api/v1/properties', [
            'name' => 'Pic House', 'type' => 'residential', 'address' => '1 Road', 'city' => 'Abuja', 'state' => 'Abuja Federal Capital Territory',
            'country' => 'Nigeria', 'photos' => [Images::dataUrl('jpeg')],
        ])->assertCreated()->json('data.photos.0');

        $this->assertStringStartsWith('/storage/properties/', $id);
    }
}
