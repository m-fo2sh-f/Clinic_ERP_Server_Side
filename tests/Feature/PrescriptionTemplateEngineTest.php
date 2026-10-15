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

class PrescriptionTemplateEngineTest extends TestCase
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

        Storage::fake('public');

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

    public function test_can_get_default_prescription_template(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template");

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.mode', 'builtin')
            ->assertJsonPath('data.paper_size', 'A5')
            ->assertJsonPath('data.template_id', 'egyptian_clinic')
            ->assertJsonPath('data.is_doctor_override', false);
    }

    public function test_receptionist_cannot_access_prescription_template(): void
    {
        $response = $this->actingAs($this->receptionist, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->getJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template");

        $response->assertForbidden();
    }

    public function test_clinic_owner_can_update_branch_prescription_template(): void
    {
        $updateData = [
            'mode' => 'letterhead',
            'paper_size' => 'A4',
            'margins' => [
                'top_cm' => 5.0,
                'bottom_cm' => 3.5,
                'left_cm' => 2.0,
                'right_cm' => 2.0,
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
                'title' => 'عيادات المستقبل التخصصية',
                'specialty' => 'طب الأطفال وحديثي الولادة',
                'degrees' => 'استشاري الأطفال',
                'phones' => ['01099998888'],
                'address' => 'الإسكندرية - سموحة',
            ],
        ];

        $response = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->putJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template", $updateData);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.mode', 'letterhead')
            ->assertJsonPath('data.paper_size', 'A4')
            ->assertJsonPath('data.template_id', 'pediatrics')
            ->assertJsonPath('data.primary_color', '#10b981')
            ->assertJsonPath('data.show_diagnosis', false)
            ->assertJsonPath('data.auto_print', true)
            ->assertJsonPath('data.laser_contrast_opt', false)
            ->assertJsonPath('data.is_doctor_override', false);

        tenancy()->initialize($this->tenant);
        $setting = ClinicSetting::where('branch_id', $this->branch->id)->first();
        $this->assertEquals('letterhead', $setting->prescription_template['mode']);
        $this->assertEquals('pediatrics', $setting->prescription_template['template_id']);
        tenancy()->end();
    }

    public function test_validation_fails_for_invalid_template_mode(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->putJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template", [
                'mode' => 'invalid_mode',
                'paper_size' => 'A3',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['details' => ['mode', 'paper_size']]);
    }

    public function test_doctor_can_override_prescription_template(): void
    {
        $doctorData = [
            'mode' => 'builtin',
            'paper_size' => 'A5',
            'template_id' => 'cardiology',
            'primary_color' => '#ef4444',
            'show_diagnosis' => true,
            'show_vitals' => true,
            'show_qr' => true,
            'show_rx_code' => true,
            'auto_print' => false,
            'laser_contrast_opt' => true,
            'doctor_override' => true,
            'header_data' => [
                'title' => 'عيادة القلب التخصصية',
                'specialty' => 'أمراض القلب والأوعية الدموية',
            ],
        ];

        $response = $this->actingAs($this->doctor, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->putJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template", $doctorData);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.template_id', 'cardiology')
            ->assertJsonPath('data.primary_color', '#ef4444')
            ->assertJsonPath('data.is_doctor_override', true);

        // Verify that the branch setting is NOT modified
        tenancy()->initialize($this->tenant);
        $setting = ClinicSetting::where('branch_id', $this->branch->id)->first();
        $this->assertEquals('egyptian_clinic', $setting->prescription_template['template_id']);

        // But the doctor record HAS the override
        $doctorUser = User::find($this->doctor->id);
        $this->assertNotNull($doctorUser->prescription_template);
        $this->assertEquals('cardiology', $doctorUser->prescription_template['template_id']);
        tenancy()->end();
    }

    public function test_doctor_can_revert_override_to_branch_template(): void
    {
        // First set doctor override
        tenancy()->initialize($this->tenant);
        $this->doctor->update(['prescription_template' => ['template_id' => 'custom_doc', 'mode' => 'builtin']]);
        tenancy()->end();

        $response = $this->actingAs($this->doctor, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->putJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template", [
                'mode' => 'builtin',
                'paper_size' => 'A5',
                'doctor_override' => false,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.is_doctor_override', false)
            ->assertJsonPath('data.template_id', 'egyptian_clinic');

        tenancy()->initialize($this->tenant);
        $this->assertNull(User::find($this->doctor->id)->prescription_template);
        tenancy()->end();
    }

    public function test_can_upload_template_image_with_uuid_filename(): void
    {
        $fakeImage = UploadedFile::fake()->image('my_letterhead.png', 800, 1200);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $fakeImage,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.mode', 'custom_image');

        $imagePath = $response->json('data.image_path');
        $imageUrl = $response->json('data.image_url');

        $this->assertNotNull($imagePath);
        $this->assertStringStartsWith('templates/', $imagePath);
        $this->assertStringContainsString('/api/v1/clinic-settings/prescription-template/image/', $imageUrl);

        // Verify file stored in public disk
        tenancy()->initialize($this->tenant);
        $this->assertTrue(Storage::disk('public')->exists($imagePath));
        tenancy()->end();
    }

    public function test_upload_image_rejects_non_image_or_oversized_files(): void
    {
        $textFile = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $textFile,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey('image', $response->json('details'));

        // Oversized file > 2MB
        $largeImage = UploadedFile::fake()->image('huge.png')->size(3000);

        $responseLarge = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $largeImage,
            ]);

        $responseLarge->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey('image', $responseLarge->json('details'));
    }

    public function test_garbage_collection_deletes_old_image_on_new_upload(): void
    {
        $firstImage = UploadedFile::fake()->image('first.png', 800, 1200);
        $res1 = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $firstImage,
            ]);
        $firstPath = $res1->json('data.image_path');
        $this->assertNotNull($firstPath);

        tenancy()->initialize($this->tenant);
        $this->assertTrue(Storage::disk('public')->exists($firstPath));
        tenancy()->end();

        // Upload second image
        $secondImage = UploadedFile::fake()->image('second.png', 800, 1200);
        $res2 = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $secondImage,
            ]);
        $secondPath = $res2->json('data.image_path');
        $this->assertNotNull($secondPath);

        $this->assertNotEquals($firstPath, $secondPath);

        tenancy()->initialize($this->tenant);
        // Old image must have been deleted
        $this->assertFalse(Storage::disk('public')->exists($firstPath));
        // New image exists
        $this->assertTrue(Storage::disk('public')->exists($secondPath));
        tenancy()->end();
    }

    public function test_can_delete_template_image(): void
    {
        $image = UploadedFile::fake()->image('delete_me.png', 800, 1200);
        $res = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $image,
            ]);
        $path = $res->json('data.image_path');
        $this->assertNotNull($path);

        $deleteRes = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->deleteJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image");

        $deleteRes->assertOk()
            ->assertJsonPath('data.image_path', null);

        tenancy()->initialize($this->tenant);
        $this->assertFalse(Storage::disk('public')->exists($path));
        tenancy()->end();
    }

    public function test_serves_template_image_with_cors_and_content_type(): void
    {
        $image = UploadedFile::fake()->image('serve.png', 800, 1200);
        $res = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Branch-ID', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image", [
                'image' => $image,
            ]);

        $imagePath = $res->json('data.image_path');
        $this->assertNotNull($imagePath);
        $filename = basename($imagePath);

        // Access via public serve route within tenant domain (no bearer token required for iFrame/print)
        $serveRes = $this->get("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image/{$filename}");

        $serveRes->assertOk();
        $this->assertStringContainsString('image/png', $serveRes->headers->get('Content-Type'));
        $this->assertEquals('*', $serveRes->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_serve_image_returns_404_for_non_existent_file(): void
    {
        $serveRes = $this->get("http://{$this->domain}/api/v1/clinic-settings/prescription-template/image/non-existent.png");

        $serveRes->assertNotFound();
    }
}
