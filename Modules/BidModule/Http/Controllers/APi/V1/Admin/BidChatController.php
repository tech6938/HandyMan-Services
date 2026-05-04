<?php

namespace Modules\BidModule\Http\Controllers\APi\V1\Admin;

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
use function response;
use function response_formatter;

class BidChatController extends Controller
{
    protected ChannelList $channel_list;
    protected ChannelUser $channel_user;
    protected ChannelConversation $channel_conversation;
    protected ConversationFile $conversation_file;
    protected PostBid $postBid;

    public function __construct(
        ChannelList $channel_list,
        ChannelUser $channel_user,
        ChannelConversation $channelConversation,
        ConversationFile $conversation_file,
        PostBid $postBid
    ) {
        $this->channel_list = $channel_list;
        $this->channel_user = $channel_user;
        $this->channel_conversation = $channelConversation;
        $this->conversation_file = $conversation_file;
        $this->postBid = $postBid;
    }

    /**
     * Get chat channel for a bid
     */
    public function getChannel(Request $request): JsonResponse
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

        $channel = $this->channel_list
            ->where('reference_id', $request['post_bid_id'])
            ->where('reference_type', 'post_bid_id')
            ->whereHas('channelUsers', function ($query) use ($request) {
                $query->where('user_id', $request->user()->id);
            })
            ->with(['channelUsers.user'])
            ->first();

        if (!$channel) {
            return response()->json(response_formatter(DEFAULT_404, ['message' => 'Chat channel not found']), 404);
        }

        return response()->json(response_formatter(DEFAULT_200, $channel), 200);
    }

    /**
     * Conversation list (adds full URLs for files)
     */
    public function conversation(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'channel_id' => 'required|uuid',
            'limit'      => 'required|numeric|min:1|max:200',
            'offset'     => 'required|numeric|min:0|max:100000', // ✅ allow 0
        ]);

        if ($validator->fails()) {
            return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
        }

        $this->channel_user
            ->where('channel_id', $request['channel_id'])
            ->where('user_id', $request->user()->id)
            ->update(['is_read' => 1]);

        $conversation = $this->channel_conversation
            ->where(['channel_id' => $request['channel_id']])
            ->with(['user', 'conversationFiles'])
            ->whereHas('channel.channelUsers', function ($query) use ($request) {
                $query->where(['user_id' => $request->user()->id]);
            })
            ->latest()
            ->paginate($request['limit'], ['*'], 'offset', $request['offset'])
            ->withPath('');

        // ✅ Add full URL (supports both old `file_name` and new `stored_file_name`)
        $conversation->getCollection()->transform(function ($item) {

            if ($item->conversationFiles && $item->conversationFiles->count()) {
                $item->conversationFiles->transform(function ($file) {

                    // prefer stored_file_name, fallback to file_name
                    $raw = $file->stored_file_name ?? $file->file_name ?? null;
                    $raw = $raw ? ltrim($raw, '/') : '';

                    if (!$raw) {
                        $file->url = null;
                        return $file;
                    }

                    // if already "conversation/xyz.jpg" keep it; else prepend
                    $path = str_starts_with($raw, 'conversation/')
                        ? $raw
                        : 'conversation/' . $raw;

                    $file->url = asset($path);

                    return $file;
                });
            }

            return $item;
        });

        return response()->json(response_formatter(DEFAULT_STORE_200, $conversation), 200);
    }

    /**
     * Send message (move files to public/conversation)
     */
    public function sendMessage(Request $request): JsonResponse
    {
      dd('hi');
        $validator = Validator::make($request->all(), [
            'message'    => 'nullable|string',
            'channel_id' => 'required|uuid',
            'files'      => $request->filled('message') ? 'nullable|array' : 'required|array',
            'files.*'    => 'max:10240|mimes:' . implode(',', array_column(FILE_TYPE, 'key')),
        ]);

        if ($validator->fails()) {
            return response()->json(response_formatter(DEFAULT_400, null, error_processor($validator)), 400);
        }

        DB::transaction(function () use ($request) {

            $this->channel_list->where('id', $request['channel_id'])->update([
                'updated_at' => now()
            ]);

            $this->channel_user
                ->where('channel_id', $request['channel_id'])
                ->where('user_id', '!=', $request->user()->id)
                ->update(['is_read' => 0]);

            $channelConversation = $this->channel_conversation;
            $channelConversation->channel_id = $request->channel_id;
            $channelConversation->message = $request->input('message');
            $channelConversation->user_id = $request->user()->id;
            $channelConversation->save();

            // ✅ Upload to public/conversation
            if ($request->hasFile('files')) {

                $destinationPath = public_path('conversation');

                if (!file_exists($destinationPath)) {
                    mkdir($destinationPath, 0755, true);
                }

                foreach ($request->file('files') as $file) {

                    $extension    = $file->getClientOriginalExtension();
                    $originalName = $file->getClientOriginalName();

                    $storedName = time() . '-' . uniqid() . '.' . $extension;

                    // Move file
                    $file->move($destinationPath, $storedName);

                    // ✅ Save (support both schemas)
                    $data = [
                        'conversation_id' => $channelConversation->id,
                        'file_type'       => $extension,
                    ];

                    // If your table has these columns, they'll be filled:
                    $data['original_file_name'] = $originalName;
                    $data['stored_file_name']   = $storedName;

                    // If your table only has file_name column, this will also be filled:
                    // (storing relative path keeps old frontend compatible)
                    $data['file_name'] = 'conversation/' . $storedName;

                    $this->conversation_file->create($data);
                }
            }
        });

        return response()->json(response_formatter(DEFAULT_STORE_200), 200);
    }
}
