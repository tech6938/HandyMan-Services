@extends('adminmodule::layouts.master')

@section('title', translate('create_service_commission'))

@section('content')
<div class="main-content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-wrap d-flex justify-content-between flex-wrap align-items-center gap-3 mb-3">
                    <h2 class="page-title">{{ translate('create_service_commission') }}</h2>
                    <div>
                        <a href="{{ route('admin.service.commission.index') }}" class="btn btn--primary">
                            <span class="material-icons">arrow_back</span>
                            {{ translate('back_to_commission_list') }}
                        </a>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <form action="{{ route('admin.service.commission.store') }}" method="POST">
                            @csrf
                            <div class="row g-4">
                                <div class="col-lg-6">
                                    <label class="form-label" for="service_id">{{ translate('service') }}</label>
                                    <select name="service_id" id="service_id" class="js-select theme-input-style w-100">
                                        <option value="">{{ translate('select_service') }}</option>
                                        @foreach($services as $service)
                                        <option value="{{ $service->id }}" {{ old('service_id') == $service->id ? 'selected' : '' }}>{{ $service->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('service_id')
                                    <span class="text-danger">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="col-lg-6">
                                    <label class="form-label" for="commission">{{ translate('commission') }}</label>
                                    <input type="number" step="0.01" name="commission" id="commission" class="theme-input-style w-100" value="{{ old('commission') }}" placeholder="{{ translate('enter_commission_amount') }}">
                                    @error('commission')
                                    <span class="text-danger">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="col-lg-6">
                                    <label class="form-label" for="commission_type">{{ translate('commission_type') }}</label>
                                    <select name="commission_type" id="commission_type" class="js-select theme-input-style w-100">
                                        <option value="fixed" {{ old('commission_type') == 'fixed' ? 'selected' : '' }}>{{ translate('fixed') }}</option>
                                        <option value="percentage" {{ old('commission_type') == 'percentage' ? 'selected' : '' }}>{{ translate('percentage') }}</option>
                                    </select>
                                    @error('commission_type')
                                    <span class="text-danger">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="col-12">
                                    <button type="submit" class="btn btn--primary">{{ translate('save') }}</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('script')
<script>
    "use strict"
    $(document).ready(function () {
        $('.js-select').select2();
    });
</script>
@endpush
