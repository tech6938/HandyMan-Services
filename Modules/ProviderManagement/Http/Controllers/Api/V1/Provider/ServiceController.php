<?php

namespace Modules\ProviderManagement\Http\Controllers\Api\V1\Provider;

use Carbon\Carbon;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\BusinessSettingsModule\Entities\PackageSubscriber;
use Modules\BusinessSettingsModule\Entities\PackageSubscriberLimit;
use Modules\CategoryManagement\Entities\Category;
use Modules\ProviderManagement\Entities\SubscribedService;
use Modules\ServiceManagement\Entities\Service;

class ServiceController extends Controller
{
    private $subscribedService, $category, $service;
    private PackageSubscriber $packageSubscriber;
    private PackageSubscriberLimit $packageSubscriberLimit;

    public function __construct(
        SubscribedService $subscribedService,
        Category $category,
        Service $service,
        PackageSubscriber $packageSubscriber,
        PackageSubscriberLimit $packageSubscriberLimit
    ) {
        $this->subscribedService = $subscribedService;
        $this->packageSubscriber = $packageSubscriber;
        $this->packageSubscriberLimit = $packageSubscriberLimit;
        $this->category = $category;
        $this->service = $service;
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @return JsonResponse
     */
    public function updateSubscription(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'service_id' => 'required|array',
            'service_id.*' => 'uuid|exists:services,id',
        ]);

        if ($validator->fails()) {
            return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
        }

        $packageSubscriber = $this->packageSubscriber->where('provider_id', $request->user()->provider->id)->first();

        // Check if package is limiting by service count instead of category count
        // You might need to adjust this based on your package subscriber limits structure
        $limit = $this->packageSubscriberLimit
            ->where('provider_id', $request->user()->provider->id)
            ->where('subscription_package_id', $packageSubscriber?->subscription_package_id)
            ->where('key', 'service') // Changed from 'category' to 'service'
            ->first();

        $packageSubscriberLimit = $limit?->limit_count;
        $isLimit = $limit?->is_limited;
        $startDate = $packageSubscriber?->package_start_date;
        $endDate = $packageSubscriber?->package_end_date;
        $providerId = $packageSubscriber?->provider_id;
        $currentDate = Carbon::now()->subDays();
        $packageEndDate = $endDate ? Carbon::parse($endDate)->endOfDay() : null;
        $isPackageEnded = $packageEndDate ? $currentDate->diffInDays($packageEndDate, false) : null;

        // Count subscribed services instead of categories
        $serviceCount = $this->subscribedService
            ->where('provider_id', $providerId)
            ->where('is_subscribed', 1)
            ->whereNotNull('service_id') // Only count service-based subscriptions
            ->count();

        foreach ($request['service_id'] as $id) {
            // Find the service to get its category and sub_category
            $service = $this->service->find($id);

            if (!$service) {
                continue; // Skip if service not found
            }

            // Check if subscription exists for this service
            $subscribedService = $this->subscribedService
                ->where('service_id', $id)
                ->where('provider_id', $request->user()->provider->id)
                ->first();

            if (!$subscribedService) {
                // Check service limit before creating new subscription
                if ($packageSubscriberLimit <= $serviceCount && $packageSubscriber && $isLimit && $isPackageEnded) {
                    return response()->json(response_formatter('service_limit_end', 400), 400);
                }

                $subscribedService = new $this->subscribedService;
                $subscribedService->is_subscribed = 1;
                $subscribedService->service_id = $id;
                $subscribedService->category_id = $service->category_id;
                $subscribedService->sub_category_id = $service->sub_category_id;

            } elseif($subscribedService) {
                if ($subscribedService->is_subscribed == 0){
                    // Check service limit before activating
                    if ($packageSubscriberLimit <= $serviceCount && $packageSubscriber && $isLimit && $isPackageEnded) {
                        return response()->json(response_formatter('service_limit_end', 400), 400);
                    }
                }

                $subscribedService->is_subscribed = !$subscribedService->is_subscribed;
            }

            $subscribedService->provider_id = $request->user()->provider->id;
            $subscribedService->save();

            // Update service count after each addition/activation
            if ($subscribedService->is_subscribed == 1) {
                $serviceCount++;
            } else {
                $serviceCount--;
            }
        }

        return response()->json(response_formatter(DEFAULT_200), 200);
    }

    /**
     * Get subscribed services
     * @param Request $request
     * @return JsonResponse
     */
    public function getSubscribedServices(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'numeric',
            'offset' => 'numeric',
            'status' => 'in:0,1'
        ]);

        if ($validator->fails()) {
            return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
        }

        $subscribedServices = $this->subscribedService
            ->with(['service' => function($query) {
                $query->select('id', 'name', 'thumbnail', 'cover_image', 'category_id', 'sub_category_id');
            }])
            ->where('provider_id', $request->user()->provider->id)
            ->when($request->has('status'), function($query) use ($request) {
                $query->where('is_subscribed', $request->status);
            })
            ->whereNotNull('service_id') // Only get service-based subscriptions
            ->paginate($request['limit'] ?? 10, ['*'], 'offset', $request['offset'] ?? 1);

        return response()->json(response_formatter(DEFAULT_200, $subscribedServices), 200);
    }
}
