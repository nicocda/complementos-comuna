<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->isProduction()) {
            // Hostinger terminates TLS in front of PHP; make generated links (emails, WhatsApp) use https.
            URL::forceScheme('https');
        }

        // Default password policy, also used by Filament's profile and password reset pages.
        Password::defaults(fn () => Password::min(12)->letters()->numbers());
    }
}
