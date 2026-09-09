import CabinetLayout from '@/Layouts/CabinetLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { Loader2, Pencil, Send } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    isActionableConfirmation,
    resolvedConfirmationCopy,
} from '@/personal-workspace/confirmationState';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function newClientId() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
        const rand = (Math.random() * 16) | 0;
        const value = char === 'x' ? rand : (rand & 0x3) | 0x8;

        return value.toString(16);
    });
}

export default function CabinetChat() {
    const {
        conversation,
        messages: initialMessages = [],
        hasMore: initialHasMore = false,
        oldestId: initialOldestId = null,
        user = {},
    } = usePage().props;

    const [messages, setMessages] = useState(initialMessages);
    const [hasMore, setHasMore] = useState(initialHasMore);
    const [oldestId, setOldestId] = useState(initialOldestId);
    const [draft, setDraft] = useState('');
    const [sending, setSending] = useState(false);
    const [loadingOlder, setLoadingOlder] = useState(false);
    const [error, setError] = useState('');
    const [editingTitle, setEditingTitle] = useState(false);
    const [titleDraft, setTitleDraft] = useState(conversation?.title ?? '');
    const scrollerRef = useRef(null);
    const shouldStickToBottom = useRef(true);
    const turnGenerationRef = useRef(0);
    const abortRef = useRef(null);

    useEffect(() => {
        setMessages(initialMessages);
        setHasMore(initialHasMore);
        setOldestId(initialOldestId);
        setTitleDraft(conversation?.title ?? '');
        setError('');
        shouldStickToBottom.current = true;
    }, [conversation?.id, initialHasMore, initialMessages, initialOldestId, conversation?.title]);

    useEffect(() => {
        if (!shouldStickToBottom.current || !scrollerRef.current) {
            return;
        }

        scrollerRef.current.scrollTop = scrollerRef.current.scrollHeight;
    }, [messages]);

    const timezone = user.timezone || undefined;

    const formatTime = (iso) => {
        if (!iso) {
            return '';
        }

        try {
            return new Date(iso).toLocaleString(undefined, {
                timeZone: timezone,
                hour: '2-digit',
                minute: '2-digit',
            });
        } catch {
            return iso;
        }
    };

    const sendBody = async (body) => {
        const text = body.trim();

        if (!text) {
            return;
        }

        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;
        const generation = ++turnGenerationRef.current;

        const clientMessageId = newClientId();
        const optimistic = {
            id: `tmp-${clientMessageId}`,
            kind: 'user',
            role: 'user',
            channel: 'web',
            body: text,
            occurred_at: new Date().toISOString(),
            pending: true,
        };

        shouldStickToBottom.current = true;
        setDraft('');
        setSending(true);
        setError('');
        setMessages((current) => [...current, optimistic]);

        try {
            const response = await fetch(route('cabinet.chats.messages.store', conversation.id), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    body: text,
                    client_message_id: clientMessageId,
                }),
                signal: controller.signal,
            });

            const payload = await response.json();

            if (generation !== turnGenerationRef.current) {
                return;
            }

            if (!response.ok) {
                throw new Error(payload.message || 'Не удалось отправить сообщение.');
            }

            setMessages((current) => {
                const withoutOptimistic = current.filter((item) => item.id !== optimistic.id);
                const next = [...withoutOptimistic];

                if (payload.inbound && !next.some((item) => Number(item.id) === Number(payload.inbound.id))) {
                    next.push(payload.inbound);
                }

                if (payload.assistant && !next.some((item) => Number(item.id) === Number(payload.assistant.id))) {
                    next.push(payload.assistant);
                }

                if (payload.error) {
                    next.push({
                        id: `error-${clientMessageId}`,
                        kind: 'error',
                        role: 'system',
                        channel: 'web',
                        body: payload.error,
                        occurred_at: new Date().toISOString(),
                    });
                }

                return next;
            });

            if (payload.error) {
                setError(payload.error);
            }
        } catch (caught) {
            if (caught?.name === 'AbortError' || generation !== turnGenerationRef.current) {
                return;
            }
            setError(caught.message || 'Не удалось получить ответ от AI. Попробуйте ещё раз позже.');
            setMessages((current) => current.filter((item) => item.id !== optimistic.id));
            setDraft(text);
        } finally {
            if (generation === turnGenerationRef.current) {
                setSending(false);
            }
        }
    };

    const send = async () => {
        await sendBody(draft);
    };

    const loadOlder = async () => {
        if (!hasMore || loadingOlder || !oldestId) {
            return;
        }

        setLoadingOlder(true);
        shouldStickToBottom.current = false;
        const scroller = scrollerRef.current;
        const previousHeight = scroller?.scrollHeight ?? 0;

        try {
            const url = new URL(route('cabinet.chats.messages.index', conversation.id), window.location.origin);
            url.searchParams.set('before_id', String(oldestId));

            const response = await fetch(url.toString(), {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await response.json();
            const incoming = payload.messages ?? [];

            setMessages((current) => {
                const existing = new Set(current.map((item) => Number(item.id)));
                const prepended = incoming.filter((item) => !existing.has(Number(item.id)));

                return [...prepended, ...current];
            });
            setHasMore(Boolean(payload.has_more));
            setOldestId(payload.oldest_id ?? oldestId);

            requestAnimationFrame(() => {
                if (scroller) {
                    scroller.scrollTop = scroller.scrollHeight - previousHeight;
                }
            });
        } finally {
            setLoadingOlder(false);
        }
    };

    const saveTitle = () => {
        const title = titleDraft.trim();

        if (!title || title === conversation.title) {
            setEditingTitle(false);
            setTitleDraft(conversation.title);

            return;
        }

        router.patch(
            route('cabinet.chats.update', conversation.id),
            { title },
            {
                preserveScroll: true,
                onFinish: () => setEditingTitle(false),
            },
        );
    };

    const empty = messages.length === 0;

    return (
        <CabinetLayout>
            <Head title={conversation?.title ?? 'Chat'} />

            <div className="flex h-full min-h-0 flex-col">
                <div className="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3 sm:px-6">
                    {editingTitle ? (
                        <input
                            autoFocus
                            value={titleDraft}
                            maxLength={120}
                            onChange={(event) => setTitleDraft(event.target.value)}
                            onBlur={saveTitle}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    saveTitle();
                                }
                                if (event.key === 'Escape') {
                                    setEditingTitle(false);
                                    setTitleDraft(conversation.title);
                                }
                            }}
                            className="h-10 w-full max-w-lg rounded-lg border border-slate-300 px-3 text-base font-semibold text-slate-900"
                        />
                    ) : (
                        <div className="flex min-w-0 items-center gap-2">
                            <h1 className="truncate text-lg font-semibold text-slate-900">{conversation.title}</h1>
                            <button
                                type="button"
                                onClick={() => setEditingTitle(true)}
                                className="rounded-lg p-2 text-slate-500 hover:bg-slate-100"
                                aria-label="Rename chat"
                            >
                                <Pencil className="h-4 w-4" />
                            </button>
                        </div>
                    )}
                </div>

                <div ref={scrollerRef} className="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-6">
                    {hasMore ? (
                        <div className="mb-4 flex justify-center">
                            <button
                                type="button"
                                disabled={loadingOlder}
                                onClick={loadOlder}
                                className="rounded-full border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-60"
                            >
                                {loadingOlder ? 'Loading…' : 'Load older'}
                            </button>
                        </div>
                    ) : null}

                    {empty ? (
                        <div className="flex h-full items-center justify-center">
                            <p className="max-w-sm text-center text-sm text-slate-500">
                                Напишите первое сообщение. История общая с Telegram.
                            </p>
                        </div>
                    ) : (
                        <div className="mx-auto flex max-w-3xl flex-col gap-3">
                            {messages.map((message) => (
                                <Bubble
                                    key={message.id}
                                    message={message}
                                    time={formatTime(message.occurred_at)}
                                    sending={sending}
                                    onConfirm={() => sendBody('да')}
                                    onCancel={() => sendBody('отмена')}
                                />
                            ))}
                            {sending ? (
                                <div className="flex items-center gap-2 text-sm text-slate-500">
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                    LAVR печатает…
                                </div>
                            ) : null}
                        </div>
                    )}
                </div>

                <div className="border-t border-slate-200 bg-white px-4 py-3 sm:px-6">
                    {error ? <p className="mb-2 text-sm text-red-600">{error}</p> : null}
                    <form
                        className="mx-auto flex max-w-3xl items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            send();
                        }}
                    >
                        <textarea
                            value={draft}
                            rows={1}
                            placeholder="Сообщение"
                            disabled={sending}
                            onChange={(event) => setDraft(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter' && !event.shiftKey) {
                                    event.preventDefault();
                                    send();
                                }
                            }}
                            className="max-h-40 min-h-[44px] flex-1 resize-none rounded-2xl border border-slate-300 px-4 py-3 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100 disabled:opacity-60"
                        />
                        <button
                            type="submit"
                            disabled={sending || !draft.trim()}
                            className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-indigo-600 text-white transition hover:bg-indigo-700 disabled:opacity-60"
                            aria-label="Send"
                        >
                            {sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                        </button>
                    </form>
                </div>
            </div>
        </CabinetLayout>
    );
}

function Bubble({ message, time, sending = false, onConfirm, onCancel }) {
    if (message.kind === 'error') {
        return (
            <div className="mx-auto max-w-xl rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-center text-sm text-red-700">
                {message.body}
            </div>
        );
    }

    const mine = message.kind === 'user';
    const pending = message.pending_confirmation;

    return (
        <div className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
            <div
                className={`max-w-[85%] rounded-2xl px-4 py-2 shadow-sm sm:max-w-[70%] ${
                    mine ? 'bg-indigo-600 text-white' : 'bg-white text-slate-900 ring-1 ring-slate-200'
                } ${message.pending ? 'opacity-70' : ''}`}
            >
                <p className="whitespace-pre-wrap break-words text-sm leading-6">{message.body}</p>
                {pending?.summary && !mine ? (
                    <p className="mt-2 text-xs leading-5 text-slate-600">{pending.summary}</p>
                ) : null}
                {pending?.preview && !mine ? (
                    <div className="mt-2 space-y-1 rounded-lg bg-slate-50 px-3 py-2 text-xs leading-5 text-slate-700">
                        {pending.preview.to?.length ? <p>To: {pending.preview.to.join(', ')}</p> : null}
                        {pending.preview.cc?.length ? <p>Cc: {pending.preview.cc.join(', ')}</p> : null}
                        {pending.preview.subject ? <p>Subject: {pending.preview.subject}</p> : null}
                        {pending.preview.body_preview ? (
                            <p className="whitespace-pre-wrap">{pending.preview.body_preview}</p>
                        ) : null}
                    </div>
                ) : null}
                {pending?.id && !mine && isActionableConfirmation(pending) ? (
                    <div className="mt-2 flex gap-2">
                        <button
                            type="button"
                            disabled={sending}
                            onClick={onConfirm}
                            className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
                        >
                            Confirm
                        </button>
                        <button
                            type="button"
                            disabled={sending}
                            onClick={onCancel}
                            className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                        >
                            Cancel
                        </button>
                    </div>
                ) : null}
                {pending?.id && !mine && !isActionableConfirmation(pending) && resolvedConfirmationCopy(pending) ? (
                    <p className="mt-2 text-xs font-medium text-slate-600">{resolvedConfirmationCopy(pending)}</p>
                ) : null}
                <p className={`mt-1 text-[11px] ${mine ? 'text-indigo-100' : 'text-slate-400'}`}>{time}</p>
            </div>
        </div>
    );
}
