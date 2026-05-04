<?php

namespace Modules\BusinessSettingsModule\Entities;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class DataSetting extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = ['id', 'key', 'value', 'type', 'is_active'];
    protected $appends = ['image_full_path'];

    protected static function newFactory()
    {
        return \Modules\BusinessSettingsModule\Database\factories\BusinessSettingsFactory::new();
    }

    public function translations(): MorphMany
    {
        return $this->morphMany(Translation::class, 'translationable');
    }

    public function storage()
    {
        return $this->hasOne(Storage::class, 'model_id');
    }

    public function getValueAttribute($value)
    {
        if (count($this->translations) > 0) {
            foreach ($this->translations as $translation) {
                if ($translation['key'] == $this->key && $translation['value'] != null) {
                    return $translation['value'];
                }
            }
        }
        return $value;
    }

    public function getImageFullPathAttribute(): string
    {
        $default = asset('assets/admin-module/img/page-default.png');

        if ($this->type !== 'pages_setup_image' || empty($this->value)) {
            return $default;
        }

        $folderMap = [
            'about_us_image'             => 'about',
            'privacy_policy_image'       => 'privacy',
            'terms_and_conditions_image' => 'terms',
            'refund_policy_image'        => 'refund',
            'cancellation_policy_image'  => 'cancellation',
        ];

        $folder = $folderMap[$this->key] ?? null;

        if (!$folder) {
            return $default;
        }

        $path = "images/page-setup/{$folder}/{$this->value}";

        return file_exists(public_path($path))
            ? asset($path)
            : $default;
    }


    protected static function booted()
    {
        static::addGlobalScope('translate', function (Builder $builder) {
            $builder->with(['translations' => function ($query) {
                return $query->where('locale', app()->getLocale());
            }]);
        });
    }
}
