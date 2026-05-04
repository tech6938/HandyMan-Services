<?php

namespace Modules\CategoryManagement\Http\Controllers\Web\Admin;

use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\CategoryManagement\Entities\Category;
use Modules\CategoryManagement\Entities\GovernmentCategoryText;

class GovernmentCategoryTextController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly Category $category,
        private readonly GovernmentCategoryText $governmentCategoryText
    ) {
    }

    public function index()
    {
        $this->authorize('business_view');

        $governmentCategory = $this->getGovernmentCategory();
        $governmentText = null;

        if ($governmentCategory) {
            $governmentText = $this->governmentCategoryText
                ->where('category_id', $governmentCategory->id)
                ->first();
        }

        return view('categorymanagement::admin.government-category-text', compact('governmentCategory', 'governmentText'));
    }

    public function store(Request $request): RedirectResponse
    {
        // dd($request);
        $this->authorize('business_update');

        $governmentCategory = $this->getGovernmentCategory();

        if (!$governmentCategory) {
            Toastr::error(translate('Government category not found'));
            return back();
        }

        $request->validate([
            'content' => 'required|string',
        ]);

        $this->governmentCategoryText->updateOrCreate(
            ['category_id' => $governmentCategory->id],
            ['content' => $request->content]
        );

        Toastr::success(translate('Government category text updated successfully'));
        return back();
    }

    private function getGovernmentCategory(): ?Category
    {
        return $this->category
            ->withoutGlobalScope('translate')
            ->ofType('main')
            ->whereRaw('LOWER(name) = ?', ['government'])
            ->first()
            ?? $this->category
                ->withoutGlobalScope('translate')
                ->ofType('main')
                ->whereRaw('LOWER(name) LIKE ?', ['%government%'])
                ->first();
    }
}
