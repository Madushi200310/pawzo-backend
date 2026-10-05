<?php

namespace App\Http\Controllers;

use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Services\ChatbotService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChatbotController extends Controller
{
    public function __construct(protected ChatbotService $chatbot) {}

    /**
     * Send a message and get a reply.
     */
    public function ask(Request $request)
    {
        $validated = $request->validate([
            'message'         => 'required|string|max:2000',
            'conversation_id' => 'nullable|integer|exists:chatbot_conversations,id',
            'session_id'      => 'nullable|string|max:100',
        ]);

        // Resolve user (works with or without auth middleware)
        $user = $request->user() ?? auth('sanctum')->user();

        // Get or create conversation
        $conversation = $this->resolveConversation(
            $validated['conversation_id'] ?? null,
            $validated['session_id'] ?? null,
            $user?->id,
            $validated['message']
        );

        // Save user message
        $userMsg = ChatbotMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'user',
            'message'         => $validated['message'],
        ]);

        // Get AI response
        $startTime = microtime(true);
        $result    = $this->chatbot->respond($validated['message']);
        $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

        // Save assistant message
        $assistantMsg = ChatbotMessage::create([
            'conversation_id'  => $conversation->id,
            'role'             => 'assistant',
            'message'          => $result['message'],
            'source'           => $result['source'],
            'response_time_ms' => $elapsedMs,
        ]);

        // Update conversation metadata
        $conversation->update([
            'message_count'   => $conversation->message_count + 2,
            'last_message_at' => now(),
        ]);

        return response()->json([
            'conversation_id' => $conversation->id,
            'user_message'    => $userMsg,
            'reply'           => $assistantMsg,
        ]);
    }

    /**
     * List my conversations (auth) or guest conversations for a session.
     */
    public function conversations(Request $request)
    {
        // Resolve user (works with or without auth middleware)
        $user = $request->user() ?? auth('sanctum')->user();

        $query = ChatbotConversation::with('messages')
            ->where('is_active', true);

        if ($user) {
            $query->where('user_id', $user->id);
        } else {
            $sessionId = $request->query('session_id');
            if (!$sessionId) {
                return response()->json(['message' => 'session_id required for guests.'], 422);
            }
            $query->where('session_id', $sessionId)->whereNull('user_id');
        }

        return response()->json($query->latest('last_message_at')->paginate(20));
    }

    /**
     * Show a single conversation with all messages.
     */
    public function show(Request $request, ChatbotConversation $conversation)
    {
        if (!$this->canAccess($request, $conversation)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json([
            'data' => $conversation->load('messages'),
        ]);
    }

    /**
     * Delete a conversation.
     */
    public function destroy(Request $request, ChatbotConversation $conversation)
    {
        if (!$this->canAccess($request, $conversation)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $conversation->delete();

        return response()->json(['message' => 'Conversation deleted.']);
    }

    // ==================== HELPERS ====================

    protected function resolveConversation(?int $id, ?string $sessionId, ?int $userId, string $firstMessage): ChatbotConversation
    {
        if ($id) {
            $existing = ChatbotConversation::find($id);
            if ($existing && $this->canOwn($existing, $userId, $sessionId)) {
                return $existing;
            }
        }

        return ChatbotConversation::create([
            'user_id'    => $userId,
            'session_id' => $userId ? null : ($sessionId ?? Str::uuid()->toString()),
            'title'      => Str::limit($firstMessage, 50),
            'is_active'  => true,
        ]);
    }

    protected function canOwn(ChatbotConversation $c, ?int $userId, ?string $sessionId): bool
    {
        if ($userId && $c->user_id === $userId) return true;
        if (!$userId && $sessionId && $c->session_id === $sessionId && $c->user_id === null) return true;
        return false;
    }

    protected function canAccess(Request $request, ChatbotConversation $c): bool
    {
        // Resolve user (works with or without auth middleware)
        $user = $request->user() ?? auth('sanctum')->user();

        if ($user && $c->user_id === $user->id) return true;
        if ($user && $user->isAdmin()) return true;

        $sessionId = $request->query('session_id') ?? $request->input('session_id');
        if (!$user && $sessionId && $c->session_id === $sessionId && $c->user_id === null) return true;

        return false;
    }
}