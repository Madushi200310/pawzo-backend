<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use Illuminate\Http\Request;

class ChatbotMonitoringController extends Controller
{
    /**
     * List all conversations (admin).
     */
    public function index(Request $request)
    {
        $query = ChatbotConversation::with(['user:id,name,email', 'messages']);

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->boolean('guests_only')) {
            $query->whereNull('user_id');
        }

        if ($request->boolean('auth_only')) {
            $query->whereNotNull('user_id');
        }

        return response()->json($query->latest('last_message_at')->paginate(30));
    }

    /**
     * View one conversation.
     */
    public function show(ChatbotConversation $conversation)
    {
        return response()->json([
            'data' => $conversation->load(['user', 'messages']),
        ]);
    }

    /**
     * Usage statistics.
     */
    public function stats()
    {
        return response()->json([
            'total_conversations' => ChatbotConversation::count(),
            'guest_conversations' => ChatbotConversation::whereNull('user_id')->count(),
            'user_conversations'  => ChatbotConversation::whereNotNull('user_id')->count(),
            'total_messages'      => ChatbotMessage::count(),
            'user_messages'       => ChatbotMessage::where('role', 'user')->count(),
            'bot_messages'        => ChatbotMessage::where('role', 'assistant')->count(),
            'by_source' => [
                'rule'     => ChatbotMessage::where('source', 'rule')->count(),
                'openai'   => ChatbotMessage::where('source', 'openai')->count(),
                'fallback' => ChatbotMessage::where('source', 'fallback')->count(),
            ],
            'messages_today' => ChatbotMessage::whereDate('created_at', today())->count(),
        ]);
    }

    /**
     * Delete a conversation (admin action).
     */
    public function destroy(ChatbotConversation $conversation)
    {
        $conversation->delete();

        return response()->json(['message' => 'Conversation deleted.']);
    }
}