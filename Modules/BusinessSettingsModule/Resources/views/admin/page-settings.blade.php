@extends('adminmodule::layouts.master')

@section('title', translate('page_setup'))

@push('css_or_js')
@endpush

@section('content')
<div class="main-content">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-wrap mb-3">
                    <h2 class="page-title">{{ translate('page_setup') }}</h2>
                </div>

                <div class="mb-3">
                    <ul class="nav nav--tabs nav--tabs__style2">
                        @foreach ($dataValues as $pageData)
                        <li class="nav-item">
                            <a href="{{ url()->current() }}?web_page={{ $pageData->key }}" class="nav-link {{ $webPage == $pageData->key ? 'active' : '' }}">
                                {{ translate($pageData->key) }}
                            </a>
                        </li>
                        @endforeach
                    </ul>
                </div>

                @foreach ($dataValues as $pageData)
                <div class="tab-content">
                    <div class="tab-pane fade {{ $webPage == $pageData->key ? 'active show' : '' }}">
                        <div class="card">
                            <form action="{{ route('admin.business-settings.set-pages-setup') }}" method="POST" enctype="multipart/form-data">
                                @csrf
                                <div class="card-header page-settings align-items-center flex-wrap">
                                    <div class="d-flex align-items-center gap-3">
                                        <h4 class="page-title">{{ translate($pageData->key) }}</h4>
                                        @if (!in_array($pageData->key, ['about_us', 'privacy_policy', 'terms_and_conditions']))
                                        <label class="switcher">
                                            <input class="switcher_input" type="checkbox" name="is_active" {{ $pageData->is_active ? 'checked' : '' }} value="1">
                                            <span class="switcher_control"></span>
                                        </label>
                                        @else
                                        <input name="is_active" value="1" class="hide-div">
                                        @endif
                                    </div>
                                    <?php
                                            $route = '#';
                                            if ($pageData->key == 'about_us') {
                                                $route = route('page.about-us');
                                            } elseif ($pageData->key == 'cancellation_policy') {
                                                $route = route('page.cancellation-policy');
                                            } elseif ($pageData->key == 'privacy_policy') {
                                                $route = route('page.privacy-policy');
                                            } elseif ($pageData->key == 'refund_policy') {
                                                $route = route('page.refund-policy');
                                            } elseif ($pageData->key == 'terms_and_conditions') {
                                                $route = route('page.terms-and-conditions');
                                            }
                                            ?>

                                    @if ($pageData->is_active)
                                    <a class="btn btn-outline--primary fs-14 text-capitalize gap-2 rounded-2" href="{{ $route }}" target="_blank">
                                        {{ translate('View URL') }}
                                        <span class="c1">
                                            <img class="svg" src="{{ asset('assets/admin-module/img/icons/arrow-right.svg') }}" alt="">
                                        </span>
                                    </a>
                                    @endif

                                </div>
                                @php
                                $imageKey = $pageData->key . '_image';
                                @endphp
                                <div class="card-body p-30">
                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="mb-30">
                                                <div class="d-flex flex-column align-items-center gap-3">
                                                    <p class="title-color mb-0"><span class="fw-bold text-uppercase">{{ translate('Header Image') }}</span>
                                                        ({{ translate('Resolution: 1280px X 186px') }})</p>
                                                    <div class="upload-file w-100 flex-grow-1 h-180px">
                                                        <input type="file" class="cover_attachment js-upload-input" data-target="main-image" accept=".{{ implode(',.', array_column(IMAGEEXTENSION, 'key')) }}, |image/*" name="cover_image">
                                                        <div class="upload-file__img m-auto max-w-100 h-180px text-center">
                                                            <img class="h-180px w-100" src="{{ $dataImages[$imageKey]->image_full_path ?? asset('assets/admin-module/img/page-default.png') }}" alt="">
                                                        </div>

                                                        <span class="edit-wrapper">
                                                            <span class="upload-file__edit top">
                                                                <span class="material-icons">edit</span>
                                                            </span>
                                                        </span>
                                                    </div>
                                                    <p class="opacity-75 text-center">
                                                        {{ translate('Supports: PNG, JPG, JPEG, WEBP, File Size: Maximum 10 MB') }}
                                                    </p>
                                                </div>
                                            </div>

                                            @php($language = Modules\BusinessSettingsModule\Entities\BusinessSettings::where('key_name', 'system_language')->first())
                                            @php($defaultLanguage = str_replace('_', '-', app()->getLocale()))
                                            @if ($language)
                                            <ul class="nav nav--tabs border-color-primary mb-4">
                                                <li class="nav-item">
                                                    <a class="nav-link lang_link active" href="#" id="default-link">{{ translate('default') }}</a>
                                                </li>
                                                @foreach ($language?->live_values as $lang)
                                                <li class="nav-item">
                                                    <a class="nav-link lang_link" href="#" id="{{ $lang['code'] }}-link">{{ get_language_name($lang['code']) }}</a>
                                                </li>
                                                @endforeach
                                            </ul>
                                            @endif
                                            @if ($language)
                                            <div class="mb-30 dark-support lang-form default-form">
                                                <input name="page_name" value="{{ $pageData->key }}" class="hide-div">
                                                <textarea class="ckeditor" required name="page_content[]">
                                                {!! $pageData->getOriginal('value') !!}
                                                </textarea>
                                            </div>
                                            <input type="hidden" name="lang[]" value="default">
                                            @foreach ($language?->live_values ?? [] as $lang)
                                            <?php
                                                            if (count($pageData['translations'])) {
                                                                $translate = [];
                                                                foreach ($pageData['translations'] as $t) {
                                                                    if ($t->locale == $lang['code'] && $t->key == $pageData->key) {
                                                                        $translate[$lang['code']][$pageData->key] = $t->value;
                                                                    }
                                                                }
                                                            }
                                                            ?>
                                            <div class="form-floating mb-30 d-none lang-form {{ $lang['code'] }}-form">
                                                <textarea class="ckeditor" name="page_content[]">
                                                                   {!! $translate[$lang['code']][$pageData->key] ?? '' !!}
                                                               </textarea>
                                            </div>
                                            <input type="hidden" name="lang[]" value="{{ $lang['code'] }}">
                                            @endforeach
                                            @else
                                            <div class="mb-30 dark-support lang-form default-form">
                                                <input name="page_name" value="{{ $pageData->key }}" class="hide-div">
                                                <textarea class="ckeditor" name="page_content[]">{!! $pageData?->getRawOriginal('live_values') !!}</textarea>
                                            </div>
                                            <input type="hidden" name="lang[]" value="default">
                                            @endif
                                        </div>
                                        @can('page_update')
                                        <div class="d-flex justify-content-end">
                                            <button class="btn btn--primary demo_check">
                                                {{ translate('update') }}
                                            </button>
                                        </div>
                                        @endcan
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                @endforeach
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

    $('.switcher_input').on('click', function() {
        $(this).submit()
    });

    $(".lang_link").on('click', function(e) {
        e.preventDefault();
        $(".lang_link").removeClass('active');
        $(".lang-form").addClass('d-none');
        $(this).addClass('active');

        let form_id = this.id;
        let lang = form_id.substring(0, form_id.length - 5);
        console.log(lang);
        $("." + lang + "-form").removeClass('d-none');
    });

</script>

<!-- Image Upload Handlr -->
<script>
    "use strict";

    $(document).ready(function() {
        $(".js-upload-input").on("change", function(event) {
            let file = event.target.files[0];
            const target = $(this).data('target');
            let blobURL = URL.createObjectURL(file);
            $(this).closest('.upload-file').find('.upload-file__img').html(
                '<img class="h-180px w-100" src="' + blobURL + '" alt="">');
        })
    });

</script>
@endpush
