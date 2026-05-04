<?php

namespace Modules\CategoryManagement\Entities;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GovernmentCategoryText extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'category_id',
        'content',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
