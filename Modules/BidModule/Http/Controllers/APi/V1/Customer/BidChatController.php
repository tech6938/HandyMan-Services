<?php

namespace Modules\BidModule\Http\Controllers\APi\V1\Customer;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\BidModule\Entities\PostBid;
use Modules\ChattingModule\Entities\ChannelConversation;
use Modules\ChattingModule\Entities\ChannelList;
use Modules\ChattingModule\Entities\ChannelUser;
use Modules\ChattingModule\Entities\ConversationFile;
use Modules\ChattingModule\Traits\ChattingTrait;
use Ramsey\Uuid\Nonstandard\Uuid;
use function file_uploader;
use function getSuperAdminId;
use function response;
use function response_formatter;

class BidChatController extends Controller
{
    protected ChannelList $channelList;
    protected ChannelUser $channelUser;
    protected ChannelConversation $channelConversation;
    protected ConversationFile $conversationFile;
    protected PostBid $postBid;

    use ChattingTrait;

    public function __construct(ChannelList $channelList, ChannelUser $channelUser, ChannelConversation $channelConversation, ConversationFile $conversationFile, PostBid $postBid)
    {
        $this->channelList = $channelList;
        $this->channelUser = $channelUser;
        $this->channelConversation = $channelConversation;
        $this->conversationFile = $conversationFile;
        $this->postBid = $postBid;
    }

    /**
     * Get or create chat channel for a bid
     * @param Request $request
     * @return JsonResponse
     */
    // public function getOrCreateChannel(Request $request): JsonResponse
    public function getOrCreateChannel(Request $request)
    {
      
        $validator = Validator::make($request->all(), [
            'post_bid_id' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
        }

        $postBid = $this->postBid->with(['provider.owner', 'post.customer'])->find($request['post_bid_id']);
        if (!$postBid) {
            return response()->json(response_formatter(DEFAULT_404, null), 404);
            }
            // return $postBid;
            // return $request->user();

        // Verify customer owns the post
        if ($postBid->post->customer_user_id !== $request->user()->id) {
            return response()->json(response_formatter(DEFAULT_403, null), 403);
        }
        // return 'yes';
        $providerUserId = $postBid->provider->owner->id ?? null;
        // return $postBid;
        $adminUserId = getSuperAdminId();

        if (!$providerUserId) {
            return response()->json(response_formatter(DEFAULT_404, ['message' => 'Provider not found']), 404);
        }

        // Find existing channel for this bid
        $existingChannel = $this->channelList
            ->where('reference_id', $request['post_bid_id'])
            ->where('reference_type', 'post_bid_id')
            ->whereHas('channelUsers', function ($query) use ($request) {
                $query->where('user_id', $request->user()->id);
            })
            ->first();

        if ($existingChannel) {
            $channel = $this->channelList
                ->with(['channelUsers.user'])
                ->find($existingChannel->id);
            return response()->json(response_formatter(DEFAULT_200, $channel), 200);
        }

        // Create new channel with customer, provider, and admin
        DB::beginTransaction();
        try {
            $channel = $this->channelList;
            $channel->reference_id = $request['post_bid_id'];
            $channel->reference_type = 'post_bid_id';
            $channel->save();

            // Add all three users to channel
            $users = [
                $request->user()->id,      // Customer
                $providerUserId,           // Provider
                $adminUserId               // Admin
            ];

            $channelUsers = [];
            foreach ($users as $userId) {
                $channelUsers[] = [
                    'id' => Uuid::uuid4(),
                    'channel_id' => $channel->id,
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }

            $this->channelUser->insert($channelUsers);

            DB::commit();

            $channel = $this->channelList
                ->with(['channelUsers.user'])
                ->find($channel->id);

            return response()->json(response_formatter(DEFAULT_STORE_200, $channel), 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(response_formatter(DEFAULT_FAIL_200, ['message' => $e->getMessage()]), 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return JsonResponse
     */
    public function conversation(Request $request): JsonResponse
{
    $validator = Validator::make($request->all(), [
        'channel_id' => 'required|uuid',
        'limit'      => 'required|numeric|min:0|max:200',
        'offset'     => 'required|numeric|min:0|max:100000',
    ]);

    if ($validator->fails()) {
        return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
    }

    $this->channelUser->where('channel_id', $request['channel_id'])
        ->where('user_id', $request->user()->id)
        ->update(['is_read' => 1]);

    $conversation = $this->channelConversation
        ->where(['channel_id' => $request['channel_id']])
        ->with(['user', 'conversationFiles'])
        ->whereHas('channel.channelUsers', function ($query) use ($request) {
            $query->where(['user_id' => $request->user()->id]);
        })
        ->latest()
        ->paginate($request['limit'], ['*'], 'offset', $request['offset'])
        ->withPath('');

    // ✅ Add full URL for each file
    $conversation->getCollection()->transform(function ($item) {
        if ($item->conversationFiles && $item->conversationFiles->count()) {
            $item->conversationFiles->transform(function ($file) {

                // stored_file_name should be only filename (e.g. abc.jpg)
                // but in case old data has "conversation/abc.jpg" handle both
                $stored = ltrim($file->stored_file_name ?? '', '/');

                $path = str_starts_with($stored, 'conversation/')
                    ? $stored
                    : 'conversation/' . $stored;

                $file->url = $stored ? asset($path) : null;

                return $file;
            });
        }

        return $item;
    });

    return response()->json(response_formatter(DEFAULT_STORE_200, $conversation), 200);
}


    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return JsonResponse
     */
    public function sendMessage(Request $request): JsonResponse
{
    $validator = Validator::make($request->all(), [
        'message' => 'nullable|string',
        'channel_id' => 'required|uuid',
        'files' => is_null($request['message']) ? 'required|array' : 'nullable|array',
        'files.*' => 'max:10240|mimes:' . implode(',', array_column(FILE_TYPE, 'key')),
    ]);

    if ($validator->fails()) {
        return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
    }

    DB::transaction(function () use ($request) {

        $this->channelList->where('id', $request['channel_id'])->update([
            'updated_at' => now()
        ]);

        $this->channelUser->where('channel_id', $request['channel_id'])
            ->where('user_id', '!=', $request->user()->id)
            ->update([
                'is_read' => 0
            ]);

        $channelConversation = $this->channelConversation;
        $channelConversation->channel_id = $request->channel_id;
        $channelConversation->message = $request['message'];
        $channelConversation->user_id = $request->user()->id;
        $channelConversation->save();

        // ✅ Move files to public/conversation
        if ($request->hasFile('files')) {

            $destinationPath = public_path('conversation');

            // Create folder if not exists
            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }

            foreach ($request->file('files') as $file) {

                $extension = $file->getClientOriginalExtension();
                $originalName = $file->getClientOriginalName();

                $storedName = time() . '-' . uniqid() . '.' . $extension;

                // Move file
                $file->move($destinationPath, $storedName);

                // Save only filename in DB
                $this->conversationFile->create([
                    'conversation_id'    => $channelConversation->id,
                    'original_file_name' => $originalName,
                    'stored_file_name'   => $storedName, // ✅ only filename
                    'file_type'          => $extension,
                ]);
            }
        }
    });

    return response()->json(response_formatter(DEFAULT_STORE_200), 200);
}

}
