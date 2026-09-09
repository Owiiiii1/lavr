import WorkspaceSideNav from '@/Components/Jarvis/WorkspaceSideNav';
import JarvisWorkspaceLayout from '@/Layouts/JarvisWorkspaceLayout';
import { Link, router, usePage } from '@inertiajs/react';
import { HardDrive, Search, Upload } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

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

export default function StorageIndex() {
    const {
        files = [],
        pagination = {},
        query: initialQuery = '',
        conversations = [],
        user = {},
        jarvisStorage = {},
        owlAdmin = {},
        flash = {},
        errors = {},
    } = usePage().props;

    const brandName = owlAdmin?.brand_name ?? 'LAVR';
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [search, setSearch] = useState(initialQuery);
    const [dragging, setDragging] = useState(false);
    const fileInputRef = useRef(null);
    const accept = jarvisStorage.accept || '';

    const errorText = useMemo(() => {
        if (errors.files) {
            return Array.isArray(errors.files) ? errors.files[0] : String(errors.files);
        }

        return '';
    }, [errors]);

    const submitFiles = (fileList) => {
        const incoming = Array.from(fileList || []).filter(Boolean);

        if (incoming.length === 0) {
            return;
        }

        const data = new FormData();
        incoming.forEach((file) => data.append('files[]', file));
        data.append('client_upload_id', crypto.randomUUID?.() || String(Date.now()));

        router.post(route('jarvis.storage.store'), data, {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    const runSearch = (event) => {
        event.preventDefault();
        router.get(route('jarvis.storage.index'), { q: search }, { preserveState: true });
    };

    return (
        <div>
            <JarvisWorkspaceLayout
                title="Storage"
                sidebarOpen={sidebarOpen}
                header={
                    <header className="flex h-14 shrink-0 items-center justify-between gap-3 border-b border-white/10 bg-black/20 px-3 sm:px-4">
                        <div>
                            <p className="text-sm font-semibold text-white">{brandName}</p>
                            <p className="text-xs text-slate-400">Storage</p>
                        </div>
                        <Link href={route('jarvis.index')} className="rounded-lg px-3 py-1.5 text-xs text-slate-400 hover:bg-white/10 hover:text-white">
                            Chats
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
                context={
                    <div className="p-4 text-sm text-slate-400">
                        Persistent owner files. Screenshots in chat stay ephemeral and are not saved here.
                    </div>
                }
            >
                <div className="flex min-h-0 flex-1 flex-col overflow-y-auto px-4 py-6 sm:px-8">
                    <div className="mx-auto w-full max-w-5xl space-y-5">
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <h1 className="flex items-center gap-2 text-2xl font-semibold text-white">
                                    <HardDrive className="h-6 w-6" />
                                    Storage
                                </h1>
                                <p className="mt-1 text-sm text-slate-400">
                                    Text and source files stay until you delete them. Max {jarvisStorage.max_file_size_mb || 20} MB each.
                                </p>
                            </div>
                            <form onSubmit={runSearch} className="flex items-center gap-2 rounded-xl border border-white/10 bg-black/20 px-3 py-2">
                                <Search className="h-3.5 w-3.5 text-slate-500" />
                                <input
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    placeholder="Search files"
                                    className="w-48 bg-transparent text-sm text-slate-200 outline-none placeholder:text-slate-600"
                                />
                            </form>
                        </div>

                        {flash?.success ? <p className="text-sm text-emerald-300">{flash.success}</p> : null}
                        {errorText ? <p className="text-sm text-rose-300">{errorText}</p> : null}

                        <div
                            className={`rounded-2xl border border-dashed px-4 py-8 text-center ${
                                dragging ? 'border-sky-400/60 bg-sky-400/10' : 'border-white/15 bg-white/5'
                            }`}
                            onDragOver={(event) => {
                                event.preventDefault();
                                setDragging(true);
                            }}
                            onDragLeave={() => setDragging(false)}
                            onDrop={(event) => {
                                event.preventDefault();
                                setDragging(false);
                                submitFiles(event.dataTransfer?.files);
                            }}
                        >
                            <Upload className="mx-auto h-6 w-6 text-slate-400" />
                            <p className="mt-2 text-sm text-slate-300">Drop text files here or choose from disk</p>
                            <button
                                type="button"
                                onClick={() => fileInputRef.current?.click()}
                                className="mt-3 rounded-lg bg-sky-500 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-400"
                            >
                                Upload files
                            </button>
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept={accept}
                                multiple
                                hidden
                                onChange={(event) => {
                                    submitFiles(event.target.files);
                                    event.target.value = '';
                                }}
                            />
                        </div>

                        {files.length === 0 ? (
                            <p className="text-sm text-slate-500">No files yet.</p>
                        ) : (
                            <div className="overflow-hidden rounded-2xl border border-white/10">
                                <table className="w-full text-left text-sm">
                                    <thead className="bg-white/5 text-[11px] uppercase tracking-[0.12em] text-slate-500">
                                        <tr>
                                            <th className="px-3 py-2 font-medium">Name</th>
                                            <th className="px-3 py-2 font-medium">Type</th>
                                            <th className="px-3 py-2 font-medium">Size</th>
                                            <th className="px-3 py-2 font-medium">Status</th>
                                            <th className="px-3 py-2 font-medium">Date</th>
                                            <th className="px-3 py-2 font-medium">Source</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {files.map((file) => (
                                            <tr key={file.public_id} className="border-t border-white/10 text-slate-200">
                                                <td className="px-3 py-2">
                                                    <Link href={route('jarvis.storage.show', file.public_id)} className="font-medium text-sky-200 hover:text-white">
                                                        {file.display_name}
                                                    </Link>
                                                    {file.summary ? (
                                                        <p className="mt-0.5 line-clamp-2 text-[11px] text-slate-500">{file.summary}</p>
                                                    ) : null}
                                                </td>
                                                <td className="px-3 py-2 text-slate-400">{file.extension || '—'}</td>
                                                <td className="px-3 py-2 text-slate-400">{formatBytes(file.size_bytes)}</td>
                                                <td className="px-3 py-2">{statusLabel(file.status)}</td>
                                                <td className="px-3 py-2 text-slate-400">
                                                    {file.uploaded_at ? new Date(file.uploaded_at).toLocaleString() : '—'}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {file.source_chat?.conversation_id ? (
                                                        <Link
                                                            href={route('jarvis.chats.show', file.source_chat.conversation_id)}
                                                            className="text-sky-300 hover:text-sky-200"
                                                        >
                                                            From: {file.source_chat.title || 'Chat'}
                                                        </Link>
                                                    ) : (
                                                        <span className="text-slate-500">Direct upload</span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {pagination.last_page > 1 ? (
                            <div className="flex items-center justify-between text-sm text-slate-400">
                                <span>
                                    Page {pagination.current_page} of {pagination.last_page} · {pagination.total} files
                                </span>
                                <div className="flex gap-2">
                                    {pagination.current_page > 1 ? (
                                        <Link
                                            href={route('jarvis.storage.index', { q: initialQuery, page: pagination.current_page - 1 })}
                                            className="rounded-lg border border-white/10 px-3 py-1 hover:bg-white/5"
                                        >
                                            Previous
                                        </Link>
                                    ) : null}
                                    {pagination.current_page < pagination.last_page ? (
                                        <Link
                                            href={route('jarvis.storage.index', { q: initialQuery, page: pagination.current_page + 1 })}
                                            className="rounded-lg border border-white/10 px-3 py-1 hover:bg-white/5"
                                        >
                                            Next
                                        </Link>
                                    ) : null}
                                </div>
                            </div>
                        ) : null}
                    </div>
                </div>
            </JarvisWorkspaceLayout>
        </div>
    );
}
