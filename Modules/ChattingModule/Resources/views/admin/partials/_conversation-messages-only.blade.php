@php
    $format = ['jpg','png','jpeg','JPG','PNG','JPEG','gif','webp','GIF','WEBP'];
@endphp

@foreach ($conversation as $chat)
    @php
        $isMine = ($chat->user_id == auth()->id());
    @endphp

    @if ($isMine)
        <div class="outgoing_msg">
            @if (!empty($chat->message))
                <p class="message_text">{{ $chat->message }}</p>
            @endif

            @if (isset($chat->conversationFiles) && $chat->conversationFiles->count() > 0)
                <div class="inbox-img-grid">
                    @foreach ($chat->conversationFiles as $file)
                        @php
                            $ext = $file->file_type ?? pathinfo($file->stored_file_name ?? '', PATHINFO_EXTENSION);
                            $url = asset($file->stored_file_name); // ✅ public/conversation/...
                        @endphp

                        @if (in_array($ext, $format))
                            <div class="conv-img-wrap">
                                <a data-lightbox="mygallery" href="{{ $url }}">
                                    <img width="150" src="{{ $url }}" alt="{{ translate('image') }}">
                                </a>
                            </div>
                        @else
                            <div class="d-flex align-items-center flex-column gap-1">
                                <img width="50" src="{{ asset('assets/admin-module/img/icons/folder.png') }}" alt="">
                                <a class="fs-12" href="{{ $url }}" download>
                                    {{ $file->original_file_name ?? 'Download file' }}
                                </a>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif

            <span class="time_date d-flex justify-content-end">
                {{ date('H:i a | M d', strtotime($chat->created_at)) }}
            </span>
        </div>
    @else
        <div class="received_msg">
            @if (!empty($chat->message))
                <p class="message_text">{{ $chat->message }}</p>
            @endif

            @if (isset($chat->conversationFiles) && $chat->conversationFiles->count() > 0)
                <div class="inbox-img-grid">
                    @foreach ($chat->conversationFiles as $file)
                        @php
                            $ext = $file->file_type ?? pathinfo($file->stored_file_name ?? '', PATHINFO_EXTENSION);
                            $url = asset($file->stored_file_name);
                        @endphp

                        @if (in_array($ext, $format))
                            <div class="conv-img-wrap">
                                <a data-lightbox="mygallery" href="{{ $url }}">
                                    <img width="150" src="{{ $url }}" alt="{{ translate('image') }}">
                                </a>
                            </div>
                        @else
                            <a href="{{ $url }}" download>
                                {{ $file->original_file_name ?? 'Download file' }}
                            </a>
                        @endif
                    @endforeach
                </div>
            @endif

            <span class="time_date">
                {{ date('H:i a | M d', strtotime($chat->created_at)) }}
            </span>
        </div>
    @endif
@endforeach
