<?php

namespace Modules\PromotionManagement\Http\Controllers\Api\V1\Customer;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Modules\PromotionManagement\Entities\Banner;

class BannerController extends Controller
{
    private Banner $banner;

    public function __construct(Banner $banner)
    {
        $this->banner = $banner;
    }

    /**
     * Display a listing of the resource.
     * @param Request $request
     * @return JsonResponse
     */
    // public function index(Request $request): JsonResponse
    // {
    //     $validator = Validator::make($request->all(), [
    //         'limit' => 'required|numeric|min:1|max:200',
    //         'offset' => 'required|numeric|min:1|max:100000'
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
    //     }

    //     $bannersQuery = $this->banner->with(['service' => function ($query) {
    //         $query->where('is_active', 1);
    //     }, 'category' => function ($query) {
    //         $query->where('is_active', 1);
    //     }])->ofStatus(1);

    //     $banners = $bannersQuery->paginate($request['limit'], ['*'], 'offset', $request['offset'])->withPath('');

    //     // Filter items based on resource_type
    //     $banners->getCollection()->transform(function ($item) {
    //         if ($item->resource_type == 'service' && is_null($item->service)) {
    //             return null;
    //         }
    //         if ($item->resource_type == 'category' && is_null($item->category)) {
    //             return null;
    //         }
    //         return $item;
    //     });

    //     // Remove nulls after transform
    //     $filteredBanners = $banners->getCollection()->filter()->values();

    //     // Replace paginator collection with filtered collection
    //     $banners->setCollection($filteredBanners);

    //     return response()->json(response_formatter(DEFAULT_200, $banners), 200);
    // }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'required|numeric|min:1|max:200',
            'offset' => 'required|numeric|min:1|max:100000',
            'page_type' => 'nullable|in:home,service'
        ]);

        if ($validator->fails()) {
            return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
        }

        $pageType = $request->get('page_type', 'home');

        $bannersQuery = $this->banner->with(['service' => function ($query) {
            $query->where('is_active', 1);
        }, 'category' => function ($query) {
            $query->where('is_active', 1);
        }])->ofStatus(1)->visibleForPageType($pageType);

        $banners = $bannersQuery->paginate($request['limit'], ['*'], 'offset', $request['offset'])->withPath('');

        // Filter items based on resource_type
        $banners->getCollection()->transform(function ($item) {
            if ($item->resource_type == 'service' && is_null($item->service)) {
                return null;
            }
            if ($item->resource_type == 'category' && is_null($item->category)) {
                return null;
            }
            return $item;
        });

        // Remove nulls after transform
        $filteredBanners = $banners->getCollection()->filter()->values();

        // Replace paginator collection with filtered collection
        $banners->setCollection($filteredBanners);

        return response()->json(response_formatter(DEFAULT_200, $banners), 200);
    }
}
