import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Loader2, Send } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function formatStamp(iso, timezone) {
    if (!iso) {
        return '';
    }

    try {
        return new Date(iso).toLocaleString(undefined, {
            timeZone: timezone || undefined,
            dateStyle: 'medium',
            timeStyle: 'short',
        });
    } catch {
        return iso;
    }
}

export default function TelegramGroupShow() {
    const {
        group,
        messages: initialMessages = [],
        hasMore: initialHasMore = false,
        oldestId: initialOldestId = null,
        locale = 'en',
        knowledge = { summary: [], decisions: [], tasks: [], events: [] },
        analysisRuns = [],
        flash = {},
    } = usePage().props;

    const [messages, setMessages] = useState(initialMessages);
    const [hasMore, setHasMore] = useState(initialHasMore);
    const [oldestId, setOldestId] = useState(initialOldestId);
    const [draft, setDraft] = useState('');
    const [sending, setSending] = useState(false);
    const [loadingOlder, setLoadingOlder] = useState(false);
    const [error, setError] = useState('');
    const [analysisTab, setAnalysisTab] = useState('summary');
    const [customFrom, setCustomFrom] = useState('');
    const [customTo, setCustomTo] = useState('');
    const [highlightId, setHighlightId] = useState(null);
    const scrollerRef = useRef(null);
    const shouldStickToBottom = useRef(true);
    const timezoneForm = useForm({ timezone: group.timezone ?? '' });

    useEffect(() => {
        setMessages(initialMessages);
        setHasMore(initialHasMore);
        setOldestId(initialOldestId);
        setError('');
        shouldStickToBottom.current = true;
        timezoneForm.setData('timezone', group.timezone ?? '');
    }, [group.id, initialHasMore, initialMessages, initialOldestId, group.timezone]);

    useEffect(() => {
        if (!shouldStickToBottom.current || !scrollerRef.current) {
            return;
        }

        scrollerRef.current.scrollTop = scrollerRef.current.scrollHeight;
    }, [messages]);

    const timezone = group.effective_timezone;
    const analysisForm = useForm({ preset: 'today', from: '', to: '' });
    const notice = flash?.analysis === 'queued'
        ? (locale === 'ru' ? 'Анализ поставлен в очередь' : 'Analysis queued')
        : '';

    const focusSource = (messageId) => {
        setHighlightId(Number(messageId));
        const node = document.getElementById(`group-msg-${messageId}`);
        if (node) {
            node.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    };

    const runPreset = (preset) => {
        analysisForm.setData({ preset, from: '', to: '' });
        analysisForm.post(route('telegram-groups.analysis.store', group.id), { preserveScroll: true });
    };

    const runCustom = (event) => {
        event.preventDefault();
        analysisForm.setData({ preset: 'custom', from: customFrom, to: customTo });
        analysisForm.transform(() => ({ preset: 'custom', from: customFrom, to: customTo }));
        analysisForm.post(route('telegram-groups.analysis.store', group.id), { preserveScroll: true });
    };

    const send = async () => {
        const body = draft.trim();

        if (!body || sending) {
            return;
        }

        setSending(true);
        setError('');
        setDraft('');
        shouldStickToBottom.current = true;

        try {
            const response = await fetch(route('telegram-groups.messages.store', group.id), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ body }),
            });
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload.message || 'Failed to send the message.');
            }

            setMessages((current) => {
                if (current.some((item) => Number(item.id) === Number(payload.message.id))) {
                    return current;
                }

                return [...current, payload.message];
            });
        } catch (caught) {
            setError(caught.message || 'Failed to send the message.');
            setDraft(body);
        } finally {
            setSending(false);
        }
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
            const url = new URL(route('telegram-groups.messages.index', group.id), window.location.origin);
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

    return (
        <AdminLayout title={group.title}>
            <Head title={group.title} />
            <div className="mb-4 flex flex-wrap items-start justify-between gap-4">
                <div className="space-y-1 text-sm text-slate-600">
                    <button
                        type="button"
                        onClick={() => router.visit(
                            group.status === 'left'
                                ? route('telegram-groups.archive')
                                : route('telegram-groups.index'),
                        )}
                        className="inline-flex h-9 items-center rounded-lg border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50"
                    >
                        {locale === 'ru' ? 'Назад' : 'Back'}
                    </button>
                    <p>
                        <span className="font-medium text-slate-800">{group.status}</span>
                        {' · '}
                        {group.chat_type}
                        {group.username ? ` · @${group.username}` : ''}
                    </p>
                    <p>
                        Monitoring: Persist only
                        {' · '}
                        Timezone: {timezone}
                        {group.timezone_is_fallback ? ' (owner fallback)' : ''}
                    </p>
                    <p className="text-xs text-slate-400">Telegram chat id: {group.telegram_chat_id}</p>
                </div>
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        timezoneForm.patch(route('telegram-groups.update', group.id), { preserveScroll: true });
                    }}
                >
                    <label className="text-xs font-medium text-slate-600">
                        IANA timezone
                        <input
                            value={timezoneForm.data.timezone}
                            onChange={(event) => timezoneForm.setData('timezone', event.target.value)}
                            placeholder={timezone}
                            className="mt-1 block w-56 rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                    </label>
                    <button
                        type="submit"
                        disabled={timezoneForm.processing}
                        className="inline-flex h-10 items-center rounded-lg border border-slate-300 px-3 text-sm disabled:opacity-50"
                    >
                        Save
                    </button>
                    {timezoneForm.errors.timezone && (
                        <p className="w-full text-xs text-red-600">{timezoneForm.errors.timezone}</p>
                    )}
                </form>
            </div>

            <div className="flex h-[62vh] flex-col overflow-hidden rounded-xl border border-slate-200 bg-[#FBF8F1]">
                <div ref={scrollerRef} className="flex-1 space-y-3 overflow-y-auto px-4 py-4">
                    {hasMore && (
                        <div className="flex justify-center">
                            <button
                                type="button"
                                onClick={loadOlder}
                                disabled={loadingOlder}
                                className="text-xs font-medium text-slate-500 hover:text-slate-800"
                            >
                                {loadingOlder ? 'Loading…' : 'Load older messages'}
                            </button>
                        </div>
                    )}
                    {messages.map((message) => {
                        const isBot = message.kind === 'bot' || message.group_outbound;
                        return (
                            <div
                                key={message.id}
                                id={`group-msg-${message.id}`}
                                className={`flex ${isBot ? 'justify-end' : 'justify-start'}`}
                            >
                                <div
                                    className={`max-w-[80%] rounded-2xl px-3 py-2 text-sm shadow-sm ${
                                        Number(highlightId) === Number(message.id)
                                            ? 'ring-2 ring-amber-400'
                                            : ''
                                    } ${
                                        isBot
                                            ? 'bg-[#0B1220] text-white'
                                            : 'border border-slate-200 bg-white text-slate-800'
                                    }`}
                                >
                                    <div className={`mb-1 text-[11px] ${isBot ? 'text-amber-200/80' : 'text-slate-500'}`}>
                                        {isBot ? 'LAVR' : (message.sender_name || 'Unknown')}
                                        {message.sender_username && !isBot ? ` @${message.sender_username}` : ''}
                                        {' · '}
                                        {formatStamp(message.occurred_at, timezone)}
                                        {message.edited_at ? ' · edited' : ''}
                                        {message.thread_id ? ` · topic ${message.thread_id}` : ''}
                                        {message.reply_to_channel_message_id ? ' · reply' : ''}
                                    </div>
                                    {message.message_type !== 'text' && (
                                        <p className={`mb-1 text-[11px] uppercase tracking-wide ${isBot ? 'text-slate-300' : 'text-slate-500'}`}>
                                            {message.message_type}
                                            {message.media?.file_name ? ` · ${message.media.file_name}` : ''}
                                            {message.media?.mime_type ? ` · ${message.media.mime_type}` : ''}
                                        </p>
                                    )}
                                    <p className="whitespace-pre-wrap break-words">{message.body}</p>
                                </div>
                            </div>
                        );
                    })}
                </div>
                <div className="border-t border-slate-200 bg-white p-3">
                    {group.status === 'left' ? (
                        <p className="text-sm text-slate-600">
                            {locale === 'ru'
                                ? 'Бот больше не в этой группе. История сохранена в архиве.'
                                : 'The bot is no longer in this group. History is kept in the archive.'}
                        </p>
                    ) : (
                        <>
                            {error && <p className="mb-2 text-xs text-red-600">{error}</p>}
                            <div className="flex items-end gap-2">
                                <textarea
                                    value={draft}
                                    onChange={(event) => setDraft(event.target.value)}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter' && !event.shiftKey) {
                                            event.preventDefault();
                                            send();
                                        }
                                    }}
                                    rows={2}
                                    placeholder="Message the group…"
                                    className="min-h-[44px] flex-1 resize-none rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                />
                                <button
                                    type="button"
                                    onClick={send}
                                    disabled={sending || !draft.trim()}
                                    className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-[#0B1220] text-white disabled:opacity-50"
                                >
                                    {sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                                </button>
                            </div>
                        </>
                    )}
                </div>
            </div>

            <section className="mt-6 space-y-4 rounded-xl border border-slate-200 bg-white p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-sm font-semibold text-slate-900">Analysis</h2>
                    {notice ? <p className="text-xs font-medium text-emerald-700">{notice}</p> : null}
                </div>
                <div className="flex flex-wrap gap-2">
                    <button type="button" disabled={analysisForm.processing} onClick={() => runPreset('today')} className="inline-flex h-9 items-center rounded-lg bg-[#0B1220] px-3 text-xs font-medium text-white disabled:opacity-50">
                        Analyze today
                    </button>
                    <button type="button" disabled={analysisForm.processing} onClick={() => runPreset('yesterday')} className="inline-flex h-9 items-center rounded-lg border border-slate-300 px-3 text-xs font-medium disabled:opacity-50">
                        Analyze yesterday
                    </button>
                    <button type="button" disabled={analysisForm.processing} onClick={() => runPreset('last_7_days')} className="inline-flex h-9 items-center rounded-lg border border-slate-300 px-3 text-xs font-medium disabled:opacity-50">
                        Analyze last 7 days
                    </button>
                </div>
                <form className="flex flex-wrap items-end gap-2" onSubmit={runCustom}>
                    <label className="text-xs font-medium text-slate-600">
                        From
                        <input type="date" value={customFrom} onChange={(event) => setCustomFrom(event.target.value)} className="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    </label>
                    <label className="text-xs font-medium text-slate-600">
                        To
                        <input type="date" value={customTo} onChange={(event) => setCustomTo(event.target.value)} className="mt-1 block rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    </label>
                    <button type="submit" disabled={analysisForm.processing || !customFrom || !customTo} className="inline-flex h-9 items-center rounded-lg border border-slate-300 px-3 text-xs font-medium disabled:opacity-50">
                        Custom range
                    </button>
                    {analysisForm.errors.preset ? <p className="w-full text-xs text-red-600">{analysisForm.errors.preset}</p> : null}
                </form>

                <div className="flex flex-wrap gap-2 border-b border-slate-200 pb-2">
                    {['summary', 'decisions', 'tasks', 'events', 'runs'].map((tab) => (
                        <button
                            key={tab}
                            type="button"
                            onClick={() => setAnalysisTab(tab)}
                            className={`h-8 rounded-lg px-3 text-xs font-medium capitalize ${
                                analysisTab === tab ? 'bg-[#0B1220] text-white' : 'text-slate-600 hover:bg-slate-100'
                            }`}
                        >
                            {tab === 'events' ? 'Events/Facts' : tab === 'runs' ? 'Analysis Runs' : tab}
                        </button>
                    ))}
                </div>

                {analysisTab !== 'runs' ? (
                    <KnowledgeList
                        items={
                            analysisTab === 'summary'
                                ? knowledge.summary
                                : analysisTab === 'decisions'
                                    ? knowledge.decisions
                                    : analysisTab === 'tasks'
                                        ? knowledge.tasks
                                        : knowledge.events
                        }
                        timezone={timezone}
                        onSource={focusSource}
                        empty={locale === 'ru' ? 'Пока нет записей.' : 'No items yet.'}
                    />
                ) : (
                    <div className="space-y-3">
                        {analysisRuns.length === 0 ? (
                            <p className="text-sm text-slate-500">{locale === 'ru' ? 'Запусков анализа пока нет.' : 'No analysis runs yet.'}</p>
                        ) : analysisRuns.map((run) => (
                            <article key={run.id} className="rounded-lg border border-slate-200 p-3 text-sm">
                                <p className="font-medium text-slate-800">
                                    {run.status}
                                    {run.no_data ? ' · no data' : ''}
                                    {run.provider ? ` · ${run.provider}` : ''}
                                    {run.model ? ` / ${run.model}` : ''}
                                </p>
                                <p className="text-xs text-slate-500">
                                    {formatStamp(run.from_at, timezone)} → {formatStamp(run.to_at, timezone)}
                                </p>
                                <p className="mt-1 text-xs text-slate-600">
                                    summaries {run.counts?.summaries ?? 0}
                                    {' · '}decisions {run.counts?.decisions ?? 0}
                                    {' · '}tasks {run.counts?.tasks ?? 0}
                                    {' · '}events {run.counts?.events ?? 0}
                                    {' · '}chunks {run.counts?.chunk_count ?? 0}
                                </p>
                                {run.last_error ? <p className="mt-1 text-xs text-red-600">{run.last_error}</p> : null}
                                {run.can_retry ? (
                                    <button
                                        type="button"
                                        onClick={() => router.post(route('telegram-groups.analysis.retry', [group.id, run.id]), {}, { preserveScroll: true })}
                                        className="mt-2 inline-flex h-8 items-center rounded-lg border border-slate-300 px-3 text-xs"
                                    >
                                        Retry
                                    </button>
                                ) : null}
                            </article>
                        ))}
                    </div>
                )}
            </section>
        </AdminLayout>
    );
}

function KnowledgeList({ items = [], timezone, onSource, empty }) {
    if (!items.length) {
        return <p className="text-sm text-slate-500">{empty}</p>;
    }

    return (
        <div className="space-y-3">
            {items.map((item) => (
                <article key={item.id} className="rounded-lg border border-slate-200 p-3 text-sm">
                    <p className="text-xs uppercase tracking-wide text-slate-500">
                        {item.status}
                        {item.confidence != null ? ` · ${item.confidence}` : ''}
                        {item.generated_at ? ` · ${formatStamp(item.generated_at, timezone)}` : ''}
                    </p>
                    <p className="mt-1 whitespace-pre-wrap text-slate-800">{item.content}</p>
                    {item.structured_data?.assignee_text ? (
                        <p className="mt-1 text-xs text-slate-600">Assignee: {item.structured_data.assignee_text}</p>
                    ) : null}
                    {item.structured_data?.due_at_local ? (
                        <p className="text-xs text-slate-600">Due: {item.structured_data.due_at_local}</p>
                    ) : null}
                    <div className="mt-2 flex flex-wrap gap-2">
                        {(item.sources || []).map((source) => (
                            <button
                                key={`${item.id}-${source.message_id}`}
                                type="button"
                                onClick={() => onSource(source.message_id)}
                                className="rounded-full bg-slate-100 px-2 py-1 text-[11px] text-slate-700 hover:bg-amber-100"
                            >
                                #{source.message_id}
                                {source.sender_name ? ` · ${source.sender_name}` : ''}
                                {source.snippet ? ` · ${source.snippet}` : ''}
                            </button>
                        ))}
                    </div>
                </article>
            ))}
        </div>
    );
}
