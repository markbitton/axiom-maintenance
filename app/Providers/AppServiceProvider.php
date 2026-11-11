<?php

namespace App\Providers;

use App\Services\Photos\R2Storage;
use App\Services\RentManager\RentManagerClient;
use App\Services\Sms\LogGateway;
use App\Services\Sms\SmsGateway;
use App\Services\Sms\TwilioGateway;
use App\Services\Triage\Classifier;
use App\Services\Triage\ClaudeClassifier;
use App\Services\Triage\RulesClassifier;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RentManagerClient::class, fn () => RentManagerClient::fromConfig());
        $this->app->singleton(R2Storage::class, fn () => R2Storage::fromConfig());
        $this->app->singleton(SmsGateway::class, fn () => config('axiom.sms.driver') === 'twilio'
            ? TwilioGateway::fromConfig()
            : new LogGateway);
        $this->app->bind(Classifier::class, fn ($app) => config('axiom.ai.enabled') && config('services.anthropic.key')
            ? new ClaudeClassifier($app->make(RulesClassifier::class))
            : $app->make(RulesClassifier::class));
    }

    public function boot(): void
    {
        //
    }
}
