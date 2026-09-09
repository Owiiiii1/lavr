import WorkspaceSideNav from '@/Components/Jarvis/WorkspaceSideNav';
import JarvisWorkspaceLayout from '@/Layouts/JarvisWorkspaceLayout';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Download, HardDrive } from 'lucide-react';
import { useState } from 'react';

function formatBytes(bytes) {
    const value = Number(bytes || 0);

    if (value < 1024) {
        return `${value} B`;
    }

    if (value < 1024 * 1024) {
        return `${(value / 1024).toFixed(1)} KB`;
    }

    return `${(value / (1024 * 1024)).toFixed(1)} MB`;
}

function statusLabel(status) {
    return {
        uploaded: 'Uploading',
        processing: 'Processing',
        ready: 'Ready',
        failed: 'Failed',
        deleted: 'Deleted',
    }[status] || status || '—';
}

export default function StorageShow() {
    const { file, preview = {}, conversations = [], user = {}, owlAdmin = {}, flash = {} } = usePage().props;
    const brandName = owlAdmin?.brand_name ?? 'LAVR';
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const rename = useForm({ display_name: file.display_name });

    const loadMore = () => {
        router.get(
            route('jarvis.storage.show', file.public_id),
            { offset: Number(preview.offset || 0) + Number(preview.limit || 0) },
            { preserveScroll: true },
        );
    };

    return (
        <div>
            <JarvisWorkspaceLayout
                title={file.display_name}
                sidebarOpen={sidebarOpen}
                header={
                    <header className="flex h-14 shrink-0 items-center justify-between gap-3 border-b border-white/10 bg-black/20 px-3 sm:px-4">
                        <div className="min-w-0">
                            <p className="text-sm font-semibold text-white">{brandName}</p>
                            <p className="truncate text-xs text-slate-400">{file.display_name}</p>
                        </div>
                        <Link href={route('jarvis.storage.index')} className="rounded-lg px-3 py-1.5 text-xs text-slate-400 hover:bg-white/10 hover:text-white">
                            All files
                        </Link>
                    </header>
                }
                sidebar={
                    <WorkspaceSideNav
                        brandName={brandName}
                        conversations={conversations}
                        storageActive
                        timezone={user.timezone}
                        onClose={() => setSidebarOpen(false)}
                    />
                }
                context={<div className="p-4 text-sm text-slate-400">Preview is bounded. Download for the full original.</div>}
            >
                <div className="flex min-h-0 flex-1 flex-col overflow-y-auto px-4 py-6 sm:px-8">
                    <div className="mx-auto w-full max-w-4xl space-y-5">
                        <div className="flex items-start gap-3">
                            <HardDrive className="mt-1 h-6 w-6 text-sky-300" />
                            <div>
                                <h1 className="text-2xl font-semibold text-white">{file.display_name}</h1>
                                <p className="mt-1 text-sm text-slate-400">
                                    {statusLabel(file.status)} · {formatBytes(file.size_bytes)} · {file.chunk_count || 0} chunks
                                </p>
                            </div>
                        </div>

                        {flash?.success ? <p className="text-sm text-emerald-300">{flash.success}</p> : null}

                        <dl className="grid gap-3 rounded-2xl border border-white/10 bg-white/5 p-4 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-slate-500">Type</dt>
                                <dd className="text-slate-200">{file.extension || file.mime_type}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">Uploaded</dt>
                                <dd className="text-slate-200">{file.uploaded_at ? new Date(file.uploaded_at).toLocaleString() : '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">Source chat</dt>
                                <dd>
                                    {file.source_chat?.conversation_id ? (
                                        <Link href={route('jarvis.chats.show', file.source_chat.conversation_id)} className="text-sky-300 hover:text-sky-200">
                                            From: {file.source_chat.title || 'Chat'}
                                        </Link>
                                    ) : (
                                        <span className="text-slate-400">Direct upload</span>
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">Extracted</dt>
                                <dd className="text-slate-200">{file.extracted_chars || 0} chars</dd>
                            </div>
                        </dl>

                        {file.summary ? (
                            <section className="rounded-2xl border border-white/10 bg-white/5 p-4">
                                <h2 className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Summary</h2>
                                <p className="mt-2 whitespace-pre-wrap text-sm text-slate-200">{file.summary}</p>
                            </section>
                        ) : null}

                        {file.status === 'failed' ? (
                            <p className="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-2 text-sm text-rose-200">
                                Processing failed. Original file is kept for retry.
                            </p>
                        ) : null}

                        <section className="rounded-2xl border border-white/10 bg-black/20 p-4">
                            <h2 className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Preview</h2>
                            {file.status === 'processing' || file.status === 'uploaded' ? (
                                <p className="mt-3 text-sm text-slate-400">Processing…</p>
                            ) : (
                                <pre className="mt-3 max-h-[28rem] overflow-auto whitespace-pre-wrap break-words text-xs text-slate-200">
                                    {preview.text || 'No extracted text.'}
                                </pre>
                            )}
                            {preview.has_more ? (
                                <button type="button" onClick={loadMore} className="mt-3 rounded-lg border border-white/10 px-3 py-1.5 text-sm text-slate-200 hover:bg-white/5">
                                    Load more
                                </button>
                            ) : null}
                        </section>

                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                rename.patch(route('jarvis.storage.update', file.public_id), { preserveScroll: true });
                            }}
                        >
                            <label className="text-sm text-slate-400">
                                Rename
                                <input
                                    value={rename.data.display_name}
                                    onChange={(event) => rename.setData('display_name', event.target.value)}
                                    className="mt-1 block w-72 rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white outline-none focus:border-sky-400/40"
                                />
                            </label>
                            <button type="submit" className="rounded-lg bg-white/10 px-3 py-2 text-sm text-white hover:bg-white/15">
                                Save name
                            </button>
                        </form>

                        <div className="flex flex-wrap gap-2">
                            <a
                                href={route('jarvis.storage.download', file.public_id)}
                                className="inline-flex items-center gap-2 rounded-lg border border-white/10 px-3 py-2 text-sm text-slate-200 hover:bg-white/5"
                            >
                                <Download className="h-4 w-4" />
                                Download
                            </a>
                            <button
                                type="button"
                                onClick={() => setConfirmDelete(true)}
                                className="rounded-lg border border-rose-400/30 px-3 py-2 text-sm text-rose-200 hover:bg-rose-500/10"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            </JarvisWorkspaceLayout>

            {confirmDelete ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" onClick={() => setConfirmDelete(false)}>
                    <div className="w-full max-w-md rounded-2xl border border-white/10 bg-[#10182a] p-5" onClick={(event) => event.stopPropagation()}>
                        <h2 className="text-base font-semibold text-white">Delete this file?</h2>
                        <p className="mt-2 text-sm text-slate-400">
                            {file.display_name} will be removed from LAVR Storage. This cannot be undone.
                        </p>
                        <div className="mt-4 flex justify-end gap-2">
                            <button type="button" onClick={() => setConfirmDelete(false)} className="rounded-lg px-3 py-2 text-sm text-slate-400">
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={() => router.delete(route('jarvis.storage.destroy', file.public_id))}
                                className="rounded-lg bg-rose-500 px-3 py-2 text-sm font-medium text-white"
                            >
                                Delete file
                            </button>
                        </div>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
