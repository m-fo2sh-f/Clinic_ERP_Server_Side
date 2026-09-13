<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Service;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class FinancialModuleSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();

        $defaultServices = [
            [
                'name'          => 'كشف استشاري',
                'code'          => 'CONSULTATION',
                'default_price' => 150.00,
                'is_active'     => true,
            ],
            [
                'name'          => 'رسم قلب (ECG)',
                'code'          => 'ECG',
                'default_price' => 100.00,
                'is_active'     => true,
            ],
            [
                'name'          => 'سونار باطني (Ultrasound)',
                'code'          => 'ULTRASOUND',
                'default_price' => 250.00,
                'is_active'     => true,
            ],
            [
                'name'          => 'تحليل سكر عشوائي (Blood Glucose)',
                'code'          => 'GLUCOSE',
                'default_price' => 40.00,
                'is_active'     => true,
            ],
            [
                'name'          => 'تضميد وغيار جرح (Wound Dressing)',
                'code'          => 'WOUND_DRESS',
                'default_price' => 80.00,
                'is_active'     => true,
            ],
        ];

        foreach ($tenants as $tenant) {
            $tenant->run(function () use ($defaultServices) {
                $branches = Branch::all();

                foreach ($defaultServices as $svcData) {
                    $service = Service::firstOrCreate(
                        ['code' => $svcData['code']],
                        [
                            'name'          => $svcData['name'],
                            'default_price' => $svcData['default_price'],
                            'is_active'     => $svcData['is_active'],
                        ]
                    );

                    foreach ($branches as $branch) {
                        BranchService::firstOrCreate(
                            [
                                'branch_id'  => $branch->id,
                                'service_id' => $service->id,
                            ],
                            [
                                'price'        => $svcData['default_price'],
                                'is_available' => true,
                            ]
                        );
                    }
                }
            });
        }
    }
}
