<?php

namespace Modules\ReviewModule\Http\Controllers\Api\V1\Customer;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\BookingModule\Entities\Booking;
use Modules\ProviderManagement\Entities\Provider;
use Modules\ReviewModule\Entities\Review;
use Modules\ServiceManagement\Entities\Service;

class ReviewController extends Controller
{
    private $review, $booking, $service, $provider;

    public function __construct(Review $review, Booking $booking, Service $service, Provider $provider)
    {
        $this->review = $review;
        $this->booking = $booking;
        $this->service = $service;
        $this->provider = $provider;
    }


    /**
     * Show resource.
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
        }

        $booking_id = $request->booking_id;
        $customer_id = $request->user()->id;

        // Get all services that have reviews for this booking
        $services = $this->service
            ->whereHas('reviews', function ($query) use ($booking_id, $customer_id) {
                $query->where('booking_id', $booking_id)
                    ->where('customer_id', $customer_id);
            })
            ->with(['reviews' => function ($query) use ($booking_id, $customer_id) {
                $query->where('booking_id', $booking_id)
                    ->where('customer_id', $customer_id)
                    ->with('reviewReply');
            }])
            ->withoutGlobalScope('zone_wise_data')
            ->get();

        // Transform the response to match desired structure
        $transformedServices = $services->map(function ($service) {
            return [
                'id' => $service->id,
                'name' => $service->name,
                'short_description' => $service->short_description,
                'description' => $service->description,
                'cover_image' => $service->cover_image,
                'thumbnail' => $service->thumbnail,
                'service_img' => $service->service_img ?? '',
                'category_id' => $service->category_id,
                'sub_category_id' => $service->sub_category_id,
                'tax' => $service->tax,
                'order_count' => $service->order_count,
                'is_active' => $service->is_active,
                'rating_count' => $service->rating_count,
                'avg_rating' => $service->avg_rating,
                'min_bidding_price' => $service->min_bidding_price ?? '0.000',
                'deleted_at' => $service->deleted_at,
                'created_at' => $service->created_at,
                'updated_at' => $service->updated_at,
                'thumbnail_full_path' => $service->thumbnail_full_path,
                'cover_image_full_path' => $service->cover_image_full_path,
                'service_discount' => $service->service_discount ?? [],
                'campaign_discount' => $service->campaign_discount ?? [],
                'review' => $service->reviews->map(function ($review) {
                    return [
                        'id' => $review->readable_id,
                        'booking_id' => $review->booking_id,
                        'service_id' => $review->service_id,
                        'review_rating' => $review->review_rating,
                        'review_comment' => $review->review_comment,
                        'review_reply' => $review->reviewReply ? [
                            'id' => $review->reviewReply->id,
                            'review_id' => $review->reviewReply->review_id,
                            'reply' => $review->reviewReply->reply,
                            'updated_at' => $review->reviewReply->updated_at,
                        ] : null,
                        'created_at' => $review->created_at,
                        'updated_at' => $review->updated_at,
                    ];
                }),
            ];
        });

        return response()->json(response_formatter(DEFAULT_STORE_200, $transformedServices), 200);
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => 'required|uuid',
            'service_id' => 'required|uuid',
            'variant_id' => 'required',
            'review_rating' => 'required|numeric|min:1|max:5',
            'review_comment' => 'nullable',
            'review_images' => 'image',
        ]);

        if ($validator->fails()) {
            return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
        }

        $booking = $this->booking->find($request['booking_id']);
        if (!isset($booking)) {
            return response()->json(response_formatter(DEFAULT_404), 200);
        }

        $providerId = $booking->provider_id;

        // If current booking has no provider_id,
        // then check child booking using parent_booking_id
        if (empty($providerId)) {

            $childBooking = $this->booking
                ->where('parent_booking_id', $booking->id)
                ->whereNotNull('provider_id')
                ->first();

            if ($childBooking) {
                $providerId = $childBooking->provider_id;
            }
        }

        // Fallback from serviceman
        if (empty($providerId) && $booking->serviceman_id) {
            $providerId = $booking->serviceman?->provider_id;
        }

        // Final validation
        if (empty($providerId)) {
            return response()->json(response_formatter(
                DEFAULT_FAIL_200,
                null,
                ['error' => 'Provider information not available for this booking']
            ), 400);
        }

        $images = [];
        if ($request->has('images')) {
            foreach ($request->images as $image) {
                $images[] = file_uploader('review/', 'png', $image);
            }
        }
        $review = $this->review
            ->where('booking_id', $request->booking_id)
            ->where('service_id', $request->service_id)
            ->where('variant_id', $request->variant_id)
            ->where('customer_id', $request->user()->id)
            ->first();

        if (!isset($review)) {
            $review = $this->review;
        }

        $review->booking_id = $request->booking_id;
        $review->service_id = $request->service_id;
        $review->variant_id = $request->variant_id;
        $review->customer_id = $request->user()->id;
        $review->review_rating = $request->review_rating;
        $review->review_comment = $request->review_comment;
        $review->provider_id = $providerId;
        $review->review_images = $images;
        $review->booking_date = $booking->created_at;

        $baseReadableId = $booking->readable_id;

        if (!$review->readable_id) {
            $lastReview = $this->review
                ->where('readable_id', 'like', "{$baseReadableId}%")
                ->orderBy('readable_id', 'desc')
                ->first();

            if ($lastReview) {
                $lastIdNumber = (int)substr($lastReview->readable_id, -3);
                $newReadableId = $baseReadableId . str_pad($lastIdNumber + 1, 3, '0', STR_PAD_LEFT);
            } else {
                $newReadableId = $baseReadableId . '100';
            }

            $review->readable_id = $newReadableId;
        }

        $review->save();


        foreach (['service_id' => $request->service_id, 'provider_id' => $providerId] as $key => $value) {
            $ratingGroupCount = DB::table('reviews')->where($key, $value)
                ->select('review_rating', DB::raw('count(*) as total'))
                ->groupBy('review_rating')
                ->get();

            $totalRating = 0;
            $ratingCount = 0;
            foreach ($ratingGroupCount as $count) {
                $totalRating += round($count->review_rating * $count->total, 2);
                $ratingCount += $count->total;
            }

            $query = collect([]);
            if ($key == 'service_id') {
                $query = $this->service->where(['id' => $value]);
            } elseif ($key == 'provider_id') {
                $query = $this->provider->where(['id' => $value]);
            }

            $query->update([
                'rating_count' => $ratingCount,
                'avg_rating' => round($totalRating / $ratingCount, 2)
            ]);
        }


        return response()->json(response_formatter(DEFAULT_STORE_200), 200);
    }
}
