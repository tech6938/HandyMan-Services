@if(session()->has('variations'))
    @foreach(session('variations') as $key => $item)
        <tr>
            <th scope="row">
                {{ $item['variant'] }}
                <input name="variants[]" value="{{ str_replace(' ', '-', $item['variant']) }}" class="hide-div">
            </th>

            {{-- NEW service_img COLUMN --}}
            <td>
                <div class="d-flex flex-column align-items-center gap-2">
                    <div class="upload-file upload-file--sm">
                        <input type="file"
                               class="upload-file__input variant-img-input"
                               name="{{ $item['variant_key'] }}_service_img"
                               accept=".jpg,.jpeg,.png,.gif,|image/*"
                               data-preview="variant-img-preview-{{ $key }}">
                        <div class="upload-file__img upload-file__img--sm" id="variant-img-preview-{{ $key }}">
                            <img src="{{ asset('assets/admin-module/img/media/upload-file.png') }}"
                                 alt="{{ translate('image') }}"
                                 style="width: 60px; height: 60px; object-fit: cover; border-radius: 6px;">
                        </div>
                        <span class="upload-file__edit" style="width:22px;height:22px;">
                            <span class="material-icons" style="font-size:14px;">edit</span>
                        </span>
                    </div>
                </div>
            </td>

            <td>
                <input type="number"
                       value="{{ $item['price'] }}"
                       class="theme-input-style"
                       id="default-set-{{ $key }}"
                       onkeyup="set_values('{{ $key }}')"
                       step="any">
            </td>

            @foreach($zones as $zone)
                <td>
                    <input type="number"
                           name="{{ $item['variant_key'] }}_{{ $zone->id }}_price"
                           value="{{ $item['price'] }}"
                           class="theme-input-style default-get-{{ $key }}"
                           step="any">
                </td>
            @endforeach

            <td>
                <a class="btn btn--danger service-ajax-remove-variant"
                   data-id="variation-table"
                   data-route="{{ route('admin.service.ajax-remove-variant', [$item['variant_key']]) }}">
                    <span class="material-icons m-0">delete</span>
                </a>
            </td>
        </tr>
    @endforeach
@endif

<script>
    "use strict";

    // Image preview on file select
    document.querySelectorAll('.variant-img-input').forEach(function (input) {
        input.addEventListener('change', function () {
            const previewId = this.getAttribute('data-preview');
            const preview = document.getElementById(previewId);
            if (this.files && this.files[0] && preview) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    preview.querySelector('img').src = e.target.result;
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    });

    document.querySelectorAll('.service-ajax-remove-variant').forEach(function (element) {
        element.addEventListener('click', function () {
            var route = this.getAttribute('data-route');
            var id = this.getAttribute('data-id');
            ajax_remove_variant(route, id);
        });
    });

    function set_values(key) {
        document.querySelectorAll('.default-get-' + key).forEach(function (element) {
            element.value = document.getElementById('default-set-' + key).value;
        });
    }
</script>
