<?php

namespace Modules\BookingModule\Http\Controllers\Api\V1\Customer;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Modules\BidModule\Entities\PostBid;
use Modules\BidModule\Http\Controllers\APi\V1\Customer\PostBidController;
use Modules\BookingModule\Entities\Booking;
use Modules\BookingModule\Entities\BookingOfflinePayment;
use Modules\BookingModule\Entities\BookingPartialPayment;
use Modules\BookingModule\Entities\BookingRepeat;
use Modules\BookingModule\Entities\BookingStatusHistory;
use Modules\BookingModule\Http\Traits\BookingTrait;
use Modules\CustomerModule\Traits\CustomerAddressTrait;
use Modules\PaymentModule\Entities\OfflinePayment;
use Modules\PaymentModule\Entities\PaymentRequest;
use Modules\TransactionModule\Entities\Account;
use Modules\TransactionModule\Entities\Transaction;
use Modules\UserManagement\Entities\User;

class BookingController extends Controller
{
    use BookingTrait, CustomerAddressTrait;

    private Booking $booking;
    private BookingStatusHistory $bookingStatusHistory;

    protected OfflinePayment $offlinePayment;
    private BookingRepeat $bookingRepeat;
    private bool $isCustomerLoggedIn;
    private mixed $customerUserId;

    public function __construct(Booking $booking, BookingStatusHistory $bookingStatusHistory, Request $request, OfflinePayment $offlinePayment, BookingRepeat $bookingRepeat)
    {
        $this->booking = $booking;
        $this->bookingStatusHistory = $bookingStatusHistory;
        $this->offlinePayment = $offlinePayment;
        $this->bookingRepeat = $bookingRepeat;

        $this->isCustomerLoggedIn = (bool)auth('api')->user();
        $this->customerUserId = $this->isCustomerLoggedIn ? auth('api')->user()->id : $request['guest_id'];
    }

    /**
     * Display a listing of the resource.
     * @param Request $request
     * @return JsonResponse
     */

    public function placeRequest(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_method' => 'required|in:' . implode(',', array_column(PAYMENT_METHODS, 'key')),
            'zone_id' => 'required|uuid',
            'service_schedule' => 'required|date',
            'service_address_id' => is_null($request['service_address']) ? 'required' : 'nullable',
            'post_id' => 'nullable|uuid',
            'provider_id' => 'nullable|uuid',

            'guest_id' => $this->isCustomerLoggedIn ? 'nullable' : 'required|uuid',
            'service_address' => is_null($request['service_address_id']) ? [
                'required',
                'json',
                function ($attribute, $value, $fail) {
                    $decoded = json_decode($value, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $fail($attribute . ' must be a valid JSON string.');
                        return;
                    }

                    if (is_null($decoded['lat']) || $decoded['lat'] == '') $fail($attribute . ' must contain "lat" properties.');
                    if (is_null($decoded['lon']) || $decoded['lon'] == '') $fail($attribute . ' must contain "lon" properties.');
                    if (is_null($decoded['address']) || $decoded['address'] == '') $fail($attribute . ' must contain "address" properties.');
                    if (is_null($decoded['contact_person_name']) || $decoded['contact_person_name'] == '') $fail($attribute . ' must contain "contact_person_name" properties.');
                    if (is_null($decoded['contact_person_number']) || $decoded['contact_person_number'] == '') $fail($attribute . ' must contain "contact_person_number" properties.');
                    if (is_null($decoded['address_label']) || $decoded['address_label'] == '') $fail($attribute . ' must contain "address_label" properties.');
                },
            ] : '',

            'is_partial' => 'nullable|in:0,1'
        ]);

        if ($validator->fails()) {
            return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
        }

        $newUserInfo = null;
        // Additional validation and register for new_user_info
        if ($request->has('new_user_info') && !empty($request->get('new_user_info')) && !$this->isCustomerLoggedIn) {
            $newUserInfo = json_decode($request['new_user_info'], true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($newUserInfo)) {
                return response()->json(response_formatter(DEFAULT_400, null, 'Invalid new_user_info format'), 400);
            }

            $newUserValidator = Validator::make($newUserInfo, [
                'first_name' => 'required',
                'phone' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/|min:10',
                'password' => 'required|min:8',
            ]);

            if ($newUserValidator->fails()) {
                return response()->json(response_formatter(DEFAULT_400, null, error_processor($newUserValidator)), 400);
            }
        }

        $customerUserId = $this->customerUserId;

        if (is_null($request['service_address_id'])) {
            $request['service_address_id'] = $this->add_address(json_decode($request['service_address']), null, !$this->isCustomerLoggedIn);
        }

        $minimumBookingAmount = (float)(business_config('min_booking_amount', 'booking_setup'))?->live_values;
        $totalBookingAmount = cart_total($customerUserId) + getServiceFee();

        if (!isset($request['post_id']) && $minimumBookingAmount > 0 && $totalBookingAmount < $minimumBookingAmount) {
            return response()->json(response_formatter(MINIMUM_BOOKING_AMOUNT_200), 200);
        }

        if ($request['payment_method'] == 'wallet_payment') {
            if (!isset($request['post_id'])) {
                $response = $this->placeBookingRequest(userId: $customerUserId, request: $request, transactionId: 'wallet_payment', newUserInfo: $newUserInfo);
            } else {
                $postBid = PostBid::with(['post'])
                    ->where('post_id', $request['post_id'])
                    ->where('provider_id', $request['provider_id'])
                    ->first();

                $data = [
                    'payment_method' => $request['payment_method'],
                    'zone_id' => $request['zone_id'],
                    'service_tax' => $postBid?->post?->service?->tax,
                    'provider_id' => $postBid->provider_id,
                    'price' => $postBid->offered_price,
                    'service_schedule' => !is_null($request['booking_schedule']) ? $request['booking_schedule'] : $postBid->post->booking_schedule,
                    'service_id' => $postBid->post->service_id,
                    'category_id' => $postBid->post->category_id,
                    'sub_category_id' => $postBid->post->sub_category_id,
                    'service_address_id' => !is_null($request['service_address_id']) ? $request['service_address_id'] : $postBid->post->service_address_id,
                    'is_partial' => $request['is_partial']
                ];

                $user = User::find($customerUserId);
                $tax = !is_null($data['service_tax']) ? round((($data['price'] * $data['service_tax']) / 100) * 1, 2) : 0;
                if (isset($user) && $user->wallet_balance < ($postBid->offered_price + $tax)) {
                    return response()->json(response_formatter(INSUFFICIENT_WALLET_BALANCE_400), 400);
                }

                $response = $this->placeBookingRequestForBidding($customerUserId, $request, 'wallet_payment', $data);

                if ($response['flag'] == 'success') {
                    PostBidController::acceptPostBidOffer($postBid->id, $response['booking_id']);
                }
            }
        } elseif ($request['payment_method'] == 'offline_payment') {
            if (!isset($request['post_id'])) {
                $response = $this->placeBookingRequest($customerUserId, $request, 'offline-payment', newUserInfo: $newUserInfo, isGuest: !$this->isCustomerLoggedIn);
            } else {
                $postBid = PostBid::with(['post'])
                    ->where('post_id', $request['post_id'])
                    ->where('provider_id', $request['provider_id'])
                    ->first();

                $data = [
                    'payment_method' => $request['payment_method'],
                    'zone_id' => $request['zone_id'],
                    'service_tax' => $postBid?->post?->service?->tax,
                    'provider_id' => $postBid->provider_id,
                    'price' => $postBid->offered_price,
                    'service_schedule' => !is_null($request['booking_schedule']) ? $request['booking_schedule'] : $postBid->post->booking_schedule,
                    'service_id' => $postBid->post->service_id,
                    'category_id' => $postBid->post->category_id,
                    'sub_category_id' => $postBid->post->sub_category_id, //here old category
                    'service_address_id' => !is_null($request['service_address_id']) ? $request['service_address_id'] : $postBid->post->service_address_id,
                    'is_partial' => $request['is_partial']
                ];

                $response = $this->placeBookingRequestForBidding($customerUserId, $request, 'offline_payment', $data);

                if ($response['flag'] == 'success') {
                    PostBidController::acceptPostBidOffer($postBid->id, $response['booking_id']);
                }
            }
        } else {
            if ($request['service_type'] == 'repeat') {
                $response = $this->placeRepeatBookingRequest($customerUserId, $request, 'cash-payment', newUserInfo: $newUserInfo, isGuest: !$this->isCustomerLoggedIn);
            } else {
                $response = $this->placeBookingRequest($customerUserId, $request, 'cash-payment', newUserInfo: $newUserInfo, isGuest: !$this->isCustomerLoggedIn);
            }
        }

        if ($response['flag'] == 'success') {
            return response()->json(response_formatter(BOOKING_PLACE_SUCCESS_200, $response), 200);
        } else {
            return response()->json(response_formatter(BOOKING_PLACE_FAIL_200), 200);
        }
    }


    /**
     * Display a listing of the resource.
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'limit'          => 'required|numeric|min:1|max:200',
            'offset'         => 'required|numeric|min:1|max:100000',
            'booking_status' => 'required|in:all,' . implode(',', array_column(BOOKING_STATUSES, 'key')),
            'service_type'   => 'required|in:all,regular,repeat',
            'string'         => 'string'
        ]);

        if ($validator->fails()) {
            return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
        }

        $bookings = $this->booking
            ->with([
                'customer',
                'repeat',
                'childBookings.detail.service',
                'childBookings.provider.owner',
                'childBookings.serviceman.user',
            ])
            ->where(['customer_id' => $request->user()->id])
            ->whereNull('parent_booking_id')
            ->search(base64_decode($request['string']), ['readable_id'])
            ->when($request['booking_status'] != 'all', function ($query) use ($request) {
                return $query->ofBookingStatus($request['booking_status']);
            })
            ->when($request['service_type'] != 'all', function ($query) use ($request) {
                return $query->ofRepeatBookingStatus(
                    $request['service_type'] === 'repeat' ? 1 : ($request['service_type'] === 'regular' ? 0 : null)
                );
            })
            ->latest()
            ->paginate($request['limit'], ['*'], 'offset', $request['offset'])
            ->withPath('');

        // foreach ($bookings as $booking) {
        //     // Per-service breakdown for customer
        //     $booking['services_detail'] = $booking->childBookings->map(function ($child) {
        //         return [
        //             'child_booking_id' => $child->id,
        //             'booking_status'   => $child->booking_status,
        //             'booking_otp'      => $child->booking_otp,
        //             'provider'         => $child->provider ? [
        //                 'id'    => $child->provider->id,
        //                 'name'  => $child->provider->owner->first_name . ' ' . $child->provider->owner->last_name,
        //                 'phone' => $child->provider->owner->phone,
        //                 'image' => $child->provider->owner->image_full_path ?? null,
        //             ] : null,
        //             'serviceman' => $child->serviceman ? [
        //                 'id'    => $child->serviceman->id,
        //                 'name'  => $child->serviceman->user->first_name . ' ' . $child->serviceman->user->last_name,
        //                 'phone' => $child->serviceman->user->phone,
        //                 'image' => $child->serviceman->user->image_full_path ?? null,
        //             ] : null,
        //             'services' => $child->detail->map(fn($d) => [
        //                 'service_id'   => $d->service_id,
        //                 'service_name' => $d->service_name,
        //                 'quantity'     => $d->quantity,
        //                 'total_cost'   => $d->total_cost,
        //             ])->values(),
        //         ];
        //     })->values();

        //     if ($booking->repeat->isNotEmpty()) {
        //         $sortedRepeats = $booking->repeat->sortBy(function ($repeat) {
        //             $parts  = explode('-', $repeat->readable_id);
        //             $suffix = end($parts);
        //             return $this->readableIdToNumber($suffix);
        //         });
        //         $booking->repeats = $sortedRepeats->values()->toArray();
        //     }
        //     unset($booking->repeat);
        //     unset($booking->childBookings);
        // }

        foreach ($bookings as $booking) {
            // Filter child bookings by status if filter applied
            $filteredChildren = $request['booking_status'] != 'all'
                ? $booking->childBookings->where('booking_status', $request['booking_status'])->values()
                : $booking->childBookings;

            $booking['services_detail'] = $filteredChildren->map(function ($child) {
                return [
                    'child_booking_id' => $child->id,
                    'chat_reference_id' => $child->id,
                    'chat_reference_type' => 'booking_id',
                    'booking_status'   => $child->booking_status,
                    'booking_otp'      => $child->booking_otp,
                    'provider'         => $child->provider ? [
                        'id'    => $child->provider->id,
                        'user_id' => $child->provider->user_id,
                        'name'  => $child->provider->owner->first_name . ' ' . $child->provider->owner->last_name,
                        'phone' => $child->provider->owner->phone,
                        'image' => $child->provider->owner->image_full_path ?? null,
                    ] : null,
                    'serviceman'       => $child->serviceman ? [
                        'id'    => $child->serviceman->id,
                        'name'  => $child->serviceman->user->first_name . ' ' . $child->serviceman->user->last_name,
                        'phone' => $child->serviceman->user->phone,
                        'image' => $child->serviceman->user->image_full_path ?? null,
                    ] : null,
                    'services'         => $child->detail->map(fn($d) => [
                        'service_id'   => $d->service_id,
                        'service_name' => $d->service_name,
                        'quantity'     => $d->quantity,
                        'total_cost'   => $d->total_cost,
                    ])->values(),
                ];
            })->values();

            if ($booking->repeat->isNotEmpty()) {
                $sortedRepeats = $booking->repeat->sortBy(function ($repeat) {
                    $parts  = explode('-', $repeat->readable_id);
                    $suffix = end($parts);
                    return $this->readableIdToNumber($suffix);
                });
                $booking->repeats = $sortedRepeats->values()->toArray();
            }
            unset($booking->repeat);
            unset($booking->childBookings);
        }

        return response()->json(response_formatter(DEFAULT_200, $bookings), 200);
    }

    /**
     * Show the specified resource.
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */

    public function show(Request $request, string $id): JsonResponse
    {
        $booking = $this->booking
            ->where(['customer_id' => $request->user()->id])
            ->whereNull('parent_booking_id')
            ->with([
                'detail.service',
                'schedule_histories.user',
                'status_histories.user',
                'service_address',
                'customer',
                'provider',
                'category',
                'subCategory:id,name',
                'serviceman.user',
                'booking_partial_payments',
                'childBookings.detail.service',
                'childBookings.provider.owner',
                'childBookings.serviceman.user',
                'childBookings.status_histories.user',
                'childBookings.category',
                'childBookings.subCategory:id,name',
                'repeat.scheduleHistories',
                'repeat.repeatHistories',
            ])
            ->where(['id' => $id])
            ->first();

        if (!isset($booking)) {
            return response()->json(response_formatter(DEFAULT_204), 200);
        }

        // Merge child details into parent for combined service list
        if ($booking->childBookings->isNotEmpty()) {
            $allDetails = $booking->childBookings->flatMap(fn($child) => $child->detail);
            $booking->setRelation('detail', $allDetails->values());
            $booking->setAttribute('booking_status', $this->resolveParentBookingStatus($booking->childBookings));

            // Normalize child histories so booking_id always points to the owning child
            // while source_booking_id always points to the parent booking in content.id.
            $allStatusHistories = $booking->childBookings
                ->flatMap(fn($child) => $this->mapChildBookingStatusHistories($child, $booking->id))
                ->sortBy('created_at')
                ->values();
            $booking->setRelation('status_histories', $allStatusHistories);

            // Per-service full detail for customer
            $booking['services_detail'] = $booking->childBookings->map(function ($child) use ($booking) {
                return [
                    'child_booking_id' => $child->id,
                    'chat_reference_id' => $child->id,
                    'chat_reference_type' => 'booking_id',
                    'booking_status'   => $child->booking_status,
                    'booking_otp'      => $child->booking_otp,
                    'service_schedule' => $child->service_schedule,
                    // 'evidence_photos' => $child->evidence_photos ?? [],
                    // 'evidence_photos_full_path' => $child->evidence_photos_full_path ?? [],
                    'provider'         => $child->provider ? [
                        'id'    => $child->provider->id,
                        'user_id' => $child->provider->user_id,
                        'company_name'  => $child->provider->contact_person_name,
                        'company_phone' => $child->provider->contact_person_phone,
                        'logo_full_path' => $child->provider->logo_full_path ?? null,
                        'chatEligibility' => chatEligibility($child->provider->id),
                    ] : null,
                    'serviceman' => $child->serviceman ? [
                        'id'         => $child->serviceman->id,
                        'provider_id' => $child->serviceman->provider_id,
                        'user_id'    => $child->serviceman->user_id,
                        'user'       => $child->serviceman->user ? [
                            'first_name'              => $child->serviceman->user->first_name,
                            'last_name'               => $child->serviceman->user->last_name,
                            'phone'                   => $child->serviceman->user->phone,
                            'profile_image_full_path' => $child->serviceman->user->profile_image_full_path ?? null,
                        ] : null,
                    ] : null,
                    'status_histories'   => $this->mapChildBookingStatusHistories($child, $booking->id),
                    'schedule_histories' => $child->schedule_histories,
                    'services'           => $child->detail->map(fn($d) => [
                        'service_id'      => $d->service_id,
                        'service_name'    => $d->service_name,
                        'quantity'        => $d->quantity,
                        'service_cost'    => $d->service_cost,
                        'total_cost'      => $d->total_cost,
                        'tax_amount'      => $d->tax_amount,
                        'discount_amount' => $d->discount_amount,
                    ])->values(),
                ];
            })->values();

            // $booking->evidence_photos = $booking->childBookings
            //     ->flatMap(fn($child) => $child->evidence_photos ?? [])
            //     ->values()
            //     ->all();

            // $booking->setAttribute(
            //     'evidence_photos_full_path',
            //     $booking->childBookings
            //         ->flatMap(fn($child) => $child->evidence_photos_full_path ?? [])
            //         ->filter()
            //         ->values()
            //         ->all()
            // );

            // Push first child's provider/serviceman/category onto parent
            $firstChild = $booking->childBookings->first();

            if ($firstChild?->provider && is_null($booking->provider_id)) {
                $booking->setRelation('provider', $firstChild->provider);
            }

            if ($firstChild?->serviceman && is_null($booking->serviceman_id)) {
                $booking->setRelation('serviceman', $firstChild->serviceman);
            }

            if ($firstChild?->category && is_null($booking->category_id)) {
                $booking->setRelation('category', $firstChild->category);
            }

            if ($firstChild?->subCategory && is_null($booking->sub_category_id)) {
                $booking->setRelation('subCategory', $firstChild->subCategory);
            }
        } else {
            // If no child bookings, ensure services_detail is an empty array
            $booking['services_detail'] = [];
        }

        $offlinePayment = $booking->booking_offline_payments?->first();

        if ($offlinePayment) {
            $booking->booking_offline_payment_method = $offlinePayment->method_name;
            $booking->booking_offline_payment        = collect($offlinePayment->customer_information)
                ->map(fn($value, $key) => ["key" => $key, "value" => $value])
                ->values()->all();
            $booking->offline_payment_id          = $offlinePayment->offline_payment_id ?? null;
            $booking->offline_payment_status      = $offlinePayment->payment_status ?? null;
            $booking->offline_payment_denied_note = $offlinePayment->denied_note ?? null;
        }

        unset($booking->booking_offline_payments);
        unset($booking->childBookings);

        if (isset($booking->provider)) {
            $booking->provider->chatEligibility = chatEligibility($booking->provider_id);
            $booking->provider->chat_user_id = $booking->provider->user_id;
        }

        if ($booking->repeat->isNotEmpty()) {
            $repeatHistoryCollection = $booking->repeat->flatMap(function ($repeat) {
                return $repeat->repeatHistories->map(function ($history) {
                    $history->log_details = json_decode($history->log_details);
                    return $history;
                });
            });

            $booking['repeatHistory'] = $repeatHistoryCollection->toArray();

            $sortedRepeats = $booking->repeat->sortBy(function ($repeat) {
                $parts  = explode('-', $repeat->readable_id);
                $suffix = end($parts);
                return $this->readableIdToNumber($suffix);
            });

            $booking['repeats']      = $sortedRepeats->values()->toArray();
            $nextService             = collect($booking['repeats'])->firstWhere('booking_status', 'accepted');
            if (!$nextService) {
                $nextService = collect($booking['repeats'])->firstWhere('booking_status', 'pending');
            }

            $booking['nextService']  = $nextService;
            $booking['time']         = max(collect($booking['repeats'])->pluck('service_schedule')->flatten()->toArray());
            $booking['startDate']    = min(collect($booking['repeats'])->pluck('service_schedule')->flatten()->toArray());
            $booking['endDate']      = max(collect($booking['repeats'])->pluck('service_schedule')->flatten()->toArray());
            $booking['totalCount']   = count($booking['repeats']);
            $booking['bookingType']  = $booking['repeats'][0]['booking_type'];

            if ($booking['bookingType'] == 'weekly') {
                $booking['weekNames'] = collect($booking['repeats'])
                    ->pluck('service_schedule')
                    ->map(fn($s) => \Carbon\Carbon::parse($s)->format('l'))
                    ->unique()->sort()->values()->toArray();
            }

            $booking['completedCount'] = collect($booking['repeats'])->where('booking_status', 'completed')->count();
            $booking['canceledCount']  = collect($booking['repeats'])->where('booking_status', 'canceled')->count();

            unset($booking->repeat);

            $booking['repeats'] = array_map(function ($repeat) {
                if (isset($repeat['repeat_histories'])) unset($repeat['repeat_histories']);
                return $repeat;
            }, $booking['repeats']);
        }

        return response()->json(response_formatter(DEFAULT_200, $booking), 200);
    }

    /**
     * Show the specified resource.
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function singleDetails(Request $request, string $id): JsonResponse
    {
        $booking = $this->bookingRepeat->with([
            'detail.service',
            'scheduleHistories.user',
            'statusHistories.user',
            'booking.service_address',
            'booking.customer',
            'provider',
            'serviceman.user'
        ])->where(['id' => $id])->first();

        if (isset($booking)) {
            if (isset($booking->provider)) {
                $booking->provider->chatEligibility = chatEligibility($booking->provider_id);
                $booking->provider->chat_user_id = $booking->provider->user_id;
            }
            return response()->json(response_formatter(DEFAULT_200, $booking), 200);
        }
        return response()->json(response_formatter(DEFAULT_204), 200);
    }
    /**
     * Show the specified resource.
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function track(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
        }

        $booking = $this->booking
            ->with(['detail.service', 'schedule_histories.user', 'status_histories.user', 'service_address', 'customer', 'provider', 'zone', 'serviceman.user'])
            ->where(['readable_id' => $id])
            ->whereHas('service_address', fn($query) => $query->where('contact_person_number', $request['phone']))
            ->first();

        if (isset($booking)) return response()->json(response_formatter(DEFAULT_200, $booking), 200);

        return response()->json(response_formatter(DEFAULT_404, $booking), 404);
    }

    /**
     * Show the specified resource.
     * @param Request $request
     * @param string $booking_id
     * @return JsonResponse
     */

    public function statusUpdate(Request $request, string $booking_id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'booking_status' => 'required|in:canceled',
        ]);

        if ($validator->fails()) {
            return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
        }


        $booking = $this->booking
            ->with(['childBookings', 'repeat'])
            ->where('id', $booking_id)
            ->where('customer_id', $request->user()->id)
            ->whereNull('parent_booking_id') // only parent booking
            ->first();

        if (!isset($booking)) {
            return response()->json(response_formatter(DEFAULT_204), 200);
        }

        // Guards
        if ($booking->booking_status == 'accepted' && $request['booking_status'] == 'canceled') {
            return response()->json(response_formatter(BOOKING_ALREADY_ACCEPTED), 200);
        }
        if ($booking->booking_status == 'ongoing' && $request['booking_status'] == 'canceled') {
            return response()->json(response_formatter(BOOKING_ALREADY_ONGOING), 200);
        }
        if ($booking->booking_status == 'completed' && $request['booking_status'] == 'canceled') {
            return response()->json(response_formatter(BOOKING_ALREADY_COMPLETED), 200);
        }
        if ($booking->booking_status == 'canceled' && $request['booking_status'] == 'canceled') {
            return response()->json(response_formatter(BOOKING_ALREADY_CANCELED_200), 200);
        }

        $booking->booking_status = $request['booking_status'];

        $bookingStatusHistory = $this->bookingStatusHistory;
        $bookingStatusHistory->booking_id = $booking_id;
        $bookingStatusHistory->changed_by = $request->user()->id;
        $bookingStatusHistory->booking_status = $request['booking_status'];

        DB::transaction(function () use ($bookingStatusHistory, $booking, $request) {

            // Parent booking cancel karo - refund will be processed here
            $booking->save();
            $bookingStatusHistory->save();

            // Sari child bookings bhi cancel karo - skip refund for children
            if ($booking->childBookings->isNotEmpty()) {
                foreach ($booking->childBookings as $child) {
                    if (!in_array($child->booking_status, ['completed', 'canceled'])) {
                        $child->booking_status = 'canceled';
                        $child->skip_refund = true; // Skip refund for child, parent will handle it
                        $child->save();

                        $childHistory = new BookingStatusHistory();
                        $childHistory->booking_id     = $child->id;
                        $childHistory->changed_by     = $request->user()->id;
                        $childHistory->booking_status = 'canceled';
                        $childHistory->save();
                    }
                }
            }

            // Repeat bookings bhi cancel karo
            if ($request['booking_status'] == 'canceled' && $booking->repeat->isNotEmpty()) {
                foreach ($booking->repeat as $repeat) {
                    $repeat->booking_status = 'canceled';
                    $repeat->save();

                    $repeatHistory = new BookingStatusHistory();
                    $repeatHistory->booking_id        = 0;
                    $repeatHistory->booking_repeat_id = $repeat->id;
                    $repeatHistory->changed_by        = $request->user()->id;
                    $repeatHistory->booking_status    = 'canceled';
                    $repeatHistory->save();
                }
            }
        });

        return response()->json(response_formatter(BOOKING_STATUS_UPDATE_SUCCESS_200, $booking), 200);
    }

    /**
     * Customer cancels a single service (child booking) from a multi-service booking
     */

    public function cancelSingleService(Request $request, string $childBookingId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'booking_status' => 'required|in:canceled',
        ]);

        if ($validator->fails()) {
            return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
        }

        // Find the child booking and ensure it belongs to the customer
        $childBooking = $this->booking
            ->with(['parentBooking.childBookings'])
            ->where('id', $childBookingId)
            ->where('customer_id', $request->user()->id)
            ->whereNotNull('parent_booking_id')
            ->first();

        if (!isset($childBooking)) {
            return response()->json(response_formatter(DEFAULT_204), 200);
        }

        // Check if booking can be canceled
        if ($childBooking->booking_status == 'accepted') {
            return response()->json(response_formatter(BOOKING_ALREADY_ACCEPTED), 200);
        }
        if ($childBooking->booking_status == 'ongoing') {
            return response()->json(response_formatter(BOOKING_ALREADY_ONGOING), 200);
        }
        if ($childBooking->booking_status == 'completed') {
            return response()->json(response_formatter(BOOKING_ALREADY_COMPLETED), 200);
        }
        if ($childBooking->booking_status == 'canceled') {
            return response()->json(response_formatter(BOOKING_ALREADY_CANCELED_200), 200);
        }

        $childBooking->booking_status = $request['booking_status'];

        $bookingStatusHistory = $this->bookingStatusHistory;
        $bookingStatusHistory->booking_id = $childBookingId;
        $bookingStatusHistory->changed_by = $request->user()->id;
        $bookingStatusHistory->booking_status = $request['booking_status'];

        DB::transaction(function () use ($bookingStatusHistory, $childBooking, $request) {
            $childBooking->save();
            $bookingStatusHistory->save();

            // Update parent booking status based on remaining children
            if ($childBooking->parentBooking) {
                $parentBooking = $childBooking->parentBooking;

                // Refresh parent booking with latest child bookings
                $parentBooking->load('childBookings');
                $allChildren = $parentBooking->childBookings;
                $completedCount = $allChildren->where('booking_status', 'completed')->count();
                $canceledCount = $allChildren->where('booking_status', 'canceled')->count();
                $ongoingCount = $allChildren->whereIn('booking_status', ['ongoing'])->count();
                $total = $allChildren->count();

                $oldParentStatus = $parentBooking->booking_status;

                // Determine new parent status
                if ($canceledCount == $total) {
                    $parentBooking->booking_status = 'canceled';
                    $parentBooking->skip_refund = true;
                    $parentBooking->save();
                } elseif ($completedCount + $canceledCount == $total) {
                    // All children either completed or cancelled
                    $parentBooking->booking_status = 'completed';
                    $parentBooking->is_paid = 1;
                    $parentBooking->save();
                } elseif ($ongoingCount > 0 || $completedCount > 0) {
                    // Some children are ongoing or completed
                    if (!in_array($parentBooking->booking_status, ['completed', 'canceled'])) {
                        $parentBooking->booking_status = 'ongoing';
                        $parentBooking->save();
                    }
                }

                // Save parent status change history
                $parentHistory = new BookingStatusHistory();
                $parentHistory->booking_id = $parentBooking->id;
                $parentHistory->changed_by = $request->user()->id;
                $parentHistory->booking_status = $parentBooking->booking_status;
                $parentHistory->save();
            }
        });

        return response()->json(response_formatter(BOOKING_STATUS_UPDATE_SUCCESS_200, $childBooking), 200);
    }

    // public function cancelSingleService(Request $request, string $childBookingId): JsonResponse
    // {
    //     $validator = Validator::make($request->all(), [
    //         'booking_status' => 'required|in:canceled',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
    //     }

    //     // Find the child booking and ensure it belongs to the customer
    //     $childBooking = $this->booking
    //         ->with(['parentBooking'])
    //         ->where('id', $childBookingId)
    //         ->where('customer_id', $request->user()->id)
    //         ->whereNotNull('parent_booking_id') // Must be a child booking
    //         ->first();

    //     if (!isset($childBooking)) {
    //         return response()->json(response_formatter(DEFAULT_204), 200);
    //     }

    //     // Check if booking can be canceled
    //     if ($childBooking->booking_status == 'accepted') {
    //         return response()->json(response_formatter(BOOKING_ALREADY_ACCEPTED), 200);
    //     }
    //     if ($childBooking->booking_status == 'ongoing') {
    //         return response()->json(response_formatter(BOOKING_ALREADY_ONGOING), 200);
    //     }
    //     if ($childBooking->booking_status == 'completed') {
    //         return response()->json(response_formatter(BOOKING_ALREADY_COMPLETED), 200);
    //     }
    //     if ($childBooking->booking_status == 'canceled') {
    //         return response()->json(response_formatter(BOOKING_ALREADY_CANCELED_200), 200);
    //     }

    //     $childBooking->booking_status = $request['booking_status'];

    //     $bookingStatusHistory = $this->bookingStatusHistory;
    //     $bookingStatusHistory->booking_id = $childBookingId;
    //     $bookingStatusHistory->changed_by = $request->user()->id;
    //     $bookingStatusHistory->booking_status = $request['booking_status'];

    //     DB::transaction(function () use ($bookingStatusHistory, $childBooking, $request) {
    //         // Cancel the child booking - this will trigger refund automatically
    //         $childBooking->save();
    //         $bookingStatusHistory->save();

    //         // Update parent booking status based on remaining children
    //         if ($childBooking->parentBooking) {
    //             $parentBooking = $childBooking->parentBooking;
    //             $allChildren = $parentBooking->childBookings;
    //             $completedCount = $allChildren->where('booking_status', 'completed')->count();
    //             $canceledCount = $allChildren->where('booking_status', 'canceled')->count();
    //             $ongoingCount = $allChildren->whereIn('booking_status', ['ongoing'])->count();
    //             $total = $allChildren->count();

    //             $oldParentStatus = $parentBooking->booking_status;

    //             // Determine new parent status
    //             if ($canceledCount == $total) {
    //                 $parentBooking->booking_status = 'canceled';
    //             } elseif ($completedCount + $canceledCount == $total) {
    //                 $parentBooking->booking_status = 'completed';
    //                 $parentBooking->is_paid = 1;
    //             } elseif ($ongoingCount > 0 || $completedCount > 0) {
    //                 if (!in_array($parentBooking->booking_status, ['completed', 'canceled'])) {
    //                     $parentBooking->booking_status = 'ongoing';
    //                 }
    //             }

    //             // Only save if status changed, but don't trigger refund again
    //             if ($oldParentStatus != $parentBooking->booking_status) {
    //                 $parentBooking->save(); // This won't trigger refund unless status becomes 'canceled'
    //             }

    //             // Save parent status change history
    //             $parentHistory = new BookingStatusHistory();
    //             $parentHistory->booking_id = $parentBooking->id;
    //             $parentHistory->changed_by = $request->user()->id;
    //             $parentHistory->booking_status = $parentBooking->booking_status;
    //             $parentHistory->save();
    //         }
    //     });

    //     // Return same response format as statusUpdate method
    //     return response()->json(response_formatter(BOOKING_STATUS_UPDATE_SUCCESS_200, $childBooking), 200);
    // }

    /**
     * @param Request $request
     * @param string $repeatId
     * @return JsonResponse
     */
    // public function singleBookingCancel(Request $request, string $repeatId): JsonResponse
    // {
    //     $customerId = $request->user()->id;
    //     // dd($repeatId, $customerId);
    //     $repeat = $this->bookingRepeat->where('id', $repeatId)->first();
    //     $bookingId = $repeat->booking_id;
    //     $booking = $this->booking->where('id', $bookingId)->where('customer_id', $customerId)->first();

    //     if ($booking && $repeat) {
    //         $statusCheck = $repeat->booking_status == 'canceled';
    //         if ($statusCheck) {
    //             return response()->json(response_formatter(BOOKING_ALREADY_CANCELED_200), 200);
    //         }

    //         DB::transaction(function () use ($repeat) {
    //             $repeat->booking_status = 'canceled';
    //             $repeat->save();
    //         });

    //         return response()->json(response_formatter(DEFAULT_200), 200);
    //     }
    //     return response()->json(response_formatter(DEFAULT_204), 200);
    // }

    public function singleBookingCancel(Request $request, string $repeatId): JsonResponse
    {
        $customerId = $request->user()->id;

        $repeat = $this->bookingRepeat->where('id', $repeatId)->first();

        // Check if repeat exists
        if (!$repeat) {
            return response()->json(response_formatter(DEFAULT_204), 200);
        }

        $bookingId = $repeat->booking_id;
        $booking = $this->booking->where('id', $bookingId)->where('customer_id', $customerId)->first();

        if ($booking && $repeat) {
            $statusCheck = $repeat->booking_status == 'canceled';
            if ($statusCheck) {
                return response()->json(response_formatter(BOOKING_ALREADY_CANCELED_200), 200);
            }

            DB::transaction(function () use ($repeat) {
                $repeat->booking_status = 'canceled';
                $repeat->save();
            });

            return response()->json(response_formatter(DEFAULT_200), 200);
        }

        return response()->json(response_formatter(DEFAULT_204), 200);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function storeOfflinePaymentData(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'offline_payment_id' => 'required',
            'customer_information' => 'required',
            'booking_id' => 'required',
            'is_partial' => 'required|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
        }

        // Retrieve booking
        $booking = $this->booking->find($request->booking_id);
        if (!$booking) {
            return response()->json(response_formatter(DEFAULT_204), 200);
        }

        $offlinePaymentData = $this->offlinePayment->find($request['offline_payment_id']);
        if (!$offlinePaymentData) {
            return response()->json(response_formatter(DEFAULT_400, null, 'Invalid offline payment ID.'), 400);
        }

        $fields = array_column($offlinePaymentData->customer_information, 'field_name');
        $customerInformation = (array)json_decode(base64_decode($request['customer_information']))[0];

        foreach ($fields as $field) {
            if (!key_exists($field, $customerInformation)) {
                return response()->json(response_formatter(DEFAULT_400, $fields, null), 400);
            }
        }

        // Handle partial payment if applicable
        if ($request->is_partial) {
            $user = auth('api')->user();
            $walletBalance = $user->wallet_balance;

            if ($walletBalance <= 0 || $walletBalance >= $booking->total_booking_amount) {
                return response()->json(response_formatter(DEFAULT_400, null, 'Invalid partial payment data.'), 400);
            }

            $paidAmount = $walletBalance;
            $dueAmount = $booking->total_booking_amount - $paidAmount;

            // Save wallet payment
            BookingPartialPayment::create([
                'booking_id' => $booking->id,
                'paid_with' => 'wallet',
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
            ]);

            // Save remaining payment
            BookingPartialPayment::create([
                'booking_id' => $booking->id,
                'paid_with' => 'offline_payment',
                'paid_amount' => $dueAmount,
                'due_amount' => 0,
            ]);

            placeBookingTransactionForPartialDigital($booking);
        }

        // Check if the booking_id already exists
        $existingPayment = BookingOfflinePayment::where('booking_id', $request->booking_id)->first();

        $customerInformation = (array)json_decode(base64_decode($request['customer_information']))[0];

        if ($existingPayment) {
            // If it exists, update with new data
            $existingPayment->offline_payment_id = $request['offline_payment_id'];
            $existingPayment->method_name = OfflinePayment::find($request['offline_payment_id'])?->method_name;
            $existingPayment->customer_information = $customerInformation;
            $existingPayment->payment_status = 'pending';
            $existingPayment->save();
        } else {
            // If no existing record, create a new one
            $bookingOfflinePayment = new BookingOfflinePayment();
            $bookingOfflinePayment->booking_id = $request->booking_id;
            $bookingOfflinePayment->offline_payment_id = $request['offline_payment_id'];
            $bookingOfflinePayment->method_name = OfflinePayment::find($request['offline_payment_id'])?->method_name;
            $bookingOfflinePayment->customer_information = $customerInformation;
            $bookingOfflinePayment->payment_status = 'pending';
            $bookingOfflinePayment->save();
        }

        $booking->update(['payment_method' => 'offline_payment']);

        return response()->json(response_formatter(OFFLINE_PAYMENT_SUCCESS_200), 200);
    }

    public function switchPaymentMethod(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => 'required',
            'payment_method' => 'required',
            'offline_payment_id' => 'required_if:payment_method,offline_payment',
            'customer_information' => 'required_if:payment_method,offline_payment',
            'is_partial' => 'required|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
        }

        // Retrieve booking
        $booking = $this->booking->find($request->booking_id);
        if (!$booking) {
            return response()->json(response_formatter(DEFAULT_204), 200);
        }

        // Handle partial payment if applicable
        if ($request->is_partial) {
            $user = auth('api')->user();
            $walletBalance = $user->wallet_balance;

            if ($walletBalance <= 0 || $walletBalance >= $booking->total_booking_amount) {
                return response()->json(response_formatter(DEFAULT_400, null, 'Invalid partial payment data.'), 400);
            }

            $paidAmount = $walletBalance;
            $dueAmount = $booking->total_booking_amount - $paidAmount;

            // Save wallet payment
            BookingPartialPayment::create([
                'booking_id' => $booking->id,
                'paid_with' => 'wallet',
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
            ]);

            // Save remaining payment
            BookingPartialPayment::create([
                'booking_id' => $booking->id,
                'paid_with' => 'digital',
                'paid_amount' => $dueAmount,
                'due_amount' => 0,
            ]);
        }

        // Handle payment method updates
        if ($request->payment_method == 'cash_after_service') {
            $booking->update(['payment_method' => 'cash_after_service', 'transaction_id' => 'cash-payment', 'is_verified' => 1]);
            if ($booking->booking_partial_payments->isNotEmpty()) {
                // Delete rows where `paid_with` is not 'wallet'
                $booking->booking_partial_payments()
                    ->where('paid_with', '!=', 'wallet')
                    ->delete();
            }
            if ($request->is_partial) {
                placeBookingTransactionForPartialCas($booking);
            }
        } elseif ($request->payment_method == 'wallet_payment') {
            $booking->update(['payment_method' => 'wallet_payment', 'transaction_id' => 'wallet-payment']);
            placeBookingTransactionForWalletPayment($booking);
        } else {
            return response()->json(response_formatter(DEFAULT_400, null, 'Invalid payment method.'), 400);
        }

        return response()->json(response_formatter(PAYMENT_METHOD_UPDATE_200), 200);
    }

    public function digitalPaymentBookingResponse(Request $request): JsonResponse|array
    {
        $validator = Validator::make($request->all(), [
            'transaction_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
        }

        $payment_info = PaymentRequest::where('transaction_id', $request->transaction_id)->first();

        if (!$payment_info) {
            return response()->json(response_formatter(DEFAULT_204), 200);
        }

        $additional_data = json_decode($payment_info->additional_data, true);

        $booking_repeat_id = $additional_data['booking_repeat_id'] ?? null;
        $register_new_customer = $additional_data['register_new_customer'] ?? 0;
        $new_user_phone = $register_new_customer == 1 ? $additional_data['phone'] : null;

        $booking = null;
        $booking_id = null;
        if (isset($payment_info) && $payment_info->attribute_id != null) {
            $booking = Booking::where('readable_id', $payment_info->attribute_id)->first();
            $booking_id = $booking ? $booking->id : null;
        }

        $loginToken = null;
        if ($register_new_customer == 1 && $new_user_phone != null) {
            $user = new User();
            $user->first_name = $additional_data['first_name'];
            $user->last_name = '';
            $user->phone = $additional_data['phone'];
            $user->password = bcrypt($additional_data['password']);
            $user->user_type = 'customer';
            $user->is_active = 1;
            $user->save();

            if ($user && $booking) {
                $booking->customer_id = $user->id;
                $booking->is_guest = 0;
                $booking->save();
            }

            $loginToken = $user->createToken('CUSTOMER_PANEL_ACCESS')->accessToken;
        }

        $response =  [
            'booking_id' => $booking_id,
            'booking_repeat_id' => $booking_repeat_id,
            'new_user_phone' => $new_user_phone,
            'login_token' => $loginToken,
        ];

        return response()->json(response_formatter(DEFAULT_200, $response), 200);
    }

    private function mapChildBookingStatusHistories(Booking $childBooking, string $parentBookingId)
    {
        return collect($childBooking->status_histories)->map(function ($history) use ($childBooking, $parentBookingId) {
            $history->setAttribute('booking_id', $childBooking->id);
            $history->setAttribute('source_booking_id', $parentBookingId);

            return $history;
        })->values();
    }

    private function resolveParentBookingStatus($childBookings): string
    {
        $childBookings = collect($childBookings)->values();

        if ($childBookings->isEmpty()) {
            return 'pending';
        }

        if ($childBookings->count() === 1) {
            return $childBookings->first()->booking_status ?? 'pending';
        }

        $total = $childBookings->count();
        $completedCount = $childBookings->where('booking_status', 'completed')->count();
        $canceledCount = $childBookings->where('booking_status', 'canceled')->count();
        $ongoingCount = $childBookings->where('booking_status', 'ongoing')->count();
        $acceptedCount = $childBookings->where('booking_status', 'accepted')->count();

        if ($canceledCount === $total) {
            return 'canceled';
        }

        if (($completedCount + $canceledCount) === $total && $completedCount > 0) {
            return 'completed';
        }

        if ($ongoingCount > 0 || $completedCount > 0) {
            return 'ongoing';
        }

        if ($acceptedCount > 0) {
            return 'accepted';
        }

        return 'pending';
    }
}
