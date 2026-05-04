<?php

namespace Modules\PromotionManagement\Entities;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage as LaravelStorage;
use Modules\BusinessSettingsModule\Entities\Storage;

class AdvertisementAttachment extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'advertisement_id',
        'file_extension_type',
        'file_name',
        'type'
    ];

    protected $appends = [
        'provider_cover_image_full_path',
        'provider_profile_image_full_path',
        'promotional_video_full_path'
    ];

    public function cover_image_storage()
    {
        return $this->hasOne(Storage::class, 'model_id')->where('model_column', '=', 'provider_cover_image');
    }

    public function profile_image_storage()
    {
        return $this->hasOne(Storage::class, 'model_id')->where('model_column', '=', 'provider_profile_image');
    }

    public function promotional_video_storage()
    {
        return $this->hasOne(Storage::class, 'model_id')->where('model_column', '=', 'promotional_video');
    }

    public function getProviderCoverImageFullPathAttribute()
    {
        $image = $this->file_name;
        $defaultPath = asset('public/assets/placeholder.png');

        if (!$image) {
            if (request()->is('api/*')) {
                $defaultPath = null;
            }
            return $defaultPath;
        }

        $s3Storage = $this->cover_image_storage;
        $path = 'advertisement/';
        $imagePath = $path . $image;

        return getSingleImageFullPath(imagePath: $imagePath, s3Storage: $s3Storage, defaultPath: $defaultPath);
    }


    public function getProviderProfileImageFullPathAttribute()
    {
        $image = $this->file_name;
        $defaultPath = asset('public/assets/placeholder.png');

        if (!$image) {
            if (request()->is('api/*')) {
                $defaultPath = null;
            }
            return $defaultPath;
        }

        $s3Storage = $this->profile_image_storage;
        $path = 'advertisement/';
        $imagePath = $path . $image;

        return getSingleImageFullPath(imagePath: $imagePath, s3Storage: $s3Storage, defaultPath: $defaultPath);
    }


    public function getPromotionalVideoFullPathAttribute()
    {
        $image = $this->file_name;
        $defaultPath = asset('public/assets/placeholder.png');

        if (!$image) {
            if (request()->is('api/*')) {
                $defaultPath = null;
            }
            return $defaultPath;
        }

        $s3Storage = $this->promotional_video_storage;
        $path = 'advertisement/';
        $imagePath = $path . $image;

        return getSingleImageFullPath(imagePath: $imagePath, s3Storage: $s3Storage, defaultPath: $defaultPath);
    }

    protected static function booted()
    {
        static::saved(function ($model) {
            $storageType = getDisk();
            if($model->isDirty('file_name') && $storageType != 'public'){
                saveSingleImageDataToStorage(model: $model, modelColumn : $model->type, storageType : $storageType);
            }
        });
    }


        // public function getProviderCoverImageFullPathAttribute()
    // {
    //     $image = $this->file_name;
    //     $defaultPath = asset('assets/placeholder.png');

    //     if (!$image) {
    //         return request()->is('api/*') ? null : $defaultPath;
    //     }

    //     // public/advertisement/{file}
    //     $path = 'advertisement/' . $image;

    //     if (file_exists(public_path($path))) {
    //         return asset($path);
    //     }

    //     // If you store in storage/app/public
    //     if (LaravelStorage::disk('public')->exists($path)) {
    //         return asset('storage/' . $path);
    //     }

    //     return request()->is('api/*') ? null : $defaultPath;
    // }

    // public function getProviderProfileImageFullPathAttribute()
    // {
    //     $image = $this->file_name;
    //     $defaultPath = asset('assets/placeholder.png');

    //     if (!$image) {
    //         return request()->is('api/*') ? null : $defaultPath;
    //     }

    //     $path = 'advertisement/' . $image;

    //     if (file_exists(public_path($path))) {
    //         return asset($path);
    //     }

    //     if (LaravelStorage::disk('public')->exists($path)) {
    //         return asset('storage/' . $path);
    //     }

    //     return request()->is('api/*') ? null : $defaultPath;
    // }

    // public function getPromotionalVideoFullPathAttribute()
    // {
    //     $video = $this->file_name;

    //     // For video: return null if missing (not placeholder image)
    //     if (!$video) {
    //         return null;
    //     }

    //     $path = 'advertisement/' . $video;

    //     // If file is in public/advertisement
    //     if (file_exists(public_path($path))) {
    //         return asset($path);
    //     }

    //     // If file is in storage/app/public/advertisement
    //     if (LaravelStorage::disk('public')->exists($path)) {
    //         return asset('storage/' . $path);
    //     }

    //     return null;
    // }
}
