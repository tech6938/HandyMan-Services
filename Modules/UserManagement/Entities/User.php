<?php

namespace Modules\UserManagement\Entities;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\HasApiTokens;
use Laravel\Passport\Token;
use Modules\BookingModule\Entities\Booking;
use Modules\BusinessSettingsModule\Entities\Storage as StorageModel;
use Modules\CartModule\Entities\AddedToCart;
use Modules\ChattingModule\Entities\ChannelConversation;
use Modules\CustomerModule\Entities\SearchedData;
use Modules\ProviderManagement\Entities\Provider;
use Modules\ReviewModule\Entities\Review;
use Modules\ServiceManagement\Entities\VisitedService;
use Modules\TransactionModule\Entities\Account;
use Modules\TransactionModule\Entities\Transaction;
use Modules\UserManagement\Entities\EmployeeRoleAccess;
use Modules\UserManagement\Entities\Role;
use Modules\UserManagement\Entities\Serviceman;
use Modules\UserManagement\Entities\UserAddress;
use Modules\ZoneManagement\Entities\Zone;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;
    use HasUuid;

    protected $hidden = [
        'password'
    ];

    protected $casts = [
        'is_phone_verified' => 'integer',
        'is_email_verified' => 'integer',
        'is_active' => 'integer',
        'identification_image' => 'array',
        'wallet_balance' => 'float',
        'loyalty_point' => 'float',
    ];

    protected $appends = ['profile_image_full_path', 'identification_image_full_path'];

    protected $fillable = [
        'uuid',
        'first_name',
        'last_name',
        'email',
        'phone',
        'identification_number',
        'identification_type',
        'identification_image',
        'date_of_birth',
        'gender',
        'profile_image',
        'fcm_token',
        'is_phone_verified',
        'is_email_verified',
        'phone_verified_at',
        'email_verified_at',
        'password',
        'is_active',
        'provider_id',
        'user_type',
        'wallet_balance',
        'loyalty_point',
        'ref_code',
        'referred_by'
    ];

    // Storage relationship for profile image
    public function storage_profile_image()
    {
        return $this->hasOne(StorageModel::class, 'model_id')->where('model_column', 'profile_image');
    }

    public function getProfileImageFullPathAttribute()
    {
        $image = $this->profile_image;

        if (!$image || $image == 'default.png') {
            return null;
        }

        // Check if storage record exists with S3 path
        if ($this->storage_profile_image && $this->storage_profile_image->storage_path) {
            return $this->storage_profile_image->storage_path;
        }

        // If no storage record, generate S3 URL directly
        if (getDisk() == 's3') {
            $folder = match ($this->user_type) {
                'customer' => 'user/profile_image/',
                'provider-serviceman' => 'serviceman/profile/',
                'provider-admin' => 'images/provider/logo/',
                default => 'user/profile_image/',
            };
            return 'https://handycart-media.s3.ap-south-1.amazonaws.com/' . $folder . $image;
        }

        // Fallback to local path
        $path = match ($this->user_type) {
            'customer' => 'user/profile_image/',
            'provider-admin' => 'images/provider/logo/',
            'provider-serviceman' => 'serviceman/profile/',
            default => 'user/profile_image/',
        };
        return asset($path . $image);
    }

    public function getIdentificationImageFullPathAttribute()
    {
        $identityImages = $this->identification_image ?? [];
        $defaultImagePath = asset('assets/admin-module/img/media/provider-id.png');

        if (empty($identityImages)) {
            if (request()->is('api/*')) {
                return [];
            }
            return [$defaultImagePath];
        }

        $path = match ($this->user_type) {
            'admin-employee' => 'employee/identity/',
            'provider-admin' => 'provider/identity/',
            'provider-serviceman' => 'serviceman/identity/',
            default => 'user/identity/',
        };

        $fullPaths = [];

        foreach ($identityImages as $imageData) {
            // Handle both formats: string or array with 'image' key
            $imageName = is_array($imageData) ? ($imageData['image'] ?? null) : $imageData;

            if (!$imageName) {
                $fullPaths[] = $defaultImagePath;
                continue;
            }

            // Check if stored in S3
            $imageStorage = is_array($imageData) && isset($imageData['storage']) ? $imageData['storage'] : 'public';

            if ($imageStorage == 's3') {
                try {
                    $fullPaths[] = Storage::disk('s3')->url($path . $imageName);
                } catch (\Exception $e) {
                    $fullPaths[] = $defaultImagePath;
                }
            } else {
                // Local storage
                if (file_exists(public_path($path . $imageName))) {
                    $fullPaths[] = asset($path . $imageName);
                } else {
                    $fullPaths[] = $defaultImagePath;
                }
            }
        }

        return $fullPaths;
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'employee_role_sections', 'employee_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'customer_id', 'id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'customer_id');
    }

    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(Zone::class, 'user_zones');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

    protected function scopeOfType($query, array $type)
    {
        $query->whereIn('user_type', $type);
    }

    protected function scopeOfStatus($query, $status)
    {
        $query->where('is_active', $status);
    }

    public function account()
    {
        return $this->hasOne(Account::class);
    }

    public function referred_by_user()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function provider()
    {
        return $this->hasOne(Provider::class);
    }

    public function serviceman()
    {
        return $this->hasOne(Serviceman::class);
    }

    public function transactions_for_from_user(): HasMany
    {
        return $this->hasMany(Transaction::class, 'from_user_id');
    }

    public function added_to_carts(): HasMany
    {
        return $this->hasMany(AddedToCart::class, 'user_id', 'id');
    }

    public function visited_services(): HasMany
    {
        return $this->hasMany(VisitedService::class, 'user_id', 'id');
    }

    public function searched_data(): HasMany
    {
        return $this->hasMany(SearchedData::class, 'user_id', 'id');
    }

    public function channelConversations(): HasMany
    {
        return $this->hasMany(ChannelConversation::class, 'user_id', 'id');
    }

    public function module_access(): HasMany
    {
        return $this->hasMany(EmployeeRoleAccess::class, 'employee_id', 'id');
    }

    public function storage()
    {
        return $this->hasOne(StorageModel::class, 'model_id');
    }

    public static function boot()
    {
        parent::boot();

        self::creating(function ($model) {
            $model->ref_code = generate_referer_code();
        });

        self::created(function ($model) {
            $account = new Account();
            $account->user_id = $model->id;
            $account->save();
        });

        self::updating(function ($model) {
            if ($model->isDirty('is_active')) {
                if ($model->is_active == 0) {
                    $model->fcm_token = '';
                }
            }
        });

        self::updated(function ($model) {
            if ($model->isDirty('is_active')) {
                if ($model->is_active == 0) {
                    $title = translate('Your account has been deactivated! Please contact with admin');
                    if ($model->fcm_token && $title) {
                        device_notification($model->fcm_token, $title, null, null, null, 'logout', null, $model->id);
                    }
                    $model->tokens->each(function ($token) {
                        $token->revoke();
                    });
                }
            }
        });

        self::deleting(function ($model) {
            // ... code here
        });

        self::deleted(function ($model) {
            // ... code here
        });

        static::saved(function ($model) {
            $storageType = getDisk();
            if ($model->isDirty('profile_image') && $storageType != 'public') {
                saveSingleImageDataToStorage(model: $model, modelColumn: 'profile_image', storageType: $storageType);
            }
        });
    }
}
