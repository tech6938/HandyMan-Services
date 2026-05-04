<?php

namespace Modules\ProviderManagement\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Modules\BookingModule\Entities\Booking;
use Modules\BookingModule\Entities\BookingIgnore;
use Modules\BusinessSettingsModule\Entities\PackageSubscriber;
use Modules\BusinessSettingsModule\Entities\Storage;
use Modules\ReviewModule\Entities\Review;
use Modules\UserManagement\Entities\Serviceman;
use Modules\UserManagement\Entities\User;
use App\Traits\HasUuid;
use Modules\ZoneManagement\Entities\Zone;

class Provider extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $casts = [
        'order_count' => 'integer',
        'service_man_count' => 'integer',
        'service_capacity_per_day' => 'integer',
        'rating_count' => 'integer',
        'avg_rating' => 'float',
        'commission_status' => 'integer',
        'commission_percentage' => 'float',
        'is_active' => 'integer',
        'is_approved' => 'integer',
        'coordinates' => 'json'
    ];

    protected $fillable = [];

    protected $hidden = [];

    protected $appends = ['logo_full_path', 'owner_identification_images'];

    public function scopeOfStatus($query, $status)
    {
        $query->where('is_active', '=', $status);
    }

    public function scopeOfApproval($query, $status)
    {
        $query->where('is_approved', '=', $status);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->where('user_type', 'provider-admin');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }

    public function bank_detail(): HasOne
    {
        return $this->hasOne(BankDetail::class, 'provider_id');
    }

    public function bookings($booking_status = null): HasMany
    {
        if ($booking_status == null) {
            return $this->hasMany(Booking::class, 'provider_id');
        }

        return $this->hasMany(Booking::class, 'provider_id')->where('booking_status', $booking_status);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(FavoriteProvider::class, 'provider_id', 'id');
    }

    public function subscribed_services(): HasMany
    {
        return $this->hasMany(SubscribedService::class, 'provider_id')->where('is_subscribed', 1);
    }

    public function servicemen(): HasMany
    {
        return $this->hasMany(Serviceman::class, 'provider_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'provider_id', 'id');
    }

    public function storage()
    {
        return $this->hasOne(Storage::class, 'model_id');
    }

    public function packageSubscriptions()
    {
        return $this->hasOne(PackageSubscriber::class);
    }
    public function ignoredBookings()
    {
        return $this->hasMany(BookingIgnore::class);
    }


    // public function getLogoFullPathAttribute()
    // {
    //     if ($this->logo && file_exists(public_path('images/provider/logo/' . $this->logo))) {
    //         return asset('images/provider/logo/' . $this->logo);
    //     }

    //     return asset('assets/admin-module/img/placeholder.png'); // fallback image
    // }

    public function getLogoFullPathAttribute()
    {
        $image = $this->logo;
        $defaultPath =  asset('public/assets/provider-module/img/user2x.png');

        if (!$image) {
            if (request()->is('api/*')) {
                $defaultPath = null;
            }
            return $defaultPath;
        }

        $s3Storage = $this->storage;
        $path = 'provider/logo/';
        $imagePath = $path . $image;

        return getSingleImageFullPath(imagePath: $imagePath, s3Storage: $s3Storage, defaultPath: $defaultPath);
    }

    // public function getOwnerIdentificationImagesAttribute()
    // {
    //     if (!$this->relationLoaded('owner')) {
    //         $this->load('owner');
    //     }

    //     $owner = $this->owner;
    //     if (!$owner || empty($owner->identification_image)) {
    //         return [];
    //     }

    //     // Debug: Check what's in identification_image
    //     \Log::info('Identification Image Data:', [
    //         'owner_id' => $owner->id,
    //         'identification_image' => $owner->identification_image,
    //         'type' => gettype($owner->identification_image)
    //     ]);

    //     $images = $owner->identification_image ?? [];
    //     $fullPaths = [];

    //     // Check if it's a string that needs to be decoded
    //     if (is_string($images)) {
    //         $images = json_decode($images, true) ?? [];
    //     }

    //     foreach ($images as $image) {
    //         // If $image is an array (like ['image' => 'filename.jpg']), extract the filename
    //         if (is_array($image)) {
    //             if (isset($image['image'])) {
    //                 $filename = $image['image'];
    //             } elseif (isset($image[0])) {
    //                 $filename = $image[0];
    //             } else {
    //                 continue; // Skip if we can't find the filename
    //             }
    //         } else {
    //             $filename = $image;
    //         }

    //         if ($filename) {
    //             $fullPaths[] = asset('images/provider/identity/' . $filename);
    //         }
    //     }

    //     return $fullPaths;
    // }
    public function getOwnerIdentificationImagesAttribute()
    {
        $image = $this->cover_image;
        $defaultPath =  asset('public/assets/provider-module/img/user2x.png');

        if (!$image) {
            if (request()->is('api/*')) {
                $defaultPath = null;
            }
            return $defaultPath;
        }

        $s3Storage = $this->storage;
        $path = 'provider/logo/';
        $imagePath = $path . $image;

        return getSingleImageFullPath(imagePath: $imagePath, s3Storage: $s3Storage, defaultPath: $defaultPath);
    }


    public static function boot()
    {
        parent::boot();

        self::creating(function ($model) {
            // ... code here
        });

        self::created(function ($model) {
            // ... code here
        });

        self::updating(function ($model) {
            if ($model->isDirty('zone_id')) {
                DB::table('subscribed_services')->where(['provider_id' => $model->id])->update(['is_subscribed' => 0]);
            }
        });

        self::updated(function ($model) {
            // ... code here
        });

        self::deleting(function ($model) {
            // ... code here
        });

        self::deleted(function ($model) {
            $model->servicemen->each(function ($serviceman) {
                $serviceman->user->update(['is_active' => 0]);
            });
        });

        static::saved(function ($model) {
            $storageType = getDisk();
            if ($model->isDirty('logo') && $storageType != 'public') {
                saveSingleImageDataToStorage(model: $model, modelColumn: 'logo', storageType: $storageType);
            }
        });
    }
}
