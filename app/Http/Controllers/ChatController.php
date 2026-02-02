<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\AiAgents\PolicyAssistant;
use Inertia\Inertia;
class ChatController extends Controller
{
    //
    public function index(PolicyAssistant $assistant)
    {
        $history = collect($assistant->chatHistory()->toArray())
            ->filter(fn ($msg) => in_array($msg['role'], ['user', 'assistant']))
            ->map(function ($msg) {
                $content = '';

                // Assistant message (Gemini format)
                if (isset($msg['content']['text'])) {
                    $content = $msg['content']['text'];
                }

                // User / developer message (array of parts)
                elseif (is_array($msg['content'])) {
                    $content = collect($msg['content'])
                        ->pluck('text')
                        ->filter()
                        ->implode("\n");
                }

                return [
                    'role' => $msg['role'],
                    'content' => $content,
                ];
            })
            ->values()
            ->toArray();

        return Inertia::render('Chat', [
            'history' => $history,
        ]);
    }
    public function ask(Request $request, PolicyAssistant $assistant)
    {
        $request->validate(['message' => 'required|string']);

        try {
            $response = $assistant->respond($request->message);

            return back()->with('chat_response', [
                'role' => 'assistant',
                'content' => (string) $response,
            ]);
        } catch (\Exception $e) {
            // Detect if it's a rate limit error
            if (str_contains($e->getMessage(), 'rate limit') || $e->getCode() == 429) {
                return back()->with('chat_response', [
                    'role' => 'assistant',
                    'content' => "⚠️ **System Busy:** I've reached Gemini's free-tier limit (15 requests/min). Please wait about 30-60 seconds and try again.",
                ]);
            }

            // Handle other errors (like safety filters)
            return back()->with('chat_response', [
                'role' => 'assistant',
                'content' => "I encountered an error: " . $e->getMessage(),
            ]);
        }
    }

// Optional: Add a clear method for the UI
    public function clear()
    {
        session()->forget('chat_history');
        return back();
    }
}
