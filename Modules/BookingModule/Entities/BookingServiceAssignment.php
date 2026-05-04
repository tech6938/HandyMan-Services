<?php

namespace Modules\BookingModule\Entities;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ProviderManagement\Entities\Provider;

class BookingServiceAssignment extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'booking_service_assignments';

    protected $fillable = [
        'id',
        'booking_id',
        'booking_detail_id',
        'provider_id',
        'status',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    public function detail(): BelongsTo
    {
        return $this->belongsTo(BookingDetail::class, 'booking_detail_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }
}
