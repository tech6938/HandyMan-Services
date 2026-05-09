@extends('adminmodule::layouts.master')

@section('title', translate('service_commission_list'))

@push('css_or_js')
<link rel="stylesheet" href="{{ asset('assets/admin-module') }}/plugins/dataTables/jquery.dataTables.min.css" />
<link rel="stylesheet" href="{{ asset('assets/admin-module') }}/plugins/dataTables/select.dataTables.min.css" />
@endpush

@section('content')
<div class="main-content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-wrap d-flex justify-content-between flex-wrap align-items-center gap-3 mb-3">
                    <h2 class="page-title">{{ translate('service_commission_list') }}</h2>
                    <div>
                        @can('service_add')
                        <a href="{{ route('admin.service.commission.create') }}" class="btn btn--primary">
                            <span class="material-icons">add</span>
                            {{ translate('add_commission') }}
                        </a>
                        @endcan
                    </div>
                </div>

                <div class="d-flex flex-wrap justify-content-between align-items-center border-bottom mx-lg-4 mb-10 gap-3">
                    <ul class="nav nav--tabs">
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('admin.service.index') ? 'active' : '' }}" href="{{ route('admin.service.index') }}">
                                {{ translate('service_list') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('admin.service.commission.*') ? 'active' : '' }}" href="{{ route('admin.service.commission.index') }}">
                                {{ translate('service_commission') }}
                            </a>
                        </li>
                    </ul>

                    <div class="d-flex gap-2 fw-medium">
                        <span class="opacity-75">{{ translate('Total_Commissions') }}:</span>
                        <span class="title-color">{{ $commissions->total() }}</span>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="data-table-top d-flex flex-wrap gap-10 justify-content-between">
                            <form action="{{ route('admin.service.commission.index') }}" class="search-form search-form_style-two" method="GET">
                                <div class="input-group search-form__input_group">
                                    <span class="search-form__icon">
                                        <span class="material-icons">search</span>
                                    </span>
                                    <input type="search" class="theme-input-style search-form__input" value="{{ $search }}" name="search" placeholder="{{ translate('search_here') }}">
                                </div>
                                <button type="submit" class="btn btn--primary">{{ translate('search') }}</button>
                            </form>
                        </div>

                        <div class="table-responsive">
                            <table id="commission-table" class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>{{ translate('SL') }}</th>
                                        <th>{{ translate('service') }}</th>
                                        <th>{{ translate('commission') }}</th>
                                        <th>{{ translate('type') }}</th>
                                        @canany(['service_update', 'service_delete'])
                                        <th>{{ translate('action') }}</th>
                                        @endcan
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($commissions as $key => $commission)
                                    <tr>
                                        <td>{{ $commissions->firstItem() + $key }}</td>
                                        <td>{{ optional($commission->service)->name ?? translate('service_not_found') }}</td>
                                        <td>
                                            @if($commission->commission_type == 'percentage')
                                            {{ $commission->commission }}%
                                            @else
                                            {{ with_currency_symbol($commission->commission) }}
                                            @endif
                                        </td>
                                        <td>{{ ucfirst($commission->commission_type) }}</td>
                                        @canany(['service_update', 'service_delete'])
                                        <td>
                                            <div class="d-flex gap-2">
                                                @can('service_update')
                                                <a href="{{ route('admin.service.commission.edit', [$commission->id]) }}" class="action-btn btn--light-primary demo_check" style="--size: 30px">
                                                    <span class="material-icons">edit</span>
                                                </a>
                                                @endcan
                                                @can('service_delete')
                                                <button type="button" data-id="delete-{{ $commission->id }}" data-message="{{ translate('want_to_delete_this_commission') }}?" class="action-btn btn--danger {{ env('APP_ENV') != 'demo' ? 'form-alert' : 'demo_check' }}" style="--size: 30px">
                                                    <span class="material-symbols-outlined">delete</span>
                                                </button>
                                                <form action="{{ route('admin.service.commission.delete', [$commission->id]) }}" method="post" id="delete-{{ $commission->id }}" class="hidden">
                                                    @csrf
                                                    @method('DELETE')
                                                </form>
                                                @endcan
                                            </div>
                                        </td>
                                        @endcan
                                    </tr>
                                    @empty
                                    <tr>
                                        <td colspan="5" class="text-center">{{ translate('no_data_found') }}</td>
                                    </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-end">
                            {!! $commissions->links() !!}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('script')
<script src="{{ asset('assets/admin-module') }}/plugins/dataTables/jquery.dataTables.min.js"></script>
<script src="{{ asset('assets/admin-module') }}/plugins/dataTables/dataTables.select.min.js"></script>
@endpush
