<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ImportEgyptianDrugsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'drugs:import-egyptian 
                            {--chunk=1000 : Number of records to insert per batch}
                            {--file= : Optional path to custom json dataset}';

    /**
     * The console command description.
     */
    protected $description = 'Import the comprehensive Egyptian pharmaceutical database into the central_drugs table.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting Egyptian Pharmaceutical Database import...');

        $filePath = $this->option('file') ?: database_path('data/egyptian_drugs.json');

        if (! File::exists($filePath)) {
            $this->error("Dataset file not found at: {$filePath}");

            return self::FAILURE;
        }

        $this->line("Reading dataset from: {$filePath}");
        $content = File::get($filePath);
        $drugs = json_decode($content, true);

        if (! is_array($drugs)) {
            $this->error('Failed to parse JSON file or invalid array format.');

            return self::FAILURE;
        }

        $total = count($drugs);
        $this->info("Found {$total} Egyptian drug records. Preparing batch insertion...");

        // Ensure we are operating on central connection
        $centralConnection = config('tenancy.database.central_connection', 'mysql');

        DB::connection($centralConnection)->table('central_drugs')->truncate();

        $chunkSize = max(100, (int) $this->option('chunk'));
        $now = Carbon::now()->toDateTimeString();

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $batch = [];
        $insertedCount = 0;

        foreach ($drugs as $item) {
            $tradeNameEn = trim((string) ($item['commercial_name_en'] ?? $item['trade_name_en'] ?? $item['trade_name'] ?? ''));
            if ($tradeNameEn === '') {
                continue;
            }

            $rawForm = isset($item['form']) ? trim((string) $item['form']) : (isset($item['route']) ? trim((string) $item['route']) : '');
            $upperName = strtoupper($tradeNameEn);
            $upperRoute = strtoupper($rawForm);

            $detectedForm = null;
            if (str_contains($upperName, 'CAP') || str_contains($upperRoute, 'CAP')) {
                $detectedForm = 'Capsule';
            } elseif (str_contains($upperName, 'TAB') || str_contains($upperRoute, 'SOLID')) {
                $detectedForm = 'Tablet';
            } elseif (str_contains($upperName, 'SYRUP') || str_contains($upperName, 'SUSP') || str_contains($upperRoute, 'LIQUID') || str_contains($upperName, 'ELIXIR')) {
                $detectedForm = 'Syrup';
            } elseif (str_contains($upperName, 'SPRAY')) {
                $detectedForm = 'Spray';
            } elseif (str_contains($upperName, 'DROP') || str_contains($upperName, 'EYE') || str_contains($upperName, 'EAR') || str_contains($upperName, 'NASAL')) {
                $detectedForm = 'Drops';
            } elseif (str_contains($upperName, 'OINT')) {
                $detectedForm = 'Ointment';
            } elseif (str_contains($upperName, 'CREAM') || str_contains($upperName, 'GEL') || str_contains($upperRoute, 'TOPICAL')) {
                $detectedForm = 'Cream';
            } elseif (str_contains($upperName, 'AMP') || str_contains($upperName, 'VIAL') || str_contains($upperName, 'INJ') || str_contains($upperRoute, 'INJECTION')) {
                $detectedForm = 'Injection';
            } else {
                $detectedForm = $rawForm !== '' ? mb_substr($rawForm, 0, 100) : 'Tablet';
            }

            $detectedStrength = isset($item['strength']) ? trim((string) $item['strength']) : null;
            if (! $detectedStrength && preg_match('/(\d+(?:\.\d+)?\s*(?:mg|mcg|gm|g|ml|iu|%|\/ml|\/dose))/i', $tradeNameEn, $m)) {
                $detectedStrength = trim($m[1]);
            }

            $batch[] = [
                'trade_name' => mb_substr($tradeNameEn, 0, 255),
                'active_ingredient' => isset($item['scientific_name']) ? trim((string) $item['scientific_name']) : (isset($item['active_ingredient']) ? trim((string) $item['active_ingredient']) : null),
                'form' => $detectedForm ? mb_substr($detectedForm, 0, 100) : null,
                'strength' => $detectedStrength ? mb_substr($detectedStrength, 0, 100) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= $chunkSize) {
                DB::connection($centralConnection)->table('central_drugs')->insert($batch);
                $insertedCount += count($batch);
                $bar->advance(count($batch));
                $batch = [];
            }
        }

        if (! empty($batch)) {
            DB::connection($centralConnection)->table('central_drugs')->insert($batch);
            $insertedCount += count($batch);
            $bar->advance(count($batch));
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Successfully imported {$insertedCount} Egyptian pharmaceutical drugs into the central database!");

        return self::SUCCESS;
    }
}
