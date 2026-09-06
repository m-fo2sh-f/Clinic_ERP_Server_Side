<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Drug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class DrugSearchTest extends TestCase
{
    use RefreshDatabase;

    protected Drug $drugPanadol;
    protected Drug $drugAugmentin;
    protected Drug $drugConcor;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Seed dedicated test drugs
        $this->drugPanadol = Drug::create([
            'trade_name'        => 'Panadol Extra',
            'active_ingredient' => 'Paracetamol 500mg / Caffeine 65mg',
            'form'              => 'Tablet',
            'strength'          => '500mg/65mg',
            'company'           => 'GSK Consumer Healthcare',
            'price'             => 35.00,
            'therapeutic_class' => 'Analgesic',
            'barcode'           => '6221111222233',
        ]);

        $this->drugAugmentin = Drug::create([
            'trade_name'        => 'Augmentin 1g',
            'active_ingredient' => 'Amoxicillin 875mg / Clavulanic Acid 125mg',
            'form'              => 'Tablet',
            'strength'          => '1g',
            'company'           => 'GlaxoSmithKline',
            'price'             => 120.00,
            'therapeutic_class' => 'Antibiotic',
            'barcode'           => '6224444555566',
        ]);

        $this->drugConcor = Drug::create([
            'trade_name'        => 'Concor Cor 2.5mg',
            'active_ingredient' => 'Bisoprolol Fumarate',
            'form'              => 'Tablet',
            'strength'          => '2.5mg',
            'company'           => 'Merck Healthcare',
            'price'             => 45.00,
            'therapeutic_class' => 'Beta Blocker',
            'barcode'           => '6227777888899',
        ]);

        // Commit transaction to flush MySQL InnoDB Full-Text cache to inverted index
        DB::commit();
    }

    protected function tearDown(): void
    {
        Drug::whereIn('id', [
            $this->drugPanadol->id,
            $this->drugAugmentin->id,
            $this->drugConcor->id,
        ])->delete();

        parent::tearDown();
    }

    /** @test */
    public function test_searching_by_partial_trade_name_matches_drug(): void
    {
        // Search partial trade name "Panad" (>= 3 chars)
        $results = Drug::search('Panad')->get();

        $this->assertTrue($results->contains('id', $this->drugPanadol->id));
        $this->assertFalse($results->contains('id', $this->drugAugmentin->id));
    }

    /** @test */
    public function test_searching_by_active_ingredient_matches_drug(): void
    {
        // Search active ingredient "Amoxicillin"
        $results = Drug::search('Amoxicillin')->get();

        $this->assertTrue($results->contains('id', $this->drugAugmentin->id));
        $this->assertFalse($results->contains('id', $this->drugPanadol->id));
    }

    /** @test */
    public function test_searching_by_numeric_barcode_matches_accurately(): void
    {
        // Numeric search uses barcode prefix matching
        $results = Drug::search('6221111')->get();

        $this->assertTrue($results->contains('id', $this->drugPanadol->id));
        $this->assertFalse($results->contains('id', $this->drugAugmentin->id));
        $this->assertFalse($results->contains('id', $this->drugConcor->id));
    }

    /** @test */
    public function test_searching_by_company_name_matches_drug(): void
    {
        // Search by company "Merck"
        $results = Drug::search('Merck')->get();

        $this->assertTrue($results->contains('id', $this->drugConcor->id));
        $this->assertFalse($results->contains('id', $this->drugPanadol->id));
    }

    /** @test */
    public function test_short_search_under_three_chars_uses_trade_name_prefix(): void
    {
        // Short search (< 3 chars): "Pa" -> prefix match on trade_name
        $results = Drug::search('Pa')->get();

        $this->assertTrue($results->contains('id', $this->drugPanadol->id));
    }

    /** @test */
    public function test_search_sanitizes_problematic_boolean_mode_operators(): void
    {
        // Query with Boolean operators that would cause SQL syntax errors if unhandled: + - * ( ) " @ ~
        $results = Drug::search('Panadol+Extra (500mg)')->get();

        $this->assertTrue($results->contains('id', $this->drugPanadol->id));
    }
}
