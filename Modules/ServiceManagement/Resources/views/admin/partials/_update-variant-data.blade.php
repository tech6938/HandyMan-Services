@if(isset($variants) && count($variants) > 0)
    @php
        $groupedVariants = $variants->groupBy('variant_key');
    @endphp

    @foreach($groupedVariants as $variant_key => $variantZones)
        @php $firstVariant = $variantZones->first(); @endphp
        <tr>
            <th scope="row">
                {{ $firstVariant->variant }}
                <input name="variants[]" value="{{ $variant_key }}" class="hide-div">
            </th>

            {{-- service_img column --}}
            <td>
                <div class="d-flex flex-column align-items-center gap-2">
                    <div class="upload-file upload-file--sm">
                        <input type="file"
                               class="upload-file__input variant-img-input"
                               name="{{ $variant_key }}_service_img"
                               accept=".jpg,.jpeg,.png,.gif,|image/*"
                               data-preview="update-variant-img-{{ $loop->index }}">
                        <div class="upload-file__img" id="update-variant-img-{{ $loop->index }}">
                            @if($firstVariant->service_img)
                                <img src="{{ asset('storage/service/' . $firstVariant->service_img) }}"
                                     alt="{{ translate('image') }}"
                                     style="width:60px;height:60px;object-fit:cover;border-radius:6px;">
                            @else
                                <img src="{{ asset('assets/admin-module/img/media/upload-file.png') }}"
                                     alt="{{ translate('image') }}"
                                     style="width:60px;height:60px;object-fit:cover;border-radius:6px;">
                            @endif
                        </div>
                        <span class="upload-file__edit" style="width:22px;height:22px;">
                            <span class="material-icons" style="font-size:14px;">edit</span>
                        </span>
                    </div>
                    {{-- Keep old image name if no new upload --}}
                    <input type="hidden"
                           name="{{ $variant_key }}_service_img_old"
                           value="{{ $firstVariant->service_img }}">
                </div>
            </td>

            {{-- Default price (first zone price as default) --}}
            <td>
                <input type="number"
                       value="{{ $firstVariant->price }}"
                       class="theme-input-style"
                       id="update-default-set-{{ $loop->index }}"
                       onkeyup="update_set_values('{{ $loop->index }}')"
                       step="any">
            </td>

            @foreach($zones as $zone)
                @php $zoneVariant = $variantZones->where('zone_id', $zone->id)->first(); @endphp
                <td>
                    <input type="number"
                           name="{{ $variant_key }}_{{ $zone->id }}_price"
                           value="{{ $zoneVariant->price ?? 0 }}"
                           class="theme-input-style update-default-get-{{ $loop->parent->index }}"
                           step="any">
                </td>
            @endforeach

            <td>
                <a class="btn btn--danger service-ajax-remove-db-variant"
                   data-id="variation-update-table"
                   data-route="{{ route('admin.service.ajax-delete-db-variant', [$variant_key, $firstVariant->service_id]) }}">
                    <span class="material-icons m-0">delete</span>
                </a>
            </td>
        </tr>
    @endforeach
@endif

<script>
    "use strict";

    // Image preview on file select for update table
    document.querySelectorAll('.variant-img-input').forEach(function(input) {
        input.addEventListener('change', function() {
            const previewId = this.getAttribute('data-preview');
            const preview = document.getElementById(previewId);
            if (this.files && this.files[0] && preview) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.querySelector('img').src = e.target.result;
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    });

    function update_set_values(key) {
        document.querySelectorAll('.update-default-get-' + key).forEach(function(element) {
            element.value = document.getElementById('update-default-set-' + key).value;
        });
    }
</script>
