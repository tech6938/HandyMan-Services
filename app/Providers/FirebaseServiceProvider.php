<?php

namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Factory;

class FirebaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('firebase.messaging', function ($app) {

            $serviceAccountKey = business_config('push_notification', 'third_party')->live_values ?? [];

            if (!is_array($serviceAccountKey) || empty($serviceAccountKey['service_file_content'])) {
                return false;
            }

            $jsonDecodeKey = json_decode($serviceAccountKey['service_file_content'], true);

            // ✅ JSON must decode to array
            if (!is_array($jsonDecodeKey)) {
                return false;
            }

            // ✅ Must contain required service-account fields
            if (empty($jsonDecodeKey['client_email']) || empty($jsonDecodeKey['private_key'])) {
                // You can also throw a custom exception or log it
                return false;
            }

            return (new Factory())
                ->withServiceAccount($jsonDecodeKey)
                ->createMessaging();
        });

        // If you also need firestore binding, keep it but don’t name it firestore if it returns Messaging
        $this->app->singleton('firebase.firestore', function ($app) {

            $serviceAccountKey = business_config('push_notification', 'third_party')->live_values ?? [];

            if (!is_array($serviceAccountKey) || empty($serviceAccountKey['service_file_content'])) {
                return false;
            }

            $jsonDecodeKey = json_decode($serviceAccountKey['service_file_content'], true);

            if (!is_array($jsonDecodeKey)) {
                return false;
            }

            if (empty($jsonDecodeKey['client_email']) || empty($jsonDecodeKey['private_key'])) {
                return false;
            }

            // NOTE: This is still messaging. If you really want Firestore, you should call createFirestore()
            return (new Factory())
                ->withServiceAccount($jsonDecodeKey)
                ->createMessaging();
        });
    }

    public function boot(): void
    {
        //
    }
}
