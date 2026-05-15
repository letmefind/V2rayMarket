<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\ServiceProvider;


use Modules\Ticketing\Providers\EventServiceProvider as TicketingEventServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // رجیستر EventServiceProvider ماژول Ticketing
        $this->app->register(TicketingEventServiceProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        User::creating(function ($user) {
            do {
                $code = 'REF-' . strtoupper(\Illuminate\Support\Str::random(6));
            } while (User::where('referral_code', $code)->exists());

            $user->referral_code = $code;
        });

        // ==========================================================
    }
}
