import React, { useState, useEffect, useRef } from 'react';
import { useForm, Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ReactMarkdown from 'react-markdown';
export default function Chat({ auth, chat_response, history }) {
    const [messages, setMessages] = useState(history || [
        { role: 'assistant', content: 'Hello! I am your Policy Assistant.' }
    ]);

    const scrollRef = useRef(null);

    const { data, setData, post, processing, reset } = useForm({
        message: '',
    });

    // Handle new responses coming back from the Agent
    useEffect(() => {
        if (chat_response) {
            setMessages((prev) => [...prev, chat_response]);
        }
    }, [chat_response]);

    // Auto-scroll to bottom on new messages
    useEffect(() => {
        scrollRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages, processing]);

    const submit = (e) => {
        e.preventDefault();
        if (!data.message.trim() || processing) return;

        // Optimistically add user message to UI
        const userMsg = { role: 'user', content: data.message };
        setMessages((prev) => [...prev, userMsg]);

        post(route('chat.ask'), {
            onSuccess: () => reset('message'),
        });
    };

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Policy Assistant" />

            <div className="py-12 max-w-4xl mx-auto sm:px-6 lg:px-8 h-[80vh] flex flex-col">
                <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg flex-grow flex flex-col">

                    {/* Message Area */}
                    <div className="flex-grow overflow-y-auto p-6 space-y-4">
                        {messages.map((msg, idx) => (
                            <div key={idx} className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                                <div className={`max-w-[80%] rounded-lg px-4 py-2 ${
                                    msg.role === 'user' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-800'
                                }`}>
                                    <div className="prose prose-sm max-w-none break-words">
                                        <ReactMarkdown>
                                            {typeof msg.content === 'string'
                                                ? msg.content
                                                : (msg.content?.[0]?.text || msg.content?.text || JSON.stringify(msg.content))}
                                        </ReactMarkdown>
                                    </div>
                                </div>
                            </div>
                        ))}

                        {/* Loading State (Thinking) */}
                        {processing && (
                            <div className="flex justify-start">
                                <div className="bg-gray-100 text-gray-500 rounded-lg px-4 py-2 text-sm flex items-center gap-2">
                                    <span className="animate-pulse">●</span>
                                    <span className="animate-pulse delay-75">●</span>
                                    <span className="animate-pulse delay-150">●</span>
                                    <span>Agent is analyzing policies...</span>
                                </div>
                            </div>
                        )}
                        <div ref={scrollRef} />
                    </div>

                    {/* Input Area */}
                    <form onSubmit={submit} className="p-4 border-t border-gray-200">
                        <div className="flex gap-2">
                            <input
                                type="text"
                                value={data.message}
                                onChange={(e) => setData('message', e.target.value)}
                                placeholder="Ask about deadlines, travel, or leave..."
                                className="flex-grow border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                disabled={processing}
                            />
                            <button
                                type="submit"
                                disabled={processing || !data.message}
                                className="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 disabled:opacity-50 transition"
                            >
                                Send
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
