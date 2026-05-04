@extends('adminmodule::layouts.master')

@section('title', translate('Government Category Text'))

@section('content')
    <div class="main-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-wrap mb-3">
                        <h2 class="page-title">{{ translate('Government Category Text') }}</h2>
                    </div>

                    <div class="card">
                        <div class="card-body p-30">
                            @if (!$governmentCategory)
                                <div class="alert alert--warning mb-0">
                                    {{ translate('Government category not found') }}
                                </div>
                            @else
                                <form action="{{ route('admin.business-settings.government-category-text.store') }}"
                                    method="POST">
                                    @csrf

                                    <div class="mb-30">
                                        <label class="form-label">{{ translate('Category') }}</label>
                                        <input type="text" class="form-control"
                                            value="{{ $governmentCategory->name }}" readonly>
                                    </div>

                                    <div class="mb-30">
                                        <label class="form-label">{{ translate('Content') }}</label>
                                        <textarea class="ckeditor" name="content" rows="12" required>{!! old('content', $governmentText?->content) !!}</textarea>
                                    </div>

                                    <div class="d-flex justify-content-end gap-20">
                                        <button class="btn btn--secondary" type="reset">{{ translate('reset') }}</button>
                                        <button class="btn btn--primary" type="submit">{{ translate('save') }}</button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script')
    <script src="{{ asset('assets/admin-module/plugins/tinymce/tinymce.min.js') }}"></script>
    <script>
        "use strict";

        $(document).ready(function() {
            tinymce.init({
                selector: 'textarea.ckeditor'
            });
        });
    </script>
@endpush
