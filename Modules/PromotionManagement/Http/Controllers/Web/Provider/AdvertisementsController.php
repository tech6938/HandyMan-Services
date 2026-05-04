<?php

namespace Modules\PromotionManagement\Http\Controllers\Web\Provider;

use Brian2694\Toastr\Facades\Toastr;
use Carbon\Carbon;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\BusinessSettingsModule\Entities\Translation;
use Modules\PromotionManagement\Entities\Advertisement;
use Modules\PromotionManagement\Entities\AdvertisementAttachment;
use Modules\PromotionManagement\Entities\AdvertisementNote;
use Modules\PromotionManagement\Entities\AdvertisementSettings;
use Rap2hpoutre\FastExcel\FastExcel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdvertisementsController extends Controller
{
    public function __construct(
        private Advertisement           $advertisement,
        private AdvertisementAttachment $advertisementAttachment,
        private AdvertisementNote       $advertisementNote,
        private AdvertisementSettings   $advertisementSettings
    ) {
    }

    public function AdsCreate(): Factory|\Illuminate\Foundation\Application|View|Application
    {
        return view('promotionmanagement::provider.advertisements.ads-create');
    }

    public function AdsList(Request $request): Factory|View|Application
    {
        $search = $request->has('search') ? $request['search'] : '';
        $status = $request->has('status') ? $request['status'] : 'all';
        $queryParam = ['search' => $search, 'status' => $status];

        $advertisements = $this->advertisement->with(['attachments'])
            ->when($request->has('search'), function ($query) use ($request) {
                $keys = explode(' ', $request['search']);
                return $query->where(function ($query) use ($keys) {
                    foreach ($keys as $key) {
                        $query->orWhere('title', 'LIKE', '%' . $key . '%');
                        $query->orWhere('readable_id', 'LIKE', '%' . $key . '%');
                    }
                });
            })
            ->where('provider_id', Auth::user()->provider->id)
            ->when($request->has('status') && $request['status'] !== 'all', function ($query) use ($request) {
                return $query->when($request['status'] === 'running', function ($query) {
                    return $query->ofRunning();
                }, function ($query) use ($request) {
                    if ($request['status'] === 'expired') {
                        return $query->ofExpired();
                    } elseif ($request['status'] === 'denied') {
                        return $query->whereIn('status', ['denied', 'canceled']);
                    } elseif ($request['status'] === 'approved') {
                        return $query->where('status', 'approved')
                            ->where('end_date', '>', Carbon::now())
                            ->where('start_date', '>', Carbon::today());
                    } else {
                        return $query->where('status', $request['status']);
                    }
                });
            })
            ->latest()
            ->paginate(pagination_limit())
            ->appends($queryParam);

        return view('promotionmanagement::provider.advertisements.ads-list', compact('advertisements', 'queryParam'));
    }

    public function AdsStore(Request $request): RedirectResponse
    {
        $request->validate([
            'title.0' => 'required|string|max:255',
            'description.0' => 'required|string|max:100',
            'type' => 'required|in:video_promotion,profile_promotion',
            'video_attachment' => 'required_if:type,video_promotion|max:50000|mimetypes:video/mp4,video/mkv,video/webm',
            'dates' => 'required',
            'profile_image' => 'required_if:type,profile_promotion|image|max:10000|mimes:' . implode(',', array_column(IMAGEEXTENSION, 'key')),
            'cover_image' => 'required_if:type,profile_promotion|image|max:10000|mimes:' . implode(',', array_column(IMAGEEXTENSION, 'key')),
        ]);

        [$startDate, $endDate] = explode(' - ', $request->dates);

        $startDate = Carbon::createFromFormat('m/d/Y', trim($startDate))->startOfDay();
        $endDate = Carbon::createFromFormat('m/d/Y', trim($endDate))->endOfDay();

        if ($startDate < Carbon::today()) {
            return redirect()->back()->withErrors(['Start date must be greater than or equal to today']);
        }

        if ($endDate < $startDate) {
            return redirect()->back()->withErrors(['End date must be greater than start date']);
        }

        DB::transaction(function () use ($request, $startDate, $endDate) {

            $advertisement = $this->advertisement;
            $advertisement->readable_id = $this->generateReadableId();
            $advertisement->title = $request->title[array_search('default', $request->lang)];
            $advertisement->description = $request->description[array_search('default', $request->lang)];
            $advertisement->provider_id = Auth::user()->provider->id;
            $advertisement->priority = null;
            $advertisement->type = $request->type;
            $advertisement->is_paid = 0;
            $advertisement->start_date = $startDate;
            $advertisement->end_date = $endDate;
            $advertisement->status = 'pending';
            $advertisement->save();

            // ✅ VIDEO upload to public/advertisement
            if ($request->type === 'video_promotion' && $request->hasFile('video_attachment')) {
                $file = $request->file('video_attachment');

                $this->advertisementAttachment->create([
                    'advertisement_id' => $advertisement->id,
                    'file_extension_type' => $file->getClientOriginalExtension(),
                    'file_name' => $this->uploadToPublicFolder('advertisement', $file),
                    'type' => 'promotional_video',
                ]);
            }

            // ✅ PROFILE upload to public/advertisement
            if ($request->type === 'profile_promotion') {

                if ($request->hasFile('profile_image')) {
                    $file = $request->file('profile_image');

                    $this->advertisementAttachment->create([
                        'advertisement_id' => $advertisement->id,
                        'file_extension_type' => $file->getClientOriginalExtension(),
                        'file_name' => $this->uploadToPublicFolder('advertisement', $file),
                        'type' => 'provider_profile_image',
                    ]);
                }

                if ($request->hasFile('cover_image')) {
                    $file = $request->file('cover_image');

                    $this->advertisementAttachment->create([
                        'advertisement_id' => $advertisement->id,
                        'file_extension_type' => $file->getClientOriginalExtension(),
                        'file_name' => $this->uploadToPublicFolder('advertisement', $file),
                        'type' => 'provider_cover_image',
                    ]);
                }

                $this->advertisementSettings->create([
                    'advertisement_id' => $advertisement->id,
                    'key' => 'review',
                    'value' => $request->has('review') ? 1 : 0,
                ]);

                $this->advertisementSettings->create([
                    'advertisement_id' => $advertisement->id,
                    'key' => 'rating',
                    'value' => $request->has('rating') ? 1 : 0,
                ]);
            }

            // translations
            $defaultLang = str_replace('_', '-', app()->getLocale());

            foreach ($request->lang as $index => $key) {
                // title
                if ($defaultLang == $key && !($request->title[$index])) {
                    if ($key != 'default') {
                        Translation::updateOrInsert(
                            [
                                'translationable_type' => Advertisement::class,
                                'translationable_id' => $advertisement->id,
                                'locale' => $key,
                                'key' => 'title',
                            ],
                            ['value' => $advertisement->title]
                        );
                    }
                } else {
                    if ($request->title[$index] && $key != 'default') {
                        Translation::updateOrInsert(
                            [
                                'translationable_type' => Advertisement::class,
                                'translationable_id' => $advertisement->id,
                                'locale' => $key,
                                'key' => 'title',
                            ],
                            ['value' => $request->title[$index]]
                        );
                    }
                }

                // description
                if ($defaultLang == $key && !($request->description[$index])) {
                    if ($key != 'default') {
                        Translation::updateOrInsert(
                            [
                                'translationable_type' => Advertisement::class,
                                'translationable_id' => $advertisement->id,
                                'locale' => $key,
                                'key' => 'description',
                            ],
                            ['value' => $advertisement->description]
                        );
                    }
                } else {
                    if ($request->description[$index] && $key != 'default') {
                        Translation::updateOrInsert(
                            [
                                'translationable_type' => Advertisement::class,
                                'translationable_id' => $advertisement->id,
                                'locale' => $key,
                                'key' => 'description',
                            ],
                            ['value' => $request->description[$index]]
                        );
                    }
                }
            }
        });

        Toastr::success(translate(DEFAULT_STORE_200['message']));
        return redirect()->route('provider.advertisements.ads-list', ['status' => 'all'])->with('newItemAdded', true);
    }

    public function details($id, Request $request): Factory|\Illuminate\Foundation\Application|View|Application
    {
        $advertisement = $this->advertisement->with(['provider', 'attachments', 'attachment', 'note', 'translations'])->find($id);

        foreach ($advertisement->attachments as $attachment) {
            if ($attachment->type == 'provider_cover_image') {
                $advertisement->provider_cover_image_full_path = $attachment->provider_cover_image_full_path;
            }
            if ($attachment->type == 'provider_profile_image') {
                $advertisement->provider_profile_image_full_path = $attachment->provider_profile_image_full_path;
            }
        }

        // promotional video (from relation OR from attachments fallback)
        $advertisement->promotional_video_full_path = $advertisement?->attachment?->promotional_video_full_path
            ?? optional($advertisement->attachments->where('type', 'promotional_video')->first())->promotional_video_full_path;

        unset($advertisement->attachments, $advertisement->attachment);

        return view('promotionmanagement::provider.advertisements.details', compact('advertisement'));
    }

    private function generateReadableId(): int
    {
        do {
            $readableId = rand(1000000000, 9999999999);
            $exists = $this->advertisement->where('readable_id', $readableId)->exists();
        } while ($exists);

        return $readableId;
    }

    public function edit($id, Request $request): Factory|\Illuminate\Foundation\Application|View|Application
    {
        $advertisement = $this->advertisement->with(['provider', 'attachments', 'attachment'])->withoutGlobalScope('translate')->find($id);

        foreach ($advertisement->attachments as $attachment) {
            if ($attachment->type == 'provider_cover_image') {
                $advertisement->provider_cover_image_full_path = $attachment->provider_cover_image_full_path;
            }
            if ($attachment->type == 'provider_profile_image') {
                $advertisement->provider_profile_image_full_path = $attachment->provider_profile_image_full_path;
            }
        }

        $advertisement->promotional_video_full_path = $advertisement?->attachment?->promotional_video_full_path
            ?? optional($advertisement->attachments->where('type', 'promotional_video')->first())->promotional_video_full_path;

        unset($advertisement->attachments, $advertisement->attachment);

        return view('promotionmanagement::provider.advertisements.edit', compact('advertisement'));
    }

    public function reSubmit($id, Request $request): Factory|View|\Illuminate\Foundation\Application|Application
    {
        $advertisement = $this->advertisement->with(['provider', 'attachments', 'attachment', 'review', 'rating'])->withoutGlobalScope('translate')->find($id);

        foreach ($advertisement->attachments as $attachment) {
            if ($attachment->type == 'provider_cover_image') {
                $advertisement->provider_cover_image_full_path = $attachment->provider_cover_image_full_path;
            }
            if ($attachment->type == 'provider_profile_image') {
                $advertisement->provider_profile_image_full_path = $attachment->provider_profile_image_full_path;
            }
        }

        $advertisement->promotional_video_full_path = $advertisement?->attachment?->promotional_video_full_path
            ?? optional($advertisement->attachments->where('type', 'promotional_video')->first())->promotional_video_full_path;

        unset($advertisement->attachments, $advertisement->attachment);

        return view('promotionmanagement::provider.advertisements.ads-re-submit', compact('advertisement'));
    }

    public function storeReSubmit(Request $request, $sourceId): RedirectResponse
    {
        $request->validate([
            'title.0' => 'required|string|max:255',
            'description.0' => 'required|string|max:100',
            'dates' => 'required',
            'type' => 'required|in:video_promotion,profile_promotion',
            'video_attachment' => 'max:50000|mimetypes:video/mp4,video/mkv,video/webm',
            'profile_image' => 'image|max:10000|mimes:' . implode(',', array_column(IMAGEEXTENSION, 'key')),
            'cover_image' => 'image|max:10000|mimes:' . implode(',', array_column(IMAGEEXTENSION, 'key')),
        ]);

        [$startDate, $endDate] = explode(' - ', $request->dates);

        $startDate = Carbon::createFromFormat('m/d/Y', trim($startDate))->startOfDay();
        $endDate = Carbon::createFromFormat('m/d/Y', trim($endDate))->endOfDay();

        if ($startDate < Carbon::today()) {
            return redirect()->back()->withErrors(['Start date must be greater than or equal to today']);
        }

        if ($endDate < $startDate) {
            return redirect()->back()->withErrors(['End date must be greater than start date']);
        }

        DB::transaction(function () use ($request, $startDate, $endDate, $sourceId) {

            $advertisement = $this->advertisement;
            $advertisement->readable_id = $this->generateReadableId();
            $advertisement->title = $request->title[array_search('default', $request->lang)];
            $advertisement->description = $request->description[array_search('default', $request->lang)];
            $advertisement->provider_id = Auth::user()->provider->id;
            $advertisement->priority = null;
            $advertisement->type = $request->type;
            $advertisement->is_paid = 0;
            $advertisement->start_date = $startDate;
            $advertisement->end_date = $endDate;
            $advertisement->status = 'pending';
            $advertisement->save();

            $sourceAdvertisement = Advertisement::with(['attachments', 'attachment'])->find($sourceId);

            if ($request->type === 'video_promotion') {

                if ($request->hasFile('video_attachment')) {
                    $file = $request->file('video_attachment');

                    $this->advertisementAttachment->create([
                        'advertisement_id' => $advertisement->id,
                        'file_extension_type' => $file->getClientOriginalExtension(),
                        'file_name' => $this->uploadToPublicFolder('advertisement', $file),
                        'type' => 'promotional_video',
                    ]);
                } else {
                    $sourceVideo = $sourceAdvertisement?->attachment;
                    if ($sourceVideo) {
                        $newName = $this->copyPublicFile('advertisement', $sourceVideo->file_name, $sourceVideo->file_extension_type);

                        $this->advertisementAttachment->create([
                            'advertisement_id' => $advertisement->id,
                            'file_extension_type' => $sourceVideo->file_extension_type,
                            'file_name' => $newName,
                            'type' => 'promotional_video',
                        ]);
                    }
                }
            }

            if ($request->type === 'profile_promotion') {

                // profile
                if ($request->hasFile('profile_image')) {
                    $file = $request->file('profile_image');

                    $this->advertisementAttachment->create([
                        'advertisement_id' => $advertisement->id,
                        'file_extension_type' => $file->getClientOriginalExtension(),
                        'file_name' => $this->uploadToPublicFolder('advertisement', $file),
                        'type' => 'provider_profile_image',
                    ]);
                } else {
                    $sourceProfile = $sourceAdvertisement?->attachments->where('type', 'provider_profile_image')->first();
                    if ($sourceProfile) {
                        $newName = $this->copyPublicFile('advertisement', $sourceProfile->file_name, $sourceProfile->file_extension_type);

                        $this->advertisementAttachment->create([
                            'advertisement_id' => $advertisement->id,
                            'file_extension_type' => $sourceProfile->file_extension_type,
                            'file_name' => $newName,
                            'type' => 'provider_profile_image',
                        ]);
                    }
                }

                // cover
                if ($request->hasFile('cover_image')) {
                    $file = $request->file('cover_image');

                    $this->advertisementAttachment->create([
                        'advertisement_id' => $advertisement->id,
                        'file_extension_type' => $file->getClientOriginalExtension(),
                        'file_name' => $this->uploadToPublicFolder('advertisement', $file),
                        'type' => 'provider_cover_image',
                    ]);
                } else {
                    $sourceCover = $sourceAdvertisement?->attachments->where('type', 'provider_cover_image')->first();
                    if ($sourceCover) {
                        $newName = $this->copyPublicFile('advertisement', $sourceCover->file_name, $sourceCover->file_extension_type);

                        $this->advertisementAttachment->create([
                            'advertisement_id' => $advertisement->id,
                            'file_extension_type' => $sourceCover->file_extension_type,
                            'file_name' => $newName,
                            'type' => 'provider_cover_image',
                        ]);
                    }
                }

                $this->advertisementSettings->create([
                    'advertisement_id' => $advertisement->id,
                    'key' => 'review',
                    'value' => $request->has('review') ? 1 : 0,
                ]);

                $this->advertisementSettings->create([
                    'advertisement_id' => $advertisement->id,
                    'key' => 'rating',
                    'value' => $request->has('rating') ? 1 : 0,
                ]);
            }

            // translations
            $defaultLang = str_replace('_', '-', app()->getLocale());

            foreach ($request->lang as $index => $key) {
                if ($defaultLang == $key && !($request->title[$index])) {
                    if ($key != 'default') {
                        Translation::updateOrInsert(
                            [
                                'translationable_type' => Advertisement::class,
                                'translationable_id' => $advertisement->id,
                                'locale' => $key,
                                'key' => 'title',
                            ],
                            ['value' => $advertisement->title]
                        );
                    }
                } else {
                    if ($request->title[$index] && $key != 'default') {
                        Translation::updateOrInsert(
                            [
                                'translationable_type' => Advertisement::class,
                                'translationable_id' => $advertisement->id,
                                'locale' => $key,
                                'key' => 'title',
                            ],
                            ['value' => $request->title[$index]]
                        );
                    }
                }

                if ($defaultLang == $key && !($request->description[$index])) {
                    if ($key != 'default') {
                        Translation::updateOrInsert(
                            [
                                'translationable_type' => Advertisement::class,
                                'translationable_id' => $advertisement->id,
                                'locale' => $key,
                                'key' => 'description',
                            ],
                            ['value' => $advertisement->description]
                        );
                    }
                } else {
                    if ($request->description[$index] && $key != 'default') {
                        Translation::updateOrInsert(
                            [
                                'translationable_type' => Advertisement::class,
                                'translationable_id' => $advertisement->id,
                                'locale' => $key,
                                'key' => 'description',
                            ],
                            ['value' => $request->description[$index]]
                        );
                    }
                }
            }
        });

        Toastr::success(translate(DEFAULT_STORE_200['message']));
        return redirect()->route('provider.advertisements.ads-list', ['status' => 'all']);
    }

    public function update(Request $request, $id): RedirectResponse
    {
        $request->validate([
            'title.0' => 'required|string|max:255',
            'description.0' => 'required|string|max:100',
            'type' => 'required|in:video_promotion,profile_promotion',
            'video_attachment' => 'max:50000|mimetypes:video/mp4,video/mkv,video/webm',
            'dates' => 'required',
            'profile_image' => 'image|max:10000|mimes:' . implode(',', array_column(IMAGEEXTENSION, 'key')),
            'cover_image' => 'image|max:10000|mimes:' . implode(',', array_column(IMAGEEXTENSION, 'key')),
        ]);

        $advertisement = $this->advertisement->with(['attachments', 'attachment', 'review', 'rating'])->find($id);

        if ($advertisement->type != $request->type) {
            $errorText = [];

            if ($request->type != 'video_promotion') {
                if (!$request->hasFile('cover_image')) $errorText[] = translate('The cover image is required');
                if (!$request->hasFile('profile_image')) $errorText[] = translate('The profile image is required');
            } else {
                if (!$request->hasFile('video_attachment')) $errorText[] = translate('The video attachment is required');
            }

            if (!empty($errorText)) {
                return redirect()->back()->withErrors($errorText);
            }
        }

        [$startDate, $endDate] = explode(' - ', $request->dates);

        $startDate = Carbon::createFromFormat('m/d/Y', trim($startDate))->startOfDay();
        $endDate = Carbon::createFromFormat('m/d/Y', trim($endDate))->endOfDay();

        if ($startDate < Carbon::today()) {
            return redirect()->back()->withErrors(['Start date must be greater than or equal to today']);
        }

        if ($endDate < $startDate) {
            return redirect()->back()->withErrors(['End date must be greater than start date']);
        }

        DB::transaction(function () use ($advertisement, $request, $startDate, $endDate) {

            $advertisement->title = $request->title[array_search('default', $request->lang)];
            $advertisement->description = $request->description[array_search('default', $request->lang)];
            $advertisement->type = $request->type;
            $advertisement->start_date = $startDate;
            $advertisement->end_date = $endDate;
            $advertisement->is_updated = 1;
            $advertisement->status = 'pending';
            $advertisement->save();

            $hasAnyFile = $request->hasFile('video_attachment') || $request->hasFile('profile_image') || $request->hasFile('cover_image');

            if ($hasAnyFile) {

                // delete only what user replaced
                if ($request->hasFile('cover_image')) {
                    $oldCover = $advertisement->attachments->where('type', 'provider_cover_image')->first();
                    if ($oldCover) {
                        $this->deleteFromPublicFolder('advertisement', $oldCover->file_name);
                        $oldCover->delete();
                    }
                }

                if ($request->hasFile('profile_image')) {
                    $oldProfile = $advertisement->attachments->where('type', 'provider_profile_image')->first();
                    if ($oldProfile) {
                        $this->deleteFromPublicFolder('advertisement', $oldProfile->file_name);
                        $oldProfile->delete();
                    }
                }

                if ($request->hasFile('video_attachment')) {
                    $oldVideo = $advertisement->attachment; // promotional_video relation
                    if ($oldVideo) {
                        $this->deleteFromPublicFolder('advertisement', $oldVideo->file_name);
                        $oldVideo->delete();
                    }
                }
            }

            // Upload new files to public/advertisement
            if ($request->type === 'video_promotion' && $request->hasFile('video_attachment')) {
                $file = $request->file('video_attachment');

                $this->advertisementAttachment->create([
                    'advertisement_id' => $advertisement->id,
                    'file_extension_type' => $file->getClientOriginalExtension(),
                    'file_name' => $this->uploadToPublicFolder('advertisement', $file),
                    'type' => 'promotional_video',
                ]);
            }

            if ($request->type === 'profile_promotion') {

                if ($request->hasFile('profile_image')) {
                    $file = $request->file('profile_image');

                    $this->advertisementAttachment->create([
                        'advertisement_id' => $advertisement->id,
                        'file_extension_type' => $file->getClientOriginalExtension(),
                        'file_name' => $this->uploadToPublicFolder('advertisement', $file),
                        'type' => 'provider_profile_image',
                    ]);
                }

                if ($request->hasFile('cover_image')) {
                    $file = $request->file('cover_image');

                    $this->advertisementAttachment->create([
                        'advertisement_id' => $advertisement->id,
                        'file_extension_type' => $file->getClientOriginalExtension(),
                        'file_name' => $this->uploadToPublicFolder('advertisement', $file),
                        'type' => 'provider_cover_image',
                    ]);
                }

                $advertisement->rating?->delete();
                $advertisement->review?->delete();

                $this->advertisementSettings->create([
                    'advertisement_id' => $advertisement->id,
                    'key' => 'review',
                    'value' => $request->review == 'on' ? 1 : 0,
                ]);

                $this->advertisementSettings->create([
                    'advertisement_id' => $advertisement->id,
                    'key' => 'rating',
                    'value' => $request->rating == 'on' ? 1 : 0,
                ]);
            }

            // translations
            $defaultLang = str_replace('_', '-', app()->getLocale());

            foreach ($request->lang as $index => $key) {
                if ($defaultLang == $key && !($request->title[$index])) {
                    if ($key != 'default') {
                        Translation::updateOrInsert(
                            [
                                'translationable_type' => Advertisement::class,
                                'translationable_id' => $advertisement->id,
                                'locale' => $key,
                                'key' => 'title',
                            ],
                            ['value' => $advertisement->title]
                        );
                    }
                } else {
                    if ($request->title[$index] && $key != 'default') {
                        Translation::updateOrInsert(
                            [
                                'translationable_type' => Advertisement::class,
                                'translationable_id' => $advertisement->id,
                                'locale' => $key,
                                'key' => 'title',
                            ],
                            ['value' => $request->title[$index]]
                        );
                    }
                }

                if ($defaultLang == $key && !($request->description[$index])) {
                    if ($key != 'default') {
                        Translation::updateOrInsert(
                            [
                                'translationable_type' => Advertisement::class,
                                'translationable_id' => $advertisement->id,
                                'locale' => $key,
                                'key' => 'description',
                            ],
                            ['value' => $advertisement->description]
                        );
                    }
                } else {
                    if ($request->description[$index] && $key != 'default') {
                        Translation::updateOrInsert(
                            [
                                'translationable_type' => Advertisement::class,
                                'translationable_id' => $advertisement->id,
                                'locale' => $key,
                                'key' => 'description',
                            ],
                            ['value' => $request->description[$index]]
                        );
                    }
                }
            }
        });

        Toastr::success(translate(DEFAULT_UPDATE_200['message']));
        return redirect()->route('provider.advertisements.ads-list', ['status' => 'all']);
    }

    public function statusUpdate(Request $request, $id, $status): RedirectResponse
    {
        $advertisement = $this->advertisement->find($id);

        if ($advertisement) {
            $advertisement->status = $status;
            $advertisement->save();
        }

        if ($request->has('note')) {
            $advertisementNote = $this->advertisementNote;
            $advertisementNote->advertisement_id = $advertisement->id;
            $advertisementNote->type = $status;
            $advertisementNote->note = $request->note;
            $advertisementNote->save();
        }

        Toastr::success(translate(DEFAULT_STATUS_UPDATE_200['message']));
        return back();
    }

    public function destroy(Request $request, $id): RedirectResponse
    {
        $advertisement = $this->advertisement->with(['attachments', 'attachment'])->where('id', $id)->first();

        if ($advertisement) {
            // delete attachments from public/advertisement
            foreach ($advertisement->attachments as $attachment) {
                $this->deleteFromPublicFolder('advertisement', $attachment->file_name);
            }
            if ($advertisement->attachment) {
                $this->deleteFromPublicFolder('advertisement', $advertisement->attachment->file_name);
            }

            $this->advertisement->where('id', $id)->delete();
        }

        Toastr::success(translate(DEFAULT_DELETE_200['message']));
        return back();
    }

    public function download(Request $request): string|StreamedResponse
    {
        $items = $this->advertisement->with(['attachments'])
            ->when($request->has('search'), function ($query) use ($request) {
                $keys = explode(' ', $request['search']);
                return $query->where(function ($query) use ($keys) {
                    foreach ($keys as $key) {
                        $query->orWhere('title', 'LIKE', '%' . $key . '%');
                    }
                });
            })
            ->where('provider_id', Auth::user()->provider->id)
            ->when($request->has('status') && $request['status'] !== 'all', function ($query) use ($request) {
                return $query->when($request['status'] === 'running', function ($query) {
                    return $query->ofRunning();
                }, function ($query) use ($request) {
                    if ($request['status'] === 'expired') {
                        return $query->ofExpired();
                    } elseif ($request['status'] === 'denied') {
                        return $query->whereIn('status', ['denied', 'canceled']);
                    } elseif ($request['status'] === 'approved') {
                        return $query->where('status', 'approved')
                            ->where('end_date', '>', Carbon::now())
                            ->where('start_date', '>', Carbon::today());
                    } else {
                        return $query->where('status', $request['status']);
                    }
                });
            })
            ->latest()
            ->get();

        return (new FastExcel($items))->download(time() . '-file.xlsx');
    }

    /**
     * ✅ Upload any file into PUBLIC folder: public/{folder}/
     * Returns file_name (only filename saved in DB)
     */
    private function uploadToPublicFolder(string $folder, $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $fileName = Carbon::now()->format('YmdHis') . '_' . uniqid() . '.' . $extension;

        $destinationPath = public_path(trim($folder, '/'));

        if (!is_dir($destinationPath)) {
            mkdir($destinationPath, 0777, true);
        }

        $file->move($destinationPath, $fileName);

        return $fileName;
    }

    /**
     * ✅ Delete from public/{folder}/
     */
    private function deleteFromPublicFolder(string $folder, ?string $fileName): void
    {
        if (!$fileName) return;

        $path = public_path(trim($folder, '/') . '/' . $fileName);

        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * ✅ Copy file inside public/{folder}/ (used for resubmit)
     */
    private function copyPublicFile(string $folder, string $oldFileName, string $extension): string
    {
        $source = public_path(trim($folder, '/') . '/' . $oldFileName);

        $newFileName = Carbon::now()->format('YmdHis') . '_' . uniqid() . '.' . $extension;
        $dest = public_path(trim($folder, '/') . '/' . $newFileName);

        if (file_exists($source)) {
            @copy($source, $dest);
        }

        return $newFileName;
    }
}
