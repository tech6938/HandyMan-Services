<?php

namespace Modules\ServiceManagement\Http\Controllers\Web\Admin;

use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\ServiceManagement\Entities\Service;
use Modules\ServiceManagement\Entities\ServiceCommission;

class ServiceCommissionController extends Controller
{
    private ServiceCommission $serviceCommission;
    private Service $service;

    public function __construct(ServiceCommission $serviceCommission, Service $service)
    {
        $this->serviceCommission = $serviceCommission;
        $this->service = $service;
    }

    public function index(Request $request): View|Factory|Application
    {
        // $this->authorize('service_view');

        $search = $request->has('search') ? $request['search'] : '';

        $commissions = $this->serviceCommission->with('service')->latest()
            ->when($request->has('search') && $request['search'] != '', function ($query) use ($request) {
                $query->where(function ($query) use ($request) {
                    $query->where('commission_type', 'LIKE', '%' . $request['search'] . '%')
                        ->orWhere('commission', 'LIKE', '%' . $request['search'] . '%')
                        ->orWhereHas('service', function ($query) use ($request) {
                            $query->where('name', 'LIKE', '%' . $request['search'] . '%');
                        });
                });
            })
            ->paginate(pagination_limit())
            ->appends(['search' => $search]);

        return view('servicemanagement::admin.servicecommission.list', compact('commissions', 'search'));
    }

    public function create(Request $request): View|Factory|Application
    {
        // $this->authorize('service_add');

        $services = $this->service->ofStatus(1)->latest()->get();

        return view('servicemanagement::admin.servicecommission.create', compact('services'));
    }

    public function store(Request $request): RedirectResponse
    {
        // $this->authorize('service_add');

        $request->validate([
            'service_id' => ['required', 'uuid', 'exists:services,id', Rule::unique('service_commissions', 'service_id')],
            'commission' => 'required|numeric|min:0',
            'commission_type' => 'required|in:percentage,fixed',
        ]);

        $commission = $this->serviceCommission;
        $commission->service_id = $request->service_id;
        $commission->commission = $request->commission;
        $commission->commission_type = $request->commission_type;
        $commission->save();

        Toastr::success(translate(DEFAULT_STORE_200['message']));

        return redirect()->route('admin.service.commission.index');
    }

    public function edit(string $id): View|Factory|Application
    {
        // $this->authorize('service_update');

        $commission = $this->serviceCommission->findOrFail($id);
        $services = $this->service->ofStatus(1)->latest()->get();

        return view('servicemanagement::admin.servicecommission.edit', compact('commission', 'services'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        // $this->authorize('service_update');

        $commission = $this->serviceCommission->findOrFail($id);

        $request->validate([
            'service_id' => ['required', 'uuid', 'exists:services,id', Rule::unique('service_commissions', 'service_id')->ignore($commission->id)],
            'commission' => 'required|numeric|min:0',
            'commission_type' => 'required|in:percentage,fixed',
        ]);

        $commission->service_id = $request->service_id;
        $commission->commission = $request->commission;
        $commission->commission_type = $request->commission_type;
        $commission->save();

        Toastr::success(translate(DEFAULT_UPDATE_200['message']));

        return redirect()->route('admin.service.commission.index');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        // $this->authorize('service_delete');

        $commission = $this->serviceCommission->findOrFail($id);
        $commission->delete();

        Toastr::success(translate(DEFAULT_DELETE_200['message']));

        return redirect()->route('admin.service.commission.index');
    }
}
