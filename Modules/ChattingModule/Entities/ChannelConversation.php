<?php

namespace Modules\ChattingModule\Entities;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\ProviderManagement\Entities\Provider;
use Modules\UserManagement\Entities\User;

class ChannelConversation extends Model
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'channel_id',
        'message',
        'user_id',
    ];


    //relation
    public function conversationFiles(): HasMany
    {
        return $this->hasMany(ConversationFile::class, 'conversation_id', 'id');
    }
    public function conversationLastFiles(): HasMany
    {
        return $this->hasMany(ConversationFile::class, 'conversation_id', 'id');
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ChannelList::class);
    }

    public function channel_users(): HasMany
    {
        return $this->hasMany(ChannelUser::class, 'channel_id', 'channel_id');
    }

    public static function boot()
    {
        parent::boot();

        self::creating(function ($model) {
            // ... code here
        });

        self::created(function ($model) {
            $model->channel_users
                ->where('user_id', '!=', $model->user_id)
                ->pluck('user_id')
                ->each(function ($item) use ($model) {
                    $to_user = User::find($item);
                    $user = User::with(['provider', 'serviceman'])->find($model->user_id);
                    if (!$to_user || !$user) return;

                    $senderMeta = static::buildSenderMetadata($user);
                    if (!$senderMeta) {
                        return;
                    }

                    $receiverProviderId = static::resolveReceiverProviderId($to_user);
                    $userNotification = static::isChatNotificationEnabledForReceiver($to_user, $receiverProviderId);

                    if ($userNotification && $to_user->fcm_token) {
                        device_notification_for_chatting(
                            $to_user->fcm_token,
                            translate('New message has been arrived'),
                            $model->message,
                            $senderMeta['image'],
                            $model->channel_id,
                            $senderMeta['name'],
                            $senderMeta['image'],
                            $senderMeta['phone'],
                            $senderMeta['type'],
                            'chatting'
                        );
                    }
                });
        });

        self::updating(function ($model) {
            // ... code here
        });

        self::updated(function ($model) {
            // ... code here
        });

        self::deleting(function ($model) {
            // ... code here
        });

        self::deleted(function ($model) {
            // ... code here
        });
    }

    private static function buildSenderMetadata(User $user): ?array
    {
        if (in_array($user->user_type, [USER_TYPES[0]['value'], USER_TYPES[1]['value']])) {
            return [
                'name' => business_config('business_name', 'business_information')?->live_values,
                'phone' => business_config('business_phone', 'business_information')?->live_values,
                'image' => asset('storage/business') . '/' . business_config('business_favicon', 'business_information')?->live_values,
                'type' => $user->user_type,
            ];
        }

        if ($user->user_type === USER_TYPES[2]['value'] && $user->provider) {
            return [
                'name' => $user->provider->company_name,
                'phone' => $user->provider->company_phone,
                'image' => asset('storage/provider/logo') . '/' . $user->provider->logo,
                'type' => $user->user_type,
            ];
        }

        if ($user->user_type === USER_TYPES[3]['value']) {
            return [
                'name' => trim($user->first_name . ' ' . $user->last_name),
                'phone' => $user->phone,
                'image' => asset('storage/serviceman/profile') . '/' . $user->profile_image,
                'type' => $user->user_type,
            ];
        }

        if ($user->user_type === USER_TYPES[4]['value']) {
            return [
                'name' => trim($user->first_name . ' ' . $user->last_name),
                'phone' => $user->phone,
                'image' => asset('storage/user/profile_image') . '/' . $user->profile_image,
                'type' => $user->user_type,
            ];
        }

        return null;
    }

    private static function resolveReceiverProviderId(User $receiver): ?string
    {
        if ($receiver->user_type === USER_TYPES[2]['value']) {
            return Provider::where('user_id', $receiver->id)->value('id');
        }

        if ($receiver->user_type === USER_TYPES[3]['value']) {
            return $receiver->serviceman?->provider_id;
        }

        return null;
    }

    private static function isChatNotificationEnabledForReceiver(User $receiver, ?string $providerId): bool
    {
        return match ($receiver->user_type) {
            USER_TYPES[2]['value'] => (bool)isNotificationActive($providerId, 'chatting', 'notification', 'provider'),
            USER_TYPES[3]['value'] => (bool)isNotificationActive($providerId, 'chatting', 'notification', 'serviceman'),
            USER_TYPES[4]['value'] => (bool)isNotificationActive(null, 'chatting', 'notification', 'user'),
            default => false,
        };
    }
}
