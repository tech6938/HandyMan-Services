<?php

namespace Modules\CategoryManagement\Http\Controllers\Api\V1\Customer;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\CategoryManagement\Entities\Category;
use Modules\CategoryManagement\Entities\GovernmentCategoryText;

class GovernmentCategoryTextController extends Controller
{
    public function __construct(
        private readonly GovernmentCategoryText $governmentCategoryText,
        private readonly Category $category
    ) {
    }

    public function index(): JsonResponse
    {
        // Direct query using DB facade to bypass any model issues
        $governmentCategory = DB::table('categories')
            ->where('name', 'Government')
            ->first();

        if (!$governmentCategory) {
            return response()->json([
                'response_code' => 'default_404',
                'message' => 'Government category not found',
                'content' => null,
                'errors' => []
            ], 404);
        }

        $governmentText = DB::table('government_category_texts')
            ->where('category_id', $governmentCategory->id)
            ->first();

        if (!$governmentText) {
            return response()->json([
                'response_code' => 'default_200',
                'message' => 'No government text found',
                'content' => [
                    'category_id' => $governmentCategory->id,
                    'category_name' => $governmentCategory->name,
                    'content' => null
                ],
                'errors' => []
            ], 200);
        }

        // Get the category with its relationships if needed
        $governmentTextWithCategory = $this->governmentCategoryText
            ->with(['category'])
            ->where('id', $governmentText->id)
            ->first()->pluck('content');

        return response()->json(response_formatter(DEFAULT_200, $governmentTextWithCategory), 200);
    }
}