<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\Appointment;
use App\Models\Tenant;
use App\Services\Clinic\AppointmentService;
use App\Http\Requests\Api\V1\Appointment\StoreAppointmentRequest;
use App\Http\Requests\Api\V1\Appointment\UpdateAppointmentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AppointmentDateTimeContractTest extends TestCase
{
    use RefreshDatabase;

    protected AppointmentService $appointmentService;
    protected Branch $branch;
    protected ?Tenant $tenant = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (class_exists(Tenant::class)) {
            $this->tenant = Tenant::create(['id' => 'tenant-' . Str::random(8)]);
            tenancy()->initialize($this->tenant);
        }

        $this->appointmentService = app(AppointmentService::class);
        $this->branch             = Branch::factory()->create();
    }

    // =========================================================================
    // Helper: Simulate prepareForValidation() on StoreAppointmentRequest
    // =========================================================================

    /**
     * Simulates the prepareForValidation normalization pipeline for a given
     * appointment_time input string and returns the normalized value.
     */
    private function normalizeViaFormRequest(string $inputDateTime): ?string
    {
        // Create a mock request with the input data
        $request = StoreAppointmentRequest::create('/api/v1/appointments', 'POST', [
            'appointment_time' => $inputDateTime,
            'branch_id'        => $this->branch->id,
            'type'             => 'check_up',
            'status'           => 'booking',
            'patient'          => [
                'name'  => 'Test Patient',
                'phone' => '01000000000',
            ],
        ]);

        // Manually invoke prepareForValidation
        $method = new \ReflectionMethod(StoreAppointmentRequest::class, 'prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);

        return $request->input('appointment_time');
    }

    // =========================================================================
    // A. NORMALIZATION UNIT TESTS (prepareForValidation)
    // =========================================================================

    /** @test */
    public function test_normalizes_iso8601_with_timezone_offset_to_sql_datetime(): void
    {
        $result = $this->normalizeViaFormRequest('2026-09-15T14:30:00.000Z');

        $this->assertNotNull($result);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result);
    }

    /** @test */
    public function test_normalizes_html5_datetime_local_to_sql_datetime(): void
    {
        $result = $this->normalizeViaFormRequest('2026-09-15T14:30');

        $this->assertNotNull($result);
        $this->assertEquals('2026-09-15 14:30:00', $result);
    }

    /** @test */
    public function test_preserves_standard_sql_datetime_format(): void
    {
        $result = $this->normalizeViaFormRequest('2026-09-15 14:30:00');

        $this->assertEquals('2026-09-15 14:30:00', $result);
    }

    /** @test */
    public function test_normalizes_iso8601_with_T_separator_and_seconds(): void
    {
        $result = $this->normalizeViaFormRequest('2026-09-15T14:30:00');

        $this->assertEquals('2026-09-15 14:30:00', $result);
    }

    /** @test */
    public function test_leaves_invalid_date_string_as_is_for_validator(): void
    {
        $result = $this->normalizeViaFormRequest('invalid-date');

        // Carbon::parse('invalid-date') will throw, so it should be left as-is
        $this->assertEquals('invalid-date', $result);
    }

    // =========================================================================
    // B. SERVICE LAYER INTEGRATION TESTS (end-to-end persistence)
    // =========================================================================

    /** @test */
    public function test_service_creates_appointment_with_sql_datetime_string(): void
    {
        $patient = Patient::factory()->create([
            'name'  => 'تست مريض',
            'phone' => '01011111111',
        ]);

        $appointment = $this->appointmentService->createAppointment([
            'branch_id'        => $this->branch->id,
            'patient_id'       => $patient->id,
            'appointment_time' => '2026-09-15 14:30:00',
            'type'             => 'check_up',
            'status'           => 'booking',
        ]);

        $this->assertNotNull($appointment->id);
        $this->assertInstanceOf(Carbon::class, $appointment->appointment_time);
        $this->assertEquals('2026-09-15 14:30:00', $appointment->appointment_time->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function test_service_creates_appointment_with_carbon_parsed_iso_string(): void
    {
        $patient = Patient::factory()->create([
            'name'  => 'تست ISO',
            'phone' => '01022222222',
        ]);

        // Simulate what prepareForValidation does: normalize ISO to SQL format
        $normalizedTime = Carbon::parse('2026-09-15T14:30:00.000Z')->format('Y-m-d H:i:s');

        $appointment = $this->appointmentService->createAppointment([
            'branch_id'        => $this->branch->id,
            'patient_id'       => $patient->id,
            'appointment_time' => $normalizedTime,
            'type'             => 'check_up',
            'status'           => 'booking',
        ]);

        $this->assertNotNull($appointment->id);
        $this->assertInstanceOf(Carbon::class, $appointment->appointment_time);
    }

    /** @test */
    public function test_service_creates_appointment_with_carbon_parsed_html5_datetime(): void
    {
        $patient = Patient::factory()->create([
            'name'  => 'تست HTML5',
            'phone' => '01033333333',
        ]);

        // Simulate what prepareForValidation does: normalize HTML5 datetime-local
        $normalizedTime = Carbon::parse('2026-09-15T14:30')->format('Y-m-d H:i:s');

        $appointment = $this->appointmentService->createAppointment([
            'branch_id'        => $this->branch->id,
            'patient_id'       => $patient->id,
            'appointment_time' => $normalizedTime,
            'type'             => 'check_up',
            'status'           => 'booking',
        ]);

        $this->assertNotNull($appointment->id);
        $this->assertEquals('2026-09-15', $appointment->appointment_time->format('Y-m-d'));
        $this->assertEquals('14:30:00', $appointment->appointment_time->format('H:i:s'));
    }

    // =========================================================================
    // C. VALIDATION RULE TESTS (ensures invalid formats are rejected)
    // =========================================================================

    /** @test */
    public function test_validation_rejects_invalid_date_string(): void
    {
        $request = StoreAppointmentRequest::create('/api/v1/appointments', 'POST', [
            'appointment_time' => 'invalid-date',
            'branch_id'        => $this->branch->id,
            'type'             => 'check_up',
            'status'           => 'booking',
            'patient'          => [
                'name'  => 'Test Patient',
                'phone' => '01000000000',
            ],
        ]);

        // Run prepareForValidation
        $method = new \ReflectionMethod(StoreAppointmentRequest::class, 'prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);

        // Now validate using the rules
        $rules = (new StoreAppointmentRequest())->rules();
        $validator = validator($request->all(), $rules);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('appointment_time'));
    }

    /** @test */
    public function test_validation_passes_for_normalized_iso8601(): void
    {
        $request = StoreAppointmentRequest::create('/api/v1/appointments', 'POST', [
            'appointment_time' => '2026-09-15T14:30:00.000Z',
            'branch_id'        => $this->branch->id,
            'type'             => 'check_up',
            'status'           => 'booking',
            'patient'          => [
                'name'  => 'Test Patient',
                'phone' => '01000000000',
            ],
        ]);

        // Run prepareForValidation
        $method = new \ReflectionMethod(StoreAppointmentRequest::class, 'prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);

        // appointment_time should now be Y-m-d H:i:s
        $rules = (new StoreAppointmentRequest())->rules();
        $validator = validator($request->all(), $rules);

        $this->assertFalse(
            $validator->errors()->has('appointment_time'),
            'ISO 8601 datetime should pass validation after normalization. Errors: ' . $validator->errors()->toJson()
        );
    }

    /** @test */
    public function test_validation_passes_for_normalized_html5_datetime_local(): void
    {
        $request = StoreAppointmentRequest::create('/api/v1/appointments', 'POST', [
            'appointment_time' => '2026-09-15T14:30',
            'branch_id'        => $this->branch->id,
            'type'             => 'check_up',
            'status'           => 'booking',
            'patient'          => [
                'name'  => 'Test Patient',
                'phone' => '01000000000',
            ],
        ]);

        // Run prepareForValidation
        $method = new \ReflectionMethod(StoreAppointmentRequest::class, 'prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);

        $rules = (new StoreAppointmentRequest())->rules();
        $validator = validator($request->all(), $rules);

        $this->assertFalse(
            $validator->errors()->has('appointment_time'),
            'HTML5 datetime-local should pass validation after normalization. Errors: ' . $validator->errors()->toJson()
        );
    }

    /** @test */
    public function test_validation_passes_for_standard_sql_format(): void
    {
        $request = StoreAppointmentRequest::create('/api/v1/appointments', 'POST', [
            'appointment_time' => '2026-09-15 14:30:00',
            'branch_id'        => $this->branch->id,
            'type'             => 'check_up',
            'status'           => 'booking',
            'patient'          => [
                'name'  => 'Test Patient',
                'phone' => '01000000000',
            ],
        ]);

        // Run prepareForValidation
        $method = new \ReflectionMethod(StoreAppointmentRequest::class, 'prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);

        $rules = (new StoreAppointmentRequest())->rules();
        $validator = validator($request->all(), $rules);

        $this->assertFalse(
            $validator->errors()->has('appointment_time'),
            'Standard SQL datetime should pass validation. Errors: ' . $validator->errors()->toJson()
        );
    }

    // =========================================================================
    // D. UPDATE REQUEST NORMALIZATION
    // =========================================================================

    /** @test */
    public function test_update_request_normalizes_iso8601(): void
    {
        $request = UpdateAppointmentRequest::create('/api/v1/appointments/1', 'PUT', [
            'appointment_time' => '2026-09-15T14:30:00.000Z',
        ]);

        $method = new \ReflectionMethod(UpdateAppointmentRequest::class, 'prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);

        $normalized = $request->input('appointment_time');

        $this->assertNotNull($normalized);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $normalized);
    }

    /** @test */
    public function test_update_request_normalizes_html5_datetime_local(): void
    {
        $request = UpdateAppointmentRequest::create('/api/v1/appointments/1', 'PUT', [
            'appointment_time' => '2026-09-15T14:30',
        ]);

        $method = new \ReflectionMethod(UpdateAppointmentRequest::class, 'prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);

        $this->assertEquals('2026-09-15 14:30:00', $request->input('appointment_time'));
    }
}
