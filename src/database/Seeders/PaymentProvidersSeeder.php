<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PaymentProvider;

class PaymentProvidersSeeder extends Seeder
{
    public function run()
    {
        PaymentProvider::create([
            'name' => 'Peachpayments',
            'slug' => 'peachpayments',
            'description' => 'Peachpayments payment provider',
            'is_active' => true,
        ]);

        // Other payment providers...
    }
}