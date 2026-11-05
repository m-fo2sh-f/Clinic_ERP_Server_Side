<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClinicSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Clinic\PrescriptionTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PrescriptionTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Branch $branch;

    protected User $owner;

    protected User $doctor;

    protected User $receptionist;

    protected string $domain;

    protected function setUp(): void
    {
        parent::setUp();

        if (function_exists('tenancy') && tenancy()->initialized) {
            tenancy()->end();
        }

        Storage::fake('public');
        Storage::fake('public_shared');

        $tenantId = 'test-rx-'.Str::random(6);
        $this->domain = $tenantId.'.test';

        $this->tenant = Tenant::create(['id' => $tenantId]);
        $this->tenant->domains()->create(['domain' => $this->domain]);

        tenancy()->initialize($this->tenant);
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($this->tenant->id);
        }

        $ownerRole = Role::firstOrCreate(['name' => 'clinic_owner', 'guard_name' => 'web']);
        $doctorRole = Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
        $receptionistRole = Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);

        $this->branch = Branch::create([
            'name' => 'Main Test Branch',
            'address' => 'Test Address',
            'phone' => '01000000000',
            'is_active' => true,
        ]);

        $this->owner = User::create([
            'name' => 'Clinic Owner',
            'email' => 'owner@'.$this->domain,
            'password' => bcrypt('12345678'),
        ]);
        $this->owner->assignRole($ownerRole);
        $this->owner->branches()->attach($this->branch->id);

        $this->doctor = User::create([
            'name' => 'Doctor User',
            'email' => 'doctor@'.$this->domain,
            'password' => bcrypt('12345678'),
        ]);
        $this->doctor->assignRole($doctorRole);
        $this->doctor->branches()->attach($this->branch->id);

        $this->receptionist = User::create([
            'name' => 'Receptionist User',
            'email' => 'reception@'.$this->domain,
            'password' => bcrypt('12345678'),
        ]);
        $this->receptionist->assignRole($receptionistRole);
        $this->receptionist->branches()->attach($this->branch->id);

        ClinicSetting::updateOrCreate(
            ['branch_id' => $this->branch->id],
            [
                'queue_strategy' => 'hybrid',
                'avg_appointment_duration' => 15,
                'prescription_template' => PrescriptionTemplateService::DEFAULT_TEMPLATE,
            ]
        );

        tenancy()->end();
    }

    protected function tearDown(): void
    {
        if (function_exists('tenancy') && tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * 1. test_can_get_default_prescription_template: owner and doctor can fetch settings.
     */
    public function test_can_get_default_prescription_template(): void
    {
        // 1. Owner can fetch settings
        $ownerResponse = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template");

        $ownerResponse->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.mode', 'builtin')
            ->assertJsonPath('data.paper_size', 'A5')
            ->assertJsonPath('data.template_id', 'egyptian_clinic')
            ->assertJsonPath('data.is_doctor_override', false)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'mode',
                    'paper_size',
                    'margins' => ['top_cm', 'bottom_cm', 'left_cm', 'right_cm'],
                    'template_id',
                    'primary_color',
                    'show_diagnosis',
                    'show_vitals',
                    'show_qr',
                    'show_rx_code',
                    'auto_print',
                    'laser_contrast_opt',
                    'header_data',
                    'footer_data',
                    'image_path',
                    'image_url',
                    'is_doctor_override',
                ],
            ]);

        // 2. Doctor can fetch settings
        $doctorResponse = $this->actingAs($this->doctor, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template");

        $doctorResponse->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.mode', 'builtin')
            ->assertJsonPath('data.paper_size', 'A5')
            ->assertJsonPath('data.template_id', 'egyptian_clinic')
            ->assertJsonPath('data.is_doctor_override', false);
    }

    /**
     * 2. test_can_update_prescription_template_settings: update margins, paper size (A5/A4), toggles, colors.
     */
    public function test_can_update_prescription_template_settings(): void
    {
        $updateData = [
            'mode' => 'letterhead',
            'paper_size' => 'A4',
            'margins' => [
                'top_cm' => 4.5,
                'bottom_cm' => 3.2,
                'left_cm' => 2.1,
                'right_cm' => 2.1,
            ],
            'template_id' => 'pediatrics',
            'primary_color' => '#10b981',
            'show_diagnosis' => false,
            'show_vitals' => true,
            'show_qr' => false,
            'show_rx_code' => true,
            'auto_print' => true,
            'laser_contrast_opt' => false,
            'header_data' => [
                'title' => 'عيادات النخبة التخصصية',
                'specialty' => 'طب الأطفال وحديثي الولادة',
                'degrees' => 'استشاري الأطفال',
            ],
        ];

        $response = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->putJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template", $updateData);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.mode', 'letterhead')
            ->assertJsonPath('data.paper_size', 'A4')
            ->assertJsonPath('data.margins.top_cm', 4.5)
            ->assertJsonPath('data.margins.bottom_cm', 3.2)
            ->assertJsonPath('data.margins.left_cm', 2.1)
            ->assertJsonPath('data.margins.right_cm', 2.1)
            ->assertJsonPath('data.primary_color', '#10b981')
            ->assertJsonPath('data.show_diagnosis', false)
            ->assertJsonPath('data.show_vitals', true)
            ->assertJsonPath('data.show_qr', false)
            ->assertJsonPath('data.show_rx_code', true)
            ->assertJsonPath('data.auto_print', true)
            ->assertJsonPath('data.laser_contrast_opt', false);

        // Verify database persistence
        tenancy()->initialize($this->tenant);
        $setting = ClinicSetting::where('branch_id', $this->branch->id)->first();
        $this->assertNotNull($setting);
        $this->assertEquals('letterhead', $setting->prescription_template['mode']);
        $this->assertEquals('A4', $setting->prescription_template['paper_size']);
        $this->assertEquals(4.5, $setting->prescription_template['margins']['top_cm']);
        $this->assertEquals(3.2, $setting->prescription_template['margins']['bottom_cm']);
        $this->assertEquals('#10b981', $setting->prescription_template['primary_color']);
        $this->assertFalse($setting->prescription_template['show_diagnosis']);
        $this->assertTrue($setting->prescription_template['show_vitals']);
        $this->assertFalse($setting->prescription_template['show_qr']);
        $this->assertTrue($setting->prescription_template['show_rx_code']);
        $this->assertTrue($setting->prescription_template['auto_print']);
        $this->assertFalse($setting->prescription_template['laser_contrast_opt']);
        tenancy()->end();

        // Also verify switching paper size back to A5
        $switchPaperResponse = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->putJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template", [
                'mode' => 'builtin',
                'paper_size' => 'A5',
            ]);

        $switchPaperResponse->assertOk()
            ->assertJsonPath('data.paper_size', 'A5')
            ->assertJsonPath('data.mode', 'builtin');
    }

    /**
     * 3. test_can_upload_valid_prescription_template_image:
     * upload valid PNG/JPG/WebP and assert file exists on disk `public_shared` at `tenants/{tenant_id}/templates/{uuid}.{ext}`.
     */
    public function test_can_upload_valid_prescription_template_image(): void
    {
        // 1. Valid PNG upload
        $pngImage = UploadedFile::fake()->image('letterhead.png', 1200, 800);

        $pngResponse = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $pngImage,
            ]);

        $pngResponse->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.mode', 'custom_image');

        $pngPath = $pngResponse->json('data.image_path');
        $this->assertNotNull($pngPath);
        $expectedPngPattern = "#^tenants/{$this->tenant->id}/templates/[a-f0-9\-]+\.png$#";
        $this->assertMatchesRegularExpression($expectedPngPattern, $pngPath);
        $this->assertTrue(Storage::disk('public_shared')->exists($pngPath));

        // 2. Valid JPG upload
        $jpgImage = UploadedFile::fake()->image('letterhead.jpg', 1200, 800);

        $jpgResponse = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $jpgImage,
            ]);

        $jpgResponse->assertOk()
            ->assertJsonPath('status', 'success');

        $jpgPath = $jpgResponse->json('data.image_path');
        $this->assertNotNull($jpgPath);
        $expectedJpgPattern = "#^tenants/{$this->tenant->id}/templates/[a-f0-9\-]+\.jpg$#";
        $this->assertMatchesRegularExpression($expectedJpgPattern, $jpgPath);
        $this->assertTrue(Storage::disk('public_shared')->exists($jpgPath));

        // 3. Valid WebP upload
        $webpImage = UploadedFile::fake()->image('letterhead.webp', 1200, 800);

        $webpResponse = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $webpImage,
            ]);

        $webpResponse->assertOk()
            ->assertJsonPath('status', 'success');

        $webpPath = $webpResponse->json('data.image_path');
        $this->assertNotNull($webpPath);
        $expectedWebpPattern = "#^tenants/{$this->tenant->id}/templates/[a-f0-9\-]+\.webp$#";
        $this->assertMatchesRegularExpression($expectedWebpPattern, $webpPath);
        $this->assertTrue(Storage::disk('public_shared')->exists($webpPath));
    }

    /**
     * 4. test_replaces_old_image_and_deletes_it_from_disk_when_uploading_new_image:
     * atomic swap: uploading a second image deletes the old file from disk so zero orphan files remain.
     */
    public function test_replaces_old_image_and_deletes_it_from_disk_when_uploading_new_image(): void
    {
        $firstImage = UploadedFile::fake()->image('initial_header.png', 1200, 800);

        $firstResponse = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $firstImage,
            ]);

        $firstResponse->assertOk();
        $firstPath = $firstResponse->json('data.image_path');
        $this->assertNotNull($firstPath);
        $this->assertTrue(Storage::disk('public_shared')->exists($firstPath));

        // Upload second replacement image
        $secondImage = UploadedFile::fake()->image('replacement_header.png', 1200, 800);

        $secondResponse = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $secondImage,
            ]);

        $secondResponse->assertOk();
        $secondPath = $secondResponse->json('data.image_path');
        $this->assertNotNull($secondPath);
        $this->assertNotEquals($firstPath, $secondPath);

        // Atomic swap verification: old file is deleted from disk, new file exists
        $this->assertFalse(Storage::disk('public_shared')->exists($firstPath));
        $this->assertTrue(Storage::disk('public_shared')->exists($secondPath));

        // DB reflects second image path
        tenancy()->initialize($this->tenant);
        $setting = ClinicSetting::where('branch_id', $this->branch->id)->first();
        $this->assertEquals($secondPath, $setting->prescription_template['image_path']);
        tenancy()->end();
    }

    /**
     * 5. test_deletes_image_file_and_clears_database_path_on_delete_request:
     * calling DELETE removes the file from disk and clears the `image_path` in DB.
     */
    public function test_deletes_image_file_and_clears_database_path_on_delete_request(): void
    {
        $image = UploadedFile::fake()->image('to_be_deleted.png', 1200, 800);

        $uploadResponse = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $image,
            ]);

        $uploadResponse->assertOk();
        $storedPath = $uploadResponse->json('data.image_path');
        $this->assertNotNull($storedPath);
        $this->assertTrue(Storage::disk('public_shared')->exists($storedPath));

        // Call DELETE endpoint
        $deleteResponse = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->deleteJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image");

        $deleteResponse->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.image_path', null);

        // Assert file removed from disk
        $this->assertFalse(Storage::disk('public_shared')->exists($storedPath));

        // Assert DB clears image_path
        tenancy()->initialize($this->tenant);
        $setting = ClinicSetting::where('branch_id', $this->branch->id)->first();
        $this->assertNull($setting->prescription_template['image_path']);
        tenancy()->end();
    }

    /**
     * 6. test_rejects_svg_and_malicious_files:
     * SVG, PDF, script-in-image files are rejected with HTTP 422 Unprocessable Content.
     */
    public function test_rejects_svg_and_malicious_files(): void
    {
        // 1. Reject SVG
        $svgFile = UploadedFile::fake()->create('vector.svg', 50, 'image/svg+xml');

        $svgResponse = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $svgFile,
            ]);

        $svgResponse->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey('image', $svgResponse->json('details') ?? []);

        // 2. Reject PDF
        $pdfFile = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

        $pdfResponse = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $pdfFile,
            ]);

        $pdfResponse->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey('image', $pdfResponse->json('details') ?? []);

        // 3. Reject script-in-image / Polyglot file (valid PNG with embedded PHP / script)
        $validImage = UploadedFile::fake()->image('exploit.png', 400, 400);
        $tempPath = tempnam(sys_get_temp_dir(), 'rx_exploit_');
        $validContent = file_get_contents($validImage->getRealPath());
        file_put_contents($tempPath, $validContent.'<?php phpinfo(); ?>');

        $polyglotFile = new UploadedFile($tempPath, 'exploit.png', 'image/png', null, true);

        $polyglotResponse = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $polyglotFile,
            ]);

        $polyglotResponse->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey('image', $polyglotResponse->json('details') ?? []);

        @unlink($tempPath);

        // 4. Reject HTML script disguised as image
        $tempHtmlPath = tempnam(sys_get_temp_dir(), 'rx_html_');
        file_put_contents($tempHtmlPath, '<html><script>alert(1)</script></html>');
        $htmlFile = new UploadedFile($tempHtmlPath, 'fake_image.png', 'image/png', null, true);

        $htmlResponse = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $htmlFile,
            ]);

        $htmlResponse->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey('image', $htmlResponse->json('details') ?? []);

        @unlink($tempHtmlPath);
    }

    /**
     * 7. test_rejects_oversized_images:
     * files > 2MB rejected with HTTP 422.
     */
    public function test_rejects_oversized_images(): void
    {
        // 2049 KB exceeds the 2048 KB limit
        $oversizedImage = UploadedFile::fake()->image('oversized.png', 800, 800)->size(2049);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $oversizedImage,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey('image', $response->json('details') ?? []);
    }

    /**
     * 8. test_rejects_invalid_dimensions:
     * images smaller than 300x300 or larger than 4500x4500 rejected with HTTP 422.
     */
    public function test_rejects_invalid_dimensions(): void
    {
        // 1. Too small (< 300x300)
        $tooSmallImage = UploadedFile::fake()->image('too_small.png', 250, 250);

        $smallResponse = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $tooSmallImage,
            ]);

        $smallResponse->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey('image', $smallResponse->json('details') ?? []);

        // 2. Too large (> 4500x4500)
        $tooLargeImage = UploadedFile::fake()->image('too_large.png', 4800, 3000);

        $largeResponse = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $tooLargeImage,
            ]);

        $largeResponse->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey('image', $largeResponse->json('details') ?? []);
    }

    /**
     * 9. test_enforces_rbac_forbidding_receptionist:
     * receptionist gets HTTP 403 Forbidden on template update, upload, or delete.
     */
    public function test_enforces_rbac_forbidding_receptionist(): void
    {
        // 1. Receptionist GET -> 403 Forbidden
        $getResponse = $this->actingAs($this->receptionist, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template");

        $getResponse->assertForbidden();

        // 2. Receptionist PUT -> 403 Forbidden
        $putResponse = $this->actingAs($this->receptionist, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->putJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template", [
                'mode' => 'letterhead',
                'paper_size' => 'A5',
            ]);

        $putResponse->assertForbidden();

        // 3. Receptionist POST upload -> 403 Forbidden
        $fakeImage = UploadedFile::fake()->image('reception_test.png', 1200, 800);

        $postResponse = $this->actingAs($this->receptionist, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $fakeImage,
            ]);

        $postResponse->assertForbidden();

        // 4. Receptionist DELETE -> 403 Forbidden
        $deleteResponse = $this->actingAs($this->receptionist, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->deleteJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image");

        $deleteResponse->assertForbidden();
    }

    /**
     * 10. test_tenant_isolation_prevents_cross_tenant_access:
     * files are stored in tenant-specific folders.
     */
    public function test_tenant_isolation_prevents_cross_tenant_access(): void
    {
        // 1. Upload image for Tenant 1
        $image1 = UploadedFile::fake()->image('tenant1_letterhead.png', 1200, 800);

        $res1 = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $image1,
            ]);

        $res1->assertOk();
        $path1 = $res1->json('data.image_path');
        $this->assertNotNull($path1);
        $filename1 = basename($path1);

        // 2. Set up Tenant 2
        $tenant2Id = 'test-rx-iso-'.Str::random(6);
        $domain2 = $tenant2Id.'.test';

        $tenant2 = Tenant::create(['id' => $tenant2Id]);
        $tenant2->domains()->create(['domain' => $domain2]);

        tenancy()->initialize($tenant2);
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($tenant2->id);
        }

        $branch2 = Branch::create([
            'name' => 'Tenant 2 Branch',
            'address' => 'Tenant 2 Address',
            'phone' => '01022222222',
            'is_active' => true,
        ]);

        $owner2 = User::create([
            'name' => 'Tenant 2 Owner',
            'email' => 'owner2@'.$domain2,
            'password' => bcrypt('12345678'),
        ]);
        $owner2->assignRole(Role::where('name', 'clinic_owner')->first());
        $owner2->branches()->attach($branch2->id);

        ClinicSetting::updateOrCreate(
            ['branch_id' => $branch2->id],
            [
                'queue_strategy' => 'hybrid',
                'avg_appointment_duration' => 15,
                'prescription_template' => PrescriptionTemplateService::DEFAULT_TEMPLATE,
            ]
        );
        tenancy()->end();

        // 3. Upload image for Tenant 2
        $image2 = UploadedFile::fake()->image('tenant2_letterhead.png', 1200, 800);

        $res2 = $this->actingAs($owner2, 'sanctum')
            ->withHeader('X-Branch-ID', $branch2->id)
            ->postJson("http://{$domain2}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $image2,
            ]);

        $res2->assertOk();
        $path2 = $res2->json('data.image_path');
        $this->assertNotNull($path2);

        // 4. Assert isolation: folders are tenant-specific and distinct
        $this->assertStringStartsWith("tenants/{$this->tenant->id}/templates/", $path1);
        $this->assertStringStartsWith("tenants/{$tenant2->id}/templates/", $path2);
        $this->assertNotEquals($path1, $path2);

        $this->assertTrue(Storage::disk('public_shared')->exists($path1));
        $this->assertTrue(Storage::disk('public_shared')->exists($path2));

        // 5. Cross-tenant serve prevention: Tenant 2 cannot access or serve Tenant 1's image file
        $crossServeResponse = $this->get("http://{$domain2}/api/v1/clinic-settings/prescription-template/image/{$filename1}");
        $crossServeResponse->assertNotFound();

        // But Tenant 1 CAN serve its own image
        $tenant1ServeResponse = $this->get("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image/{$filename1}");
        $tenant1ServeResponse->assertOk();
    }
}
