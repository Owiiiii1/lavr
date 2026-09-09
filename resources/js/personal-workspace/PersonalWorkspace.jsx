import SafeMarkdown from '@/Components/Jarvis/SafeMarkdown';
import JarvisWorkspaceLayout from '@/Layouts/JarvisWorkspaceLayout';
import LavrAppShell from '@/telegram/LavrAppShell';
import { workspaceRoute } from '@/personal-workspace/named';
import {
    isActionableConfirmation,
    resolvedConfirmationCopy,
    withConfirmationState,
} from '@/personal-workspace/confirmationState';
import RemindersPanel from '@/personal-workspace/RemindersPanel';
import TasksPanel from '@/personal-workspace/TasksPanel';
import WatchersPanel from '@/personal-workspace/WatchersPanel';
import ReportsPanel from '@/personal-workspace/ReportsPanel';
import OverviewPanel from '@/personal-workspace/OverviewPanel';
import NotificationsPanel from '@/personal-workspace/NotificationsPanel';
import ConversationDeleteDialog from '@/personal-workspace/ConversationDeleteDialog';
import ConversationSidebarItem from '@/personal-workspace/ConversationSidebarItem';
import WorkspaceSettings from '@/personal-workspace/settings/WorkspaceSettings';
import { allowedSettingsSection } from '@/personal-workspace/settings/sections';
import { primeVoiceMediaFromUserGesture } from '@/voice/audio/voiceMedia';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    Check,
    CheckSquare,
    Eye,
    FileText,
    FolderKanban,
    HardDrive,
    Inbox,
    LayoutDashboard,
    Loader2,
    Menu,
    MessageSquarePlus,
    Mic,
    PanelRight,
    Pencil,
    Paperclip,
    Search,
    Send,
    Settings2,
    Type,
    X,
} from 'lucide-react';
import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react';

const SUGGESTIONS = [
    'Что у меня сегодня?',
    'Проверь календарь',
    'Посмотри новые письма',
    'Что изменилось в LAVR?',
    'Напомни...',
];

const USER_SUGGESTIONS = [
    'Что у меня сегодня?',
    'Напомни...',
    'Найди в моих файлах',
    'Поищи в интернете',
];

const VoiceSession = lazy(() => import('@/Components/Jarvis/WorkspaceVoice'));

/** Survives a page-component remount: Inertia drops component state on every non-preserveState visit. */
let lastKnownConversations = [];

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

const IMAGE_MIME_ALIASES = {
    'image/jpg': 'image/jpeg',
    'image/pjpeg': 'image/jpeg',
    'image/jfif': 'image/jpeg',
    'image/jpe': 'image/jpeg',
    'image/x-jpeg': 'image/jpeg',
};

function normalizeImageMime(type) {
    const raw = String(type || '').toLowerCase().trim();

    return IMAGE_MIME_ALIASES[raw] || raw;
}

const BLOCKED_CLIENT_MIMES = [
    'image/svg+xml',
    'text/html',
    'text/xml',
    'application/xml',
    'application/pdf',
    'application/javascript',
    'application/x-php',
    'application/x-executable',
    'application/zip',
];

function isDesktopPointer() {
    return typeof window !== 'undefined' && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
}

function focusComposer(textarea, { forceDesktopOnly = true } = {}) {
    if (!textarea || textarea.disabled) {
        return;
    }

    if (forceDesktopOnly && !isDesktopPointer()) {
        return;
    }

    textarea.focus({ preventScroll: true });
}

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

function fileExtension(name) {
    const parts = String(name || '').toLowerCase().split('.');

    return parts.length > 1 ? parts.pop() : '';
}
function isAllowedClientImage(file) {
    const mime = normalizeImageMime(file?.type);

    if (BLOCKED_CLIENT_MIMES.includes(mime) || mime.includes('svg')) {
        return false;
    }

    return Boolean(file) && (mime.startsWith('image/') || mime === '');
}

function isStorageTextFile(file, allowedExtensions = []) {
    const ext = fileExtension(file?.name);

    if (ext && allowedExtensions.includes(ext)) {
        return true;
    }

    const mime = String(file?.type || '').toLowerCase();

    return mime.startsWith('text/') || mime === 'application/json' || mime === 'application/xml';
}

function withStatus(message, status = 'completed') {
    return {
        ...message,
        status: message.status ?? status,
    };
}

function draftKey(surface, conversationId) {
    return `${surface}.draft.${conversationId}`;
}

function formatWhen(iso, timezone) {
    if (!iso) {
        return '';
    }

    try {
        return new Date(iso).toLocaleString(undefined, {
            timeZone: timezone || undefined,
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return iso;
    }
}

function providerLabel(toolName) {
    if (!toolName) {
        return 'Action';
    }
    if (toolName.includes('gmail')) {
        return 'Gmail';
    }
    if (toolName.includes('calendar')) {
        return 'Calendar';
    }
    if (toolName.includes('github')) {
        return 'GitHub';
    }
    return toolName.replaceAll('_', ' ');
}

export default function PersonalWorkspace() {
    const {
        conversation,
        conversations = [],
        messages: initialMessages = [],
        hasMore: initialHasMore = false,
        oldestId: initialOldestId = null,
        user = {},
        context = {},
        owlAdmin = {},
        flash = {},
        chatAttachments = null,
        jarvisStorage = null,
        voiceClient = {},
        surface: surfaceProp = 'jarvis',
        capabilities: capabilityProps = {},
        settings = {},
        settingsContext: settingsContextProp = {},
        assistantProfile: assistantProfileProp = {},
        activeReminderCount: activeReminderCountProp = 0,
        activeTaskCount: activeTaskCountProp = 0,
        activeWatcherCount: activeWatcherCountProp = 0,
        activeReportCount: activeReportCountProp = 0,
        unreadNotificationCount: unreadNotificationCountProp = 0,
    } = usePage().props;
    const surface = surfaceProp === 'chat' ? 'chat' : 'jarvis';
    const capabilities = {
        voice: false,
        webResearch: false,
        attachments: false,
        files: false,
        projects: false,
        admin: false,
        integrations: false,
        storagePage: false,
        ownerContext: false,
        reminders: false,
        tasks: false,
        notifications: false,
        memory: false,
        knowledge: false,
        watchers: false,
        scheduledReports: false,
        telegramDm: false,
        ...capabilityProps,
    };

    const timezone = user.timezone || undefined;
    const imageAccept = chatAttachments?.accept
        ? `${chatAttachments.accept},image/*`
        : 'image/*,.jpg,.jpeg,.png,.webp';
    const storageAccept = jarvisStorage?.accept || '';
    const acceptTypes = [imageAccept, storageAccept].filter(Boolean).join(',');
    const maxImages = Number(chatAttachments?.max_images_per_message || 0);
    const maxFileBytes = Number(chatAttachments?.max_file_size_mb || 0) * 1024 * 1024;
    const maxTotalBytes = Number(chatAttachments?.max_total_upload_mb || 0) * 1024 * 1024;
    const maxStorageFiles = Number(jarvisStorage?.max_files_per_upload || 8);
    const maxStorageBytes = Number(jarvisStorage?.max_file_size_mb || 20) * 1024 * 1024;
    const storageExtensions = jarvisStorage?.allowed_extensions || [];
    const allowedMimes = chatAttachments?.allowed_mime_types || [];
    const retentionHours = Number(chatAttachments?.retention_hours || 24);
    const productBrand = owlAdmin?.brand_name ?? 'LAVR';
    const [mode, setMode] = useState('text');
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [contextCollapsed, setContextCollapsed] = useState(!capabilities.ownerContext);
    const [contextDrawer, setContextDrawer] = useState(false);
    const [conversationItems, setConversationItems] = useState(() => (
        Array.isArray(conversations) && conversations.length > 0 ? conversations : lastKnownConversations
    ));
    const [query, setQuery] = useState('');
    const [messages, setMessages] = useState(() => initialMessages.map((item) => withStatus(item)));
    const [hasMore, setHasMore] = useState(initialHasMore);
    const [oldestId, setOldestId] = useState(initialOldestId);
    const [draft, setDraft] = useState('');
    const [pendingFiles, setPendingFiles] = useState([]);
    const [lightbox, setLightbox] = useState(null);
    const [sending, setSending] = useState(false);
    const [loadingOlder, setLoadingOlder] = useState(false);
    const [error, setError] = useState('');
    const [editingTitle, setEditingTitle] = useState(false);
    const [titleDraft, setTitleDraft] = useState(conversation?.title ?? '');
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [settingsSection, setSettingsSection] = useState('profile');
    const [remindersOpen, setRemindersOpen] = useState(false);
    const [tasksOpen, setTasksOpen] = useState(false);
    const [watchersOpen, setWatchersOpen] = useState(false);
    const [reportsOpen, setReportsOpen] = useState(false);
    const [overviewOpen, setOverviewOpen] = useState(false);
    const [notificationsOpen, setNotificationsOpen] = useState(false);
    const [assistantProfile, setAssistantProfile] = useState(assistantProfileProp);
    const [settingsContext, setSettingsContext] = useState(settingsContextProp);
    const [activeReminderCount, setActiveReminderCount] = useState(Number(activeReminderCountProp) || 0);
    const [activeTaskCount, setActiveTaskCount] = useState(Number(activeTaskCountProp) || 0);
    const [activeWatcherCount, setActiveWatcherCount] = useState(Number(activeWatcherCountProp) || 0);
    const [activeReportCount, setActiveReportCount] = useState(Number(activeReportCountProp) || 0);
    const [unreadNotificationCount, setUnreadNotificationCount] = useState(Number(unreadNotificationCountProp) || 0);
    const [productivityRefreshToken, setProductivityRefreshToken] = useState(0);
    const [overviewRefreshToken, setOverviewRefreshToken] = useState(0);
    const [menuConversationId, setMenuConversationId] = useState(null);
    const [pendingDelete, setPendingDelete] = useState(null);
    const [deletingChat, setDeletingChat] = useState(false);
    const [renamingConversationId, setRenamingConversationId] = useState(null);
    const [sidebarTitleDraft, setSidebarTitleDraft] = useState('');
    const workspaceTitle = assistantProfile?.presentation_name
        || (user?.role === 'owner' ? productBrand : 'Assistant');
    const onboardingStatus = assistantProfile?.onboarding_status || 'not_started';
    const showOnboarding = Boolean(assistantProfile?.show_onboarding);
    const onboardingLabel = {
        not_started: 'Знакомство не пройдено',
        in_progress: 'Знакомство в процессе',
        completed: 'Знакомство завершено',
    }[onboardingStatus] || 'Знакомство не пройдено';
    const scrollerRef = useRef(null);
    const fileInputRef = useRef(null);
    const composerRef = useRef(null);
    const shouldStickToBottom = useRef(true);
    const pendingFilesRef = useRef([]);
    const turnGenerationRef = useRef(0);
    const abortRef = useRef(null);

    const openSettings = (nextSection = 'profile') => {
        const allowed = allowedSettingsSection(nextSection) || 'profile';
        setSettingsSection(allowed);
        setSettingsOpen(true);
    };

    const closeSettings = () => {
        setSettingsOpen(false);
    };

    const changeSettingsSection = (nextSection) => {
        const allowed = allowedSettingsSection(nextSection) || 'profile';
        setSettingsSection(allowed);
    };

    const rememberConversations = (items) => {
        lastKnownConversations = items;
        setConversationItems(items);
    };

    const applyProductivityCounts = (payload) => {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        if (typeof payload.active_reminder_count === 'number') {
            setActiveReminderCount(payload.active_reminder_count);
        } else if (typeof payload.reminders?.active_count === 'number') {
            setActiveReminderCount(payload.reminders.active_count);
        }

        if (typeof payload.active_task_count === 'number') {
            setActiveTaskCount(payload.active_task_count);
        } else if (typeof payload.tasks?.active_count === 'number') {
            setActiveTaskCount(payload.tasks.active_count);
        }

        if (typeof payload.active_watcher_count === 'number') {
            setActiveWatcherCount(payload.active_watcher_count);
        } else if (typeof payload.watchers?.active_count === 'number') {
            setActiveWatcherCount(payload.watchers.active_count);
        }

        if (typeof payload.active_report_count === 'number') {
            setActiveReportCount(payload.active_report_count);
        } else if (typeof payload.reports?.active_count === 'number') {
            setActiveReportCount(payload.reports.active_count);
        }

        if (typeof payload.unread_notification_count === 'number') {
            setUnreadNotificationCount(payload.unread_notification_count);
        } else if (typeof payload.notifications?.unread_count === 'number') {
            setUnreadNotificationCount(payload.notifications.unread_count);
        }

        if (payload.assistant_profile) {
            setAssistantProfile(payload.assistant_profile);
        }

        setSettingsContext((current) => ({
            ...current,
            memory: payload.memory ?? current.memory,
            telegram: payload.telegram
                ? { ...current.telegram, ...payload.telegram }
                : current.telegram,
            general_prompt: payload.general_prompt !== undefined ? payload.general_prompt : current.general_prompt,
            integrations: payload.integrations ?? current.integrations,
        }));
    };

    const refreshProductivity = useCallback((turnPayload = null) => {
        applyProductivityCounts(turnPayload);
        setProductivityRefreshToken((current) => current + 1);

        return fetch(workspaceRoute(surface, 'workspace.status'), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(async (response) => {
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    return;
                }

                applyProductivityCounts(payload);
            })
            .catch(() => {});
    }, [surface]);

    const refreshOverview = useCallback(() => {
        setOverviewRefreshToken((current) => current + 1);
    }, []);

    useEffect(() => {
        if (!Array.isArray(conversations) || conversations.length === 0) {
            return;
        }

        lastKnownConversations = conversations;
        setConversationItems(conversations);
    }, [conversations]);

    useEffect(() => {
        if (menuConversationId === null) {
            return undefined;
        }

        const closeMenu = () => setMenuConversationId(null);
        window.addEventListener('click', closeMenu);

        return () => window.removeEventListener('click', closeMenu);
    }, [menuConversationId]);

    useEffect(() => {
        setSettingsContext(settingsContextProp);
    }, [settingsContextProp]);

    useEffect(() => {
        if (typeof window === 'undefined') {
            return undefined;
        }

        const params = new URLSearchParams(window.location.search);

        if (params.get('reminder')) {
            setRemindersOpen(true);
        }

        if (params.get('task')) {
            setTasksOpen(true);
        }

        if (params.get('watchers')) {
            setWatchersOpen(true);
        }

        if (params.get('reports')) {
            setReportsOpen(true);
        }

        if (params.get('notifications')) {
            setNotificationsOpen(true);
        }

        const requestedSettings = allowedSettingsSection(params.get('settings'));

        if (requestedSettings) {
            setSettingsSection(requestedSettings);
            setSettingsOpen(true);
        }

        const onMessage = (event) => {
            if (event.data?.type !== 'open-reminder') {
                return;
            }

            setRemindersOpen(true);

            if (typeof event.data.url === 'string' && event.data.url.startsWith('/') && !event.data.url.includes('://')) {
                const next = event.data.url.split('?')[0];

                if (next && next !== window.location.pathname) {
                    router.visit(event.data.url);
                }
            }
        };

        navigator.serviceWorker?.addEventListener('message', onMessage);

        return () => {
            navigator.serviceWorker?.removeEventListener('message', onMessage);
        };
    }, []);

    useEffect(() => {
        if (!conversation?.id) {
            return;
        }

        setMessages(initialMessages.map((item) => withStatus(item)));
        setHasMore(initialHasMore);
        setOldestId(initialOldestId);
        setTitleDraft(conversation?.title ?? '');
        setError('');
        setSidebarOpen(false);
        setPendingFiles((current) => {
            current.forEach((item) => {
                if (item.url) {
                    URL.revokeObjectURL(item.url);
                }
            });

            return [];
        });
        shouldStickToBottom.current = true;

        try {
            setDraft(window.localStorage.getItem(draftKey(surface, conversation?.id)) ?? '');
        } catch {
            setDraft('');
        }
    }, [conversation?.id]);

    useEffect(() => {
        setAssistantProfile(assistantProfileProp ?? {});
    }, [assistantProfileProp]);

    useEffect(() => {
        setActiveReminderCount(Number(activeReminderCountProp) || 0);
    }, [activeReminderCountProp]);

    useEffect(() => {
        setActiveTaskCount(Number(activeTaskCountProp) || 0);
    }, [activeTaskCountProp]);

    useEffect(() => {
        setActiveWatcherCount(Number(activeWatcherCountProp) || 0);
    }, [activeWatcherCountProp]);

    useEffect(() => {
        setActiveReportCount(Number(activeReportCountProp) || 0);
    }, [activeReportCountProp]);

    useEffect(() => {
        setUnreadNotificationCount(Number(unreadNotificationCountProp) || 0);
    }, [unreadNotificationCountProp]);

    useEffect(() => {
        if (!conversation?.id) {
            return;
        }

        try {
            window.localStorage.setItem(draftKey(surface, conversation.id), draft);
        } catch {
            // ignore quota / private mode
        }
    }, [conversation?.id, draft]);

    useEffect(() => {
        pendingFilesRef.current = pendingFiles;
    }, [pendingFiles]);

    useEffect(() => {
        return () => {
            pendingFilesRef.current.forEach((item) => {
                if (item.url) {
                    URL.revokeObjectURL(item.url);
                }
            });
        };
    }, []);

    useEffect(() => {
        if (! capabilities.voice && mode === 'voice') {
            setMode('text');
        }
    }, [capabilities.voice, mode]);

    useEffect(() => {
        if (!sending) {
            focusComposer(composerRef.current, { forceDesktopOnly: true });
        }
    }, [sending]);

    useEffect(() => {
        if (!shouldStickToBottom.current || !scrollerRef.current) {
            return;
        }

        scrollerRef.current.scrollTop = scrollerRef.current.scrollHeight;
    }, [messages, sending]);

    const filteredConversations = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (!needle) {
            return conversationItems;
        }

        return conversationItems.filter((item) => String(item.title ?? '').toLowerCase().includes(needle));
    }, [conversationItems, query]);

    const applyTurnPayload = (payload, optimisticId, clientMessageId) => {
        setMessages((current) => {
            const withoutOptimistic = current.filter((item) => item.id !== optimisticId);
            let next = withConfirmationState(withoutOptimistic, payload.confirmation);

            if (payload.inbound && !next.some((item) => Number(item.id) === Number(payload.inbound.id))) {
                next.push(withStatus(payload.inbound, 'completed'));
            }

            if (payload.assistant && !next.some((item) => Number(item.id) === Number(payload.assistant.id))) {
                next.push(withStatus(payload.assistant, 'completed'));
            }

            if (payload.error) {
                next.push({
                    id: `error-${clientMessageId}`,
                    kind: 'error',
                    role: 'system',
                    channel: 'web',
                    body: payload.error,
                    occurred_at: new Date().toISOString(),
                    status: 'failed',
                });
            }

            return next;
        });

        if (payload.error) {
            setError(payload.error);
        }

        if (payload.assistant_profile) {
            setAssistantProfile(payload.assistant_profile);
        }

        refreshProductivity(payload);
    };

    const firstError = (payload) => {
        const errors = payload?.errors;

        if (errors && typeof errors === 'object') {
            for (const value of Object.values(errors)) {
                if (Array.isArray(value) && value[0]) {
                    return String(value[0]);
                }

                if (typeof value === 'string' && value !== '') {
                    return value;
                }
            }
        }

        return payload?.message || payload?.error || 'Не удалось отправить сообщение.';
    };

    const addPendingFiles = (fileList) => {
        const incoming = Array.from(fileList || []).filter(Boolean);

        if (incoming.length === 0) {
            return;
        }

        setPendingFiles((current) => {
            const next = [...current];
            let message = '';

            incoming.forEach((file) => {
                const asStorage = isStorageTextFile(file, storageExtensions) && !String(file.type || '').startsWith('image/');

                if (asStorage) {
                    if (next.filter((item) => item.kind === 'file').length >= maxStorageFiles) {
                        message = `Можно прикрепить не больше ${maxStorageFiles} файлов.`;
                        return;
                    }

                    if (file.size > maxStorageBytes) {
                        message = `Файл больше ${jarvisStorage.max_file_size_mb} МБ.`;
                        return;
                    }

                    next.push({
                        id: `local-${newClientId()}`,
                        kind: 'file',
                        file,
                        name: file.name || 'file',
                        mime: file.type,
                        size: file.size,
                    });

                    return;
                }

                if (next.filter((item) => item.kind !== 'file').length >= maxImages) {
                    message = `Можно прикрепить не больше ${maxImages} изображений.`;
                    return;
                }

                if (!isAllowedClientImage(file)) {
                    message = 'Нужны изображения PNG/JPEG/WebP или текстовый файл для Storage.';
                    return;
                }

                if (file.size > maxFileBytes) {
                    message = `Изображение больше ${chatAttachments.max_file_size_mb} МБ.`;
                    return;
                }

                const total = next.filter((item) => item.kind !== 'file').reduce((sum, item) => sum + item.file.size, 0) + file.size;

                if (total > maxTotalBytes) {
                    message = `Суммарный размер изображений больше ${chatAttachments.max_total_upload_mb} МБ.`;
                    return;
                }

                next.push({
                    id: `local-${newClientId()}`,
                    kind: 'image',
                    file,
                    url: URL.createObjectURL(file),
                    name: file.name || 'image',
                    mime: normalizeImageMime(file.type) || file.type,
                    size: file.size,
                });
            });

            if (message) {
                setError(message);
            }

            return next;
        });
    };

    const removePendingFile = (id) => {
        setPendingFiles((current) => {
            const selected = current.find((item) => item.id === id);

            if (selected?.url) {
                URL.revokeObjectURL(selected.url);
            }

            return current.filter((item) => item.id !== id);
        });
        focusComposer(composerRef.current, { forceDesktopOnly: true });
    };

    const handleClipboardPaste = (event) => {
        const items = Array.from(event.clipboardData?.items || []);
        const imageFiles = items
            .filter((item) => allowedMimes.includes(normalizeImageMime(item.type)))
            .map((item) => item.getAsFile())
            .filter(Boolean);

        if (imageFiles.length === 0) {
            return;
        }

        addPendingFiles(imageFiles);

        const text = event.clipboardData?.getData('text/plain') || '';

        if (text === '') {
            event.preventDefault();
        }
    };

    const handleDrop = (event) => {
        event.preventDefault();
        addPendingFiles(event.dataTransfer?.files);
    };

    const sendBody = async (body) => {
        const text = body.trim();
        const files = pendingFiles.map((item) => item.file);

        if (!text && files.length === 0) {
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
            status: 'pending',
            attachments: pendingFiles
                .filter((item) => item.kind !== 'file')
                .map((item) => ({
                    id: item.id,
                    kind: 'image',
                    mime_type: item.mime,
                    size_bytes: item.size,
                    preview_url: item.url,
                    view_url: item.url,
                    retention_class: 'ephemeral',
                })),
            stored_files: pendingFiles
                .filter((item) => item.kind === 'file')
                .map((item) => ({
                    public_id: item.id,
                    display_name: item.name,
                    size_bytes: item.size,
                    status: 'uploaded',
                })),
        };

        shouldStickToBottom.current = true;
        setDraft('');
        setSending(true);
        setError('');
        setMessages((current) => [...current, optimistic]);

        const form = new FormData();
        form.append('body', text);
        form.append('client_message_id', clientMessageId);
        pendingFiles.forEach((item) => {
            if (item.kind === 'file') {
                form.append('files[]', item.file);
            } else {
                form.append('images[]', item.file);
            }
        });

        try {
            const response = await fetch(workspaceRoute(surface, 'messages.store', conversation.id), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: form,
                signal: controller.signal,
            });

            const payload = await response.json().catch(() => ({}));

            if (generation !== turnGenerationRef.current) {
                return;
            }

            if (!response.ok) {
                throw new Error(firstError(payload));
            }

            pendingFiles.forEach((item) => {
                if (item.url) {
                    URL.revokeObjectURL(item.url);
                }
            });
            setPendingFiles([]);
            applyTurnPayload(payload, optimistic.id, clientMessageId);
        } catch (caught) {
            if (caught?.name === 'AbortError' || generation !== turnGenerationRef.current) {
                return;
            }
            setError(caught.message || 'Не удалось получить ответ от LAVR. Попробуйте ещё раз позже.');
            setMessages((current) => [
                ...current.filter((item) => item.id !== optimistic.id),
                {
                    id: `failed-${clientMessageId}`,
                    kind: 'error',
                    role: 'system',
                    channel: 'web',
                    body: caught.message || 'Request failed.',
                    occurred_at: new Date().toISOString(),
                    status: 'failed',
                },
            ]);
            setDraft(text);
        } finally {
            if (generation === turnGenerationRef.current) {
                setSending(false);
            }
        }
    };

    const resolveConfirmation = async (confirmationId, confirm) => {
        if (sending || !confirmationId) {
            return;
        }

        const clientMessageId = newClientId();
        setSending(true);
        setError('');
        shouldStickToBottom.current = true;

        try {
            const url = workspaceRoute(
                surface,
                confirm ? 'confirmations.confirm' : 'confirmations.cancel',
                confirmationId,
            );
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ client_message_id: clientMessageId }),
            });
            const payload = await response.json().catch(() => ({}));

            if (payload.already_resolved && payload.confirmation) {
                setMessages((current) => withConfirmationState(current, payload.confirmation));
                return;
            }

            if (!response.ok) {
                if (response.status === 404 || response.status === 409) {
                    setMessages((current) => withConfirmationState(current, {
                        id: confirmationId,
                        status: 'expired',
                    }));
                    return;
                }

                throw new Error(payload.message || 'Confirmation could not be processed.');
            }

            applyTurnPayload(payload, null, clientMessageId);
        } catch (caught) {
            setError(caught.message || 'Confirmation failed.');
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
            const url = new URL(workspaceRoute(surface, 'messages.older', conversation.id), window.location.origin);
            url.searchParams.set('before_id', String(oldestId));

            const response = await fetch(url.toString(), {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await response.json();
            const incoming = (payload.messages ?? []).map((item) => withStatus(item));

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
            workspaceRoute(surface, 'chats.update', conversation.id),
            { title },
            {
                preserveScroll: true,
                onFinish: () => setEditingTitle(false),
                onSuccess: () => {
                    rememberConversations(conversationItems.map((item) => (
                        Number(item.id) === Number(conversation.id) ? { ...item, title } : item
                    )));
                },
            },
        );
    };

    const startSidebarRename = (item) => {
        setMenuConversationId(null);
        setRenamingConversationId(item.id);
        setSidebarTitleDraft(String(item.title || '').trim());
    };

    const cancelSidebarRename = () => {
        setRenamingConversationId(null);
        setSidebarTitleDraft('');
    };

    const saveSidebarRename = (item) => {
        const title = sidebarTitleDraft.trim();

        if (!title || title === item.title) {
            cancelSidebarRename();

            if (Number(item.id) === Number(conversation?.id)) {
                setEditingTitle(false);
                setTitleDraft(item.title);
            }

            return;
        }

        router.patch(
            workspaceRoute(surface, 'chats.update', item.id),
            { title },
            {
                preserveScroll: true,
                onFinish: () => cancelSidebarRename(),
                onSuccess: () => {
                    rememberConversations(conversationItems.map((row) => (
                        Number(row.id) === Number(item.id) ? { ...row, title } : row
                    )));

                    if (Number(item.id) === Number(conversation?.id)) {
                        setTitleDraft(title);
                    }
                },
            },
        );
    };

    const requestDeleteConversation = (item) => {
        setMenuConversationId(null);
        setPendingDelete(item);
    };

    const deleteConversation = async () => {
        if (!pendingDelete || deletingChat) {
            return;
        }

        setDeletingChat(true);
        setError('');

        try {
            const response = await fetch(workspaceRoute(surface, 'chats.destroy', pendingDelete.id), {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                setError(typeof payload.message === 'string' && payload.message !== ''
                    ? payload.message
                    : 'Не удалось удалить чат.');

                return;
            }

            const deletedId = Number(payload.deleted_id);
            const next = payload.conversation;
            const remaining = conversationItems.filter((row) => Number(row.id) !== deletedId);

            if (next?.id && !remaining.some((row) => Number(row.id) === Number(next.id))) {
                remaining.unshift(next);
            }

            rememberConversations(remaining);
            setPendingDelete(null);

            if (Number(conversation?.id) === deletedId && next?.id) {
                router.visit(workspaceRoute(surface, 'chats.show', next.id), {
                    preserveScroll: true,
                });
            }
        } finally {
            setDeletingChat(false);
        }
    };

    const empty = messages.length === 0;
    const projects = context.projects ?? [];
    const connected = Boolean(owlAdmin?.ai?.connected);

    const closeOverlaysFromBackdrop = (event) => {
        if (event.target?.dataset?.sidebarBackdrop) {
            setSidebarOpen(false);
        }
        if (event.target?.dataset?.contextBackdrop) {
            setContextDrawer(false);
        }
    };

    const header = (
        <header className="flex h-14 shrink-0 items-center justify-between gap-3 border-b border-white/10 bg-black/20 px-3 sm:px-4">
            <div className="flex min-w-0 items-center gap-2">
                <button
                    type="button"
                    className="rounded-lg p-2 text-slate-300 hover:bg-white/10 lg:hidden"
                    onClick={() => setSidebarOpen(true)}
                    aria-label="Open conversations"
                >
                    <Menu className="h-4 w-4" />
                </button>
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <span className="text-sm font-semibold tracking-wide text-white">{workspaceTitle}</span>
                        <span
                            className={`h-2 w-2 rounded-full ${connected ? 'bg-emerald-400' : 'bg-rose-400'}`}
                            title={connected ? 'AI connected' : 'AI not connected'}
                            aria-label={connected ? 'AI connected' : 'AI not connected'}
                        />
                    </div>
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
                            className="mt-0.5 h-7 w-full max-w-md rounded-md border border-white/15 bg-black/30 px-2 text-xs text-white"
                            aria-label="Conversation title"
                        />
                    ) : (
                        <button
                            type="button"
                            onClick={() => setEditingTitle(true)}
                            className="mt-0.5 flex max-w-[46vw] items-center gap-1 truncate text-xs text-slate-400 hover:text-slate-200"
                            aria-label="Rename conversation"
                        >
                            <span className="truncate">{conversation?.title}</span>
                            <Pencil className="h-3 w-3 shrink-0" />
                        </button>
                    )}
                </div>
            </div>

            <div className="flex items-center gap-1 sm:gap-2">
                <div className="inline-flex rounded-full border border-white/10 bg-black/30 p-0.5" role="tablist" aria-label="Mode">
                    <button
                        type="button"
                        role="tab"
                        aria-selected={mode === 'text'}
                        onClick={() => setMode('text')}
                        className={`inline-flex min-h-11 items-center gap-1 rounded-full px-3 py-1.5 text-xs font-medium ${
                            mode === 'text' ? 'bg-white/10 text-white' : 'text-slate-400 hover:text-white'
                        }`}
                    >
                        <Type className="h-3.5 w-3.5" />
                        Text
                    </button>
                    {capabilities.voice ? (
                        <button
                            type="button"
                            role="tab"
                            aria-selected={mode === 'voice'}
                            onClick={() => {
                                primeVoiceMediaFromUserGesture().finally(() => setMode('voice'));
                            }}
                            className={`inline-flex min-h-11 items-center gap-1 rounded-full px-3 py-1.5 text-xs font-medium ${
                                mode === 'voice' ? 'bg-white/10 text-white' : 'text-slate-400 hover:text-white'
                            }`}
                        >
                            <Mic className="h-3.5 w-3.5" />
                            Voice
                        </button>
                    ) : null}
                </div>
                {capabilities.admin ? (
                    <Link
                        href={route('dashboard')}
                        className="hidden rounded-lg px-3 py-1.5 text-xs font-medium text-slate-400 hover:bg-white/10 hover:text-white sm:inline-flex"
                    >
                        Admin
                    </Link>
                ) : null}
                {capabilities.knowledge || capabilities.tasks ? (
                    <button
                        type="button"
                        onClick={() => setOverviewOpen(true)}
                        className="relative inline-flex items-center gap-2 rounded-lg p-2 text-slate-300 hover:bg-white/10 sm:px-3"
                        aria-label="Обзор"
                    >
                        <LayoutDashboard className="h-4 w-4" />
                        <span className="hidden text-xs font-medium sm:inline">Обзор</span>
                    </button>
                ) : null}
                {capabilities.tasks ? (
                    <button
                        type="button"
                        onClick={() => setTasksOpen(true)}
                        className="relative inline-flex items-center gap-2 rounded-lg p-2 text-slate-300 hover:bg-white/10 sm:px-3"
                        aria-label="Задачи"
                    >
                        <CheckSquare className="h-4 w-4" />
                        <span className="hidden text-xs font-medium sm:inline">Задачи</span>
                        {activeTaskCount > 0 ? (
                            <span className="absolute -right-0.5 -top-0.5 min-w-[1.1rem] rounded-full bg-amber-500 px-1 text-[10px] font-semibold leading-4 text-white">
                                {activeTaskCount > 99 ? '99+' : activeTaskCount}
                            </span>
                        ) : null}
                    </button>
                ) : null}
                {capabilities.watchers ? (
                    <button
                        type="button"
                        onClick={() => setWatchersOpen(true)}
                        className="relative inline-flex items-center gap-2 rounded-lg p-2 text-slate-300 hover:bg-white/10 sm:px-3"
                        aria-label="Автоматизации"
                    >
                        <Eye className="h-4 w-4" />
                        <span className="hidden text-xs font-medium sm:inline">Автоматизации</span>
                        {activeWatcherCount > 0 ? (
                            <span className="absolute -right-0.5 -top-0.5 min-w-[1.1rem] rounded-full bg-violet-500 px-1 text-[10px] font-semibold leading-4 text-white">
                                {activeWatcherCount > 99 ? '99+' : activeWatcherCount}
                            </span>
                        ) : null}
                    </button>
                ) : null}
                {capabilities.scheduledReports ? (
                    <button
                        type="button"
                        onClick={() => setReportsOpen(true)}
                        className="relative inline-flex items-center gap-2 rounded-lg p-2 text-slate-300 hover:bg-white/10 sm:px-3"
                        aria-label="Отчеты"
                    >
                        <FileText className="h-4 w-4" />
                        <span className="hidden text-xs font-medium sm:inline">Отчеты</span>
                        {activeReportCount > 0 ? (
                            <span className="absolute -right-0.5 -top-0.5 min-w-[1.1rem] rounded-full bg-emerald-500 px-1 text-[10px] font-semibold leading-4 text-white">
                                {activeReportCount > 99 ? '99+' : activeReportCount}
                            </span>
                        ) : null}
                    </button>
                ) : null}
                {capabilities.reminders ? (
                    <button
                        type="button"
                        onClick={() => setRemindersOpen(true)}
                        className="relative inline-flex items-center gap-2 rounded-lg p-2 text-slate-300 hover:bg-white/10 sm:px-3"
                        aria-label="Напоминания"
                    >
                        <Bell className="h-4 w-4" />
                        <span className="hidden text-xs font-medium sm:inline">Напоминания</span>
                        {activeReminderCount > 0 ? (
                            <span className="absolute -right-0.5 -top-0.5 min-w-[1.1rem] rounded-full bg-sky-500 px-1 text-[10px] font-semibold leading-4 text-white">
                                {activeReminderCount > 99 ? '99+' : activeReminderCount}
                            </span>
                        ) : null}
                    </button>
                ) : null}
                {capabilities.notifications ? (
                    <button
                        type="button"
                        onClick={() => setNotificationsOpen(true)}
                        className="relative inline-flex items-center gap-2 rounded-lg p-2 text-slate-300 hover:bg-white/10 sm:px-3"
                        aria-label="Уведомления"
                    >
                        <Inbox className="h-4 w-4" />
                        <span className="hidden text-xs font-medium sm:inline">Уведомления</span>
                        {unreadNotificationCount > 0 ? (
                            <span className="absolute -right-0.5 -top-0.5 min-w-[1.1rem] rounded-full bg-rose-500 px-1 text-[10px] font-semibold leading-4 text-white">
                                {unreadNotificationCount > 99 ? '99+' : unreadNotificationCount}
                            </span>
                        ) : null}
                    </button>
                ) : null}
                <button
                    type="button"
                    onClick={() => openSettings()}
                    className="inline-flex items-center gap-2 rounded-lg p-2 text-slate-300 hover:bg-white/10 sm:px-3"
                    aria-label="Настройки"
                >
                    <Settings2 className="h-4 w-4" />
                    <span className="hidden text-xs font-medium sm:inline">Настройки</span>
                </button>
                {capabilities.ownerContext ? (
                    <button
                        type="button"
                        onClick={() => {
                            if (window.matchMedia('(min-width: 1280px)').matches) {
                                setContextCollapsed((open) => !open);
                            } else {
                                setContextDrawer((open) => !open);
                            }
                        }}
                        className="rounded-lg p-2 text-slate-300 hover:bg-white/10"
                        aria-label="Toggle context panel"
                        aria-pressed={!contextCollapsed || contextDrawer}
                    >
                        <PanelRight className="h-4 w-4" />
                    </button>
                ) : null}
            </div>
        </header>
    );

    const sidebar = (
        <div className="flex h-full min-h-0 flex-col">
            <div className="flex items-center justify-between px-4 py-4">
                <div>
                    <p className="text-sm font-semibold text-white">{workspaceTitle}</p>
                    <p className="text-[10px] uppercase tracking-[0.18em] text-slate-500">Workspace</p>
                </div>
                <button
                    type="button"
                    className="rounded-lg p-2 text-slate-400 hover:bg-white/10 lg:hidden"
                    onClick={() => setSidebarOpen(false)}
                    aria-label="Close conversations"
                >
                    <X className="h-4 w-4" />
                </button>
            </div>
            <div className="px-3">
                <button
                    type="button"
                    onClick={() => router.post(workspaceRoute(surface, 'chats.store'))}
                    className="inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl bg-sky-500/90 px-3 text-sm font-semibold text-white hover:bg-sky-400"
                >
                    <MessageSquarePlus className="h-4 w-4" />
                    New Chat
                </button>
                {showOnboarding ? (
                    <div className="mt-2 rounded-xl border border-white/10 bg-black/20 px-3 py-2">
                        <p className="text-xs text-slate-400">Знакомство: {onboardingLabel.replace('Знакомство ', '')}</p>
                        {onboardingStatus === 'not_started' ? (
                            <button
                                type="button"
                                onClick={() => router.post(workspaceRoute(surface, 'onboarding.start'))}
                                className="mt-2 w-full rounded-lg bg-white/10 px-2 py-1.5 text-xs font-medium text-white hover:bg-white/15"
                            >
                                Познакомиться
                            </button>
                        ) : null}
                        {onboardingStatus === 'in_progress' ? (
                            <button
                                type="button"
                                onClick={() => router.post(workspaceRoute(surface, 'onboarding.start'))}
                                className="mt-2 w-full rounded-lg bg-white/10 px-2 py-1.5 text-xs font-medium text-white hover:bg-white/15"
                            >
                                Продолжить знакомство
                            </button>
                        ) : null}
                    </div>
                ) : null}
                {capabilities.storagePage ? (
                    <Link
                        href={route('jarvis.storage.index')}
                        onClick={() => setSidebarOpen(false)}
                        className="mt-2 inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl border border-white/10 px-3 text-sm font-semibold text-slate-200 hover:bg-white/5"
                    >
                        <HardDrive className="h-4 w-4" />
                        Storage
                    </Link>
                ) : null}
                <label className="mt-3 flex items-center gap-2 rounded-xl border border-white/10 bg-black/20 px-3 py-2">
                    <Search className="h-3.5 w-3.5 text-slate-500" />
                    <input
                        type="search"
                        name="chat-search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search chats"
                        className="w-full bg-transparent text-sm text-slate-200 outline-none placeholder:text-slate-600"
                        aria-label="Search conversations"
                        autoComplete="off"
                        data-lpignore="true"
                        data-1p-ignore=""
                    />
                </label>
            </div>
            <nav className="mt-3 min-h-0 flex-1 overflow-y-auto px-2 pb-3">
                {filteredConversations.length === 0 ? (
                    <p className="px-3 py-6 text-sm text-slate-500">No chats.</p>
                ) : (
                    <ul className="space-y-1">
                        {filteredConversations.map((item) => (
                            <ConversationSidebarItem
                                key={item.id}
                                item={item}
                                active={Number(item.id) === Number(conversation?.id)}
                                surface={surface}
                                timezone={timezone}
                                menuOpen={Number(menuConversationId) === Number(item.id)}
                                renaming={Number(renamingConversationId) === Number(item.id)}
                                renameDraft={sidebarTitleDraft}
                                onToggleMenu={() => setMenuConversationId(
                                    Number(menuConversationId) === Number(item.id) ? null : item.id,
                                )}
                                onRenameDraft={setSidebarTitleDraft}
                                onSaveRename={() => saveSidebarRename(item)}
                                onCancelRename={cancelSidebarRename}
                                onStartRename={() => startSidebarRename(item)}
                                onRequestDelete={() => requestDeleteConversation(item)}
                                onNavigate={() => setSidebarOpen(false)}
                            />
                        ))}
                    </ul>
                )}
            </nav>
        </div>
    );

    const contextPanel = (
        <div className="flex h-full min-h-0 flex-col">
            <div className="flex items-center justify-between px-4 py-4">
                <p className="text-sm font-semibold text-white">Context</p>
                <button
                    type="button"
                    className="rounded-lg p-2 text-slate-400 hover:bg-white/10 xl:hidden"
                    onClick={() => setContextDrawer(false)}
                    aria-label="Close context"
                >
                    <X className="h-4 w-4" />
                </button>
            </div>
            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-3 pb-4">
                <section className="rounded-2xl border border-white/10 bg-white/5 p-3">
                    <div className="mb-2 flex items-center justify-between">
                        <h2 className="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">
                            <FolderKanban className="h-3.5 w-3.5" />
                            Projects
                        </h2>
                        <Link href={route('projects.index')} className="text-[11px] text-sky-300 hover:text-sky-200">
                            Admin
                        </Link>
                    </div>
                    {projects.length === 0 ? (
                        <p className="text-xs text-slate-500">No active projects.</p>
                    ) : (
                        <ul className="space-y-1.5">
                            {projects.map((project) => (
                                <li key={project.id}>
                                    <Link
                                        href={route('projects.show', project.id)}
                                        className="flex items-center justify-between rounded-lg px-2 py-1.5 text-sm text-slate-200 hover:bg-white/5"
                                    >
                                        <span className="truncate">{project.name}</span>
                                        {project.attached ? (
                                            <span className="ml-2 shrink-0 rounded-full bg-sky-400/15 px-2 py-0.5 text-[10px] text-sky-200">
                                                attached
                                            </span>
                                        ) : null}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </div>
    );

    const pendingVoiceConfirmation = [...messages]
        .reverse()
        .find((item) => isActionableConfirmation(item?.pending_confirmation))
        ?.pending_confirmation ?? null;

    return (
        <LavrAppShell fill>
        <div className="flex h-full min-h-0 flex-col" onClick={closeOverlaysFromBackdrop}>
            <JarvisWorkspaceLayout
                title={conversation?.title ?? workspaceTitle}
                header={header}
                sidebar={sidebar}
                context={capabilities.ownerContext ? contextPanel : null}
                sidebarOpen={sidebarOpen}
                contextCollapsed={!capabilities.ownerContext || contextCollapsed}
                contextDrawer={Boolean(capabilities.ownerContext && contextDrawer)}
            >
                {mode === 'voice' && capabilities.voice ? (
                    <Suspense
                        fallback={
                            <div className="flex h-full items-center justify-center text-sm text-slate-400">
                                Loading Voice…
                            </div>
                        }
                    >
                        <div className="flex h-full min-h-0 flex-col">
                            {pendingVoiceConfirmation ? (
                                <div className="shrink-0 px-4 pt-3">
                                    <ConfirmationCard
                                        pending={pendingVoiceConfirmation}
                                        sending={sending}
                                        onConfirm={() => resolveConfirmation(pendingVoiceConfirmation.id, true)}
                                        onCancel={() => resolveConfirmation(pendingVoiceConfirmation.id, false)}
                                    />
                                </div>
                            ) : null}
                            <VoiceSession
                                conversationId={conversation?.id}
                                surface={surface}
                                voiceClient={voiceClient}
                                onSwitchToText={() => setMode('text')}
                                onTurn={(payload, optimisticId, clientMessageId) => applyTurnPayload(payload, optimisticId, clientMessageId)}
                            />
                        </div>
                    </Suspense>
                ) : (
                    <div className="flex h-full min-h-0 flex-col">
                        <div ref={scrollerRef} className="min-h-0 flex-1 overflow-y-auto px-4 py-5 sm:px-8">
                            {hasMore ? (
                                <div className="mb-4 flex justify-center">
                                    <button
                                        type="button"
                                        disabled={loadingOlder}
                                        onClick={loadOlder}
                                        className="rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-medium text-slate-300 hover:bg-white/10 disabled:opacity-60"
                                    >
                                        {loadingOlder ? 'Loading…' : 'Load older'}
                                    </button>
                                </div>
                            ) : null}

                            {empty ? (
                                <div className="flex h-full flex-col items-center justify-center gap-6">
                                    <p className="text-2xl font-medium tracking-tight text-white">Чем займёмся?</p>
                                    {showOnboarding && onboardingStatus !== 'completed' ? (
                                        <div className="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-center">
                                            <p className="text-sm text-slate-300">{onboardingLabel}</p>
                                            <button
                                                type="button"
                                                onClick={() => router.post(workspaceRoute(surface, 'onboarding.start'))}
                                                className="mt-2 rounded-lg bg-sky-500 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-400"
                                            >
                                                {onboardingStatus === 'in_progress' ? 'Продолжить знакомство' : 'Познакомиться'}
                                            </button>
                                        </div>
                                    ) : null}
                                    <div className="flex max-w-xl flex-wrap justify-center gap-2">
                                        {(capabilities.ownerContext ? SUGGESTIONS : USER_SUGGESTIONS).map((chip) => (
                                            <button
                                                key={chip}
                                                type="button"
                                                onClick={() => sendBody(chip)}
                                                className="rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-300 hover:border-sky-400/40 hover:text-white"
                                            >
                                                {chip}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            ) : (
                                <div className="mx-auto flex max-w-3xl flex-col gap-3">
                                    {messages.map((message) => (
                                        <Bubble
                                            key={message.id}
                                            message={message}
                                            time={formatWhen(message.occurred_at, timezone)}
                                            sending={sending}
                                            storagePage={capabilities.storagePage}
                                            onOpenImage={setLightbox}
                                            onConfirm={() => resolveConfirmation(message.pending_confirmation?.id, true)}
                                            onCancel={() => resolveConfirmation(message.pending_confirmation?.id, false)}
                                        />
                                    ))}
                                    {sending ? (
                                        <div className="flex items-center gap-2 text-sm text-slate-400" aria-live="polite">
                                            <Loader2 className="h-4 w-4 animate-spin" />
                                            LAVR is thinking...
                                        </div>
                                    ) : null}
                                </div>
                            )}
                        </div>

                        <div className="lavr-chat-composer border-t border-white/10 bg-black/30 px-4 py-3 sm:px-8">
                            {error ? <p className="mb-2 text-sm text-rose-300">{error}</p> : null}
                            {flash?.success ? <p className="mb-2 text-sm text-emerald-300">{flash.success}</p> : null}
                            <form
                                className="mx-auto max-w-3xl"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    sendBody(draft);
                                }}
                                onDragOver={(event) => event.preventDefault()}
                                onDrop={handleDrop}
                            >
                                {pendingFiles.length > 0 ? (
                                    <div className="mb-2 flex flex-wrap gap-2">
                                        {pendingFiles.map((item) => (
                                            item.kind === 'file' ? (
                                                <div key={item.id} className="relative flex h-16 min-w-[9rem] items-center gap-2 rounded-xl bg-white/5 px-3 ring-1 ring-white/15">
                                                    <FileText className="h-4 w-4 text-sky-300" />
                                                    <div className="min-w-0">
                                                        <p className="truncate text-xs text-white">{item.name}</p>
                                                        <p className="text-[10px] text-slate-400">{formatBytes(item.size)} · Storage</p>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        onClick={() => removePendingFile(item.id)}
                                                        className="absolute right-0.5 top-0.5 rounded-full bg-black/70 p-0.5 text-white"
                                                        aria-label="Remove attachment"
                                                        disabled={sending}
                                                    >
                                                        <X className="h-3 w-3" />
                                                    </button>
                                                </div>
                                            ) : (
                                                <div key={item.id} className="relative h-16 w-16 overflow-hidden rounded-xl ring-1 ring-white/15">
                                                    <img src={item.url} alt="" className="h-full w-full object-cover" />
                                                    <button
                                                        type="button"
                                                        onClick={() => removePendingFile(item.id)}
                                                        className="absolute right-0.5 top-0.5 rounded-full bg-black/70 p-0.5 text-white"
                                                        aria-label="Remove attachment"
                                                        disabled={sending}
                                                    >
                                                        <X className="h-3 w-3" />
                                                    </button>
                                                </div>
                                            )
                                        ))}
                                    </div>
                                ) : null}
                                <div className="flex items-end gap-2">
                                    <input
                                        ref={fileInputRef}
                                        type="file"
                                        accept={acceptTypes}
                                        multiple
                                        hidden
                                        onChange={(event) => {
                                            addPendingFiles(event.target.files);
                                            event.target.value = '';
                                        }}
                                    />
                                    <button
                                        type="button"
                                        disabled={sending || maxImages < 1}
                                        onClick={() => fileInputRef.current?.click()}
                                        className="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-slate-300 hover:text-white disabled:opacity-50"
                                        aria-label="Attach image"
                                    >
                                        <Paperclip className="h-4 w-4" />
                                    </button>
                                    <textarea
                                        ref={composerRef}
                                        value={draft}
                                        rows={1}
                                        placeholder="Сообщение, файл или Ctrl+V для скрина"
                                        onChange={(event) => setDraft(event.target.value)}
                                        onPaste={handleClipboardPaste}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter' && !event.shiftKey) {
                                                event.preventDefault();
                                                sendBody(draft);
                                            }
                                        }}
                                        className="max-h-40 min-h-[48px] flex-1 resize-none rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-slate-100 shadow-inner outline-none placeholder:text-slate-500 focus:border-sky-400/40 disabled:opacity-60"
                                    />
                                    <button
                                        type="submit"
                                        disabled={!draft.trim() && pendingFiles.length === 0}
                                        className="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-sky-500 text-white hover:bg-sky-400 disabled:opacity-50"
                                        aria-label="Send"
                                    >
                                        {sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}
            </JarvisWorkspaceLayout>

            <WorkspaceSettings
                open={settingsOpen}
                section={settingsSection}
                onSectionChange={changeSettingsSection}
                onClose={closeSettings}
                surface={surface}
                user={user}
                settings={settings}
                capabilities={capabilities}
                assistantProfile={assistantProfile}
                settingsContext={settingsContext}
                showOnboarding={showOnboarding}
                onboardingLabel={onboardingLabel}
                onboardingStatus={onboardingStatus}
            />

            <ConversationDeleteDialog
                conversation={pendingDelete}
                deleting={deletingChat}
                onCancel={() => {
                    if (!deletingChat) {
                        setPendingDelete(null);
                    }
                }}
                onConfirm={deleteConversation}
            />

            <RemindersPanel
                open={remindersOpen}
                surface={surface}
                timezone={timezone}
                refreshToken={productivityRefreshToken}
                onClose={() => setRemindersOpen(false)}
                onCountChange={setActiveReminderCount}
                onDataChange={refreshOverview}
                onCreateInChat={() => {
                    setRemindersOpen(false);
                    setMode('text');
                    setDraft((current) => (current?.trim() ? current : 'Напомни мне '));
                    requestAnimationFrame(() => focusComposer(composerRef.current, { forceDesktopOnly: false }));
                }}
            />
            <OverviewPanel
                open={overviewOpen}
                surface={surface}
                refreshToken={productivityRefreshToken + overviewRefreshToken}
                onClose={() => setOverviewOpen(false)}
            />
            <TasksPanel
                open={tasksOpen}
                surface={surface}
                refreshToken={productivityRefreshToken}
                onClose={() => setTasksOpen(false)}
                onCountChange={setActiveTaskCount}
                onDataChange={refreshOverview}
                onCreateInChat={() => {
                    setTasksOpen(false);
                    setMode('text');
                    setDraft((current) => (current?.trim() ? current : 'Создай задачу '));
                    requestAnimationFrame(() => focusComposer(composerRef.current, { forceDesktopOnly: false }));
                }}
            />
            <WatchersPanel
                open={watchersOpen}
                surface={surface}
                refreshToken={productivityRefreshToken}
                onClose={() => setWatchersOpen(false)}
                onCountChange={setActiveWatcherCount}
                onDataChange={refreshOverview}
                onCreateInChat={() => {
                    setWatchersOpen(false);
                    setMode('text');
                    setDraft((current) => (current?.trim() ? current : 'Следи и сообщи, когда '));
                    requestAnimationFrame(() => focusComposer(composerRef.current, { forceDesktopOnly: false }));
                }}
            />
            <ReportsPanel
                open={reportsOpen}
                surface={surface}
                refreshToken={productivityRefreshToken}
                onClose={() => setReportsOpen(false)}
                onCountChange={setActiveReportCount}
                onDataChange={refreshOverview}
                onCreateInChat={() => {
                    setReportsOpen(false);
                    setMode('text');
                    setDraft((current) => (current?.trim() ? current : 'Каждый день присылай отчёт '));
                    requestAnimationFrame(() => focusComposer(composerRef.current, { forceDesktopOnly: false }));
                }}
            />
            <NotificationsPanel
                open={notificationsOpen}
                surface={surface}
                refreshToken={productivityRefreshToken}
                onClose={() => setNotificationsOpen(false)}
                onCountChange={setUnreadNotificationCount}
            />

            {lightbox ? (
                <div
                    className="fixed inset-0 z-[60] flex items-center justify-center bg-black/80 p-4"
                    onClick={() => {
                        setLightbox(null);
                        focusComposer(composerRef.current, { forceDesktopOnly: true });
                    }}
                >
                    <img
                        src={lightbox.view_url || lightbox.preview_url}
                        alt=""
                        className="max-h-full max-w-full rounded-xl object-contain shadow-2xl"
                        onClick={(event) => event.stopPropagation()}
                    />
                    <button
                        type="button"
                        onClick={() => {
                            setLightbox(null);
                            focusComposer(composerRef.current, { forceDesktopOnly: true });
                        }}
                        className="absolute right-4 top-4 rounded-lg bg-black/60 p-2 text-white"
                        aria-label="Close image"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </div>
            ) : null}
        </div>
        </LavrAppShell>
    );
}

function Bubble({ message, time, sending = false, onConfirm, onCancel, onOpenImage, retentionHours = 24, storagePage = false }) {
    if (message.kind === 'error' || message.status === 'failed') {
        return (
            <div className="mx-auto max-w-xl rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-center text-sm text-rose-200">
                <p>Не удалось отправить. Текст сохранён — нажмите Send ещё раз.</p>
                {message.body ? <p className="mt-1 text-xs text-rose-200/80">{message.body}</p> : null}
            </div>
        );
    }

    const mine = message.kind === 'user';
    const pending = message.pending_confirmation;
    const streaming = message.status === 'streaming';

    return (
        <div className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
            <div
                className={`max-w-[92%] rounded-2xl px-4 py-2 sm:max-w-[78%] ${
                    mine
                        ? 'bg-sky-500/20 text-sky-50 ring-1 ring-sky-400/20'
                        : 'bg-white/5 text-slate-100 ring-1 ring-white/10'
                } ${message.pending || message.status === 'pending' ? 'opacity-70' : ''}`}
            >
                {mine ? (
                    message.body ? (
                        <p className="whitespace-pre-wrap break-words text-sm leading-6">{message.body}</p>
                    ) : null
                ) : (
                    <SafeMarkdown text={message.body} />
                )}
                {Array.isArray(message.attachments) && message.attachments.length > 0 ? (
                    <div className={`mt-2 flex flex-wrap gap-2 ${mine ? 'justify-end' : 'justify-start'}`}>
                        {message.attachments.map((attachment) => (
                            attachment.purged ? (
                                <ExpiredScreenshotCard key={attachment.id} attachment={attachment} />
                            ) : (
                                <button
                                    key={attachment.id}
                                    type="button"
                                    onClick={() => onOpenImage?.(attachment)}
                                    className="overflow-hidden rounded-xl ring-1 ring-white/15"
                                    aria-label="Open image"
                                >
                                    <img
                                        src={attachment.preview_url || attachment.view_url}
                                        alt=""
                                        className="h-20 w-20 object-cover"
                                    />
                                    <p className="max-w-[10rem] px-2 py-1 text-[10px] text-slate-400">
                                        Temporary image · expires in ~{retentionHours}h
                                    </p>
                                </button>
                            )
                        ))}
                    </div>
                ) : null}
                {Array.isArray(message.stored_files) && message.stored_files.length > 0 ? (
                    <div className={`mt-2 flex flex-wrap gap-2 ${mine ? 'justify-end' : 'justify-start'}`}>
                        {message.stored_files.map((file) => {
                            const body = (
                                <>
                                    <FileText className="h-4 w-4 text-emerald-300" />
                                    <div className="min-w-0">
                                        <p className="truncate text-xs text-white">{file.display_name}</p>
                                        <p className="text-[10px] text-emerald-200/80">Saved file · {formatBytes(file.size_bytes)}</p>
                                    </div>
                                </>
                            );

                            if (storagePage) {
                                return (
                                    <Link
                                        key={file.public_id}
                                        href={route('jarvis.storage.show', file.public_id)}
                                        className="flex min-w-[10rem] items-center gap-2 rounded-xl bg-black/20 px-3 py-2 ring-1 ring-emerald-400/20"
                                    >
                                        {body}
                                    </Link>
                                );
                            }

                            return (
                                <div
                                    key={file.public_id}
                                    className="flex min-w-[10rem] items-center gap-2 rounded-xl bg-black/20 px-3 py-2 ring-1 ring-emerald-400/20"
                                >
                                    {body}
                                </div>
                            );
                        })}
                    </div>
                ) : null}
                {streaming ? <p className="mt-1 text-[11px] text-slate-500">streaming…</p> : null}
                {pending?.id && !mine ? <ConfirmationCard pending={pending} sending={sending} onConfirm={onConfirm} onCancel={onCancel} /> : null}
                <p className={`mt-1 text-[11px] ${mine ? 'text-sky-200/70' : 'text-slate-500'}`}>{time}</p>
            </div>
        </div>
    );
}

function ExpiredScreenshotCard({ attachment }) {
    const [open, setOpen] = useState(false);
    const summary = String(attachment.summary_text || '').trim();

    return (
        <div className="max-w-[16rem] rounded-xl bg-black/30 px-3 py-2 ring-1 ring-white/10">
            <p className="text-xs font-medium text-slate-200">Screenshot expired</p>
            {summary ? (
                <>
                    <p className={`mt-1 text-[11px] text-slate-400 ${open ? '' : 'line-clamp-3'}`}>{summary}</p>
                    {summary.length > 120 ? (
                        <button type="button" onClick={() => setOpen((value) => !value)} className="mt-1 text-[11px] text-sky-300">
                            {open ? 'Collapse' : 'Expand'}
                        </button>
                    ) : null}
                </>
            ) : (
                <p className="mt-1 text-[11px] text-slate-500">Screenshot expired; visual summary unavailable.</p>
            )}
        </div>
    );
}

function ConfirmationCard({ pending, sending, onConfirm, onCancel }) {
    const preview = pending.preview || {};
    const actionable = isActionableConfirmation(pending);
    const resolvedCopy = resolvedConfirmationCopy(pending);

    return (
        <div className={`mt-3 rounded-xl border p-3 ${
            actionable
                ? 'border-amber-400/20 bg-amber-400/5'
                : 'border-white/10 bg-white/5'
        }`}
        >
            <p className="text-[11px] uppercase tracking-[0.14em] text-amber-200/80">{providerLabel(pending.tool_name)}</p>
            {pending.summary ? <p className="mt-1 text-sm text-slate-200">{pending.summary}</p> : null}
            {preview.to?.length ? <p className="mt-2 text-xs text-slate-400">To: {preview.to.join(', ')}</p> : null}
            {preview.cc?.length ? <p className="text-xs text-slate-400">Cc: {preview.cc.join(', ')}</p> : null}
            {preview.subject ? <p className="text-xs text-slate-400">Subject: {preview.subject}</p> : null}
            {preview.body_preview ? (
                <p className="mt-1 whitespace-pre-wrap text-xs text-slate-300">{preview.body_preview}</p>
            ) : null}
            {Object.entries(preview)
                .filter(([key]) => !['to', 'cc', 'subject', 'body_preview'].includes(key))
                .map(([key, value]) => (
                    <p key={key} className="text-xs text-slate-400">
                        {key.replaceAll('_', ' ')}: {String(value)}
                    </p>
                ))}
            {actionable && pending.expires_at ? (
                <p className="mt-2 text-[11px] text-slate-500">Expires {formatWhen(pending.expires_at)}</p>
            ) : null}
            {resolvedCopy ? (
                <p className="mt-2 text-xs font-medium text-slate-300">{resolvedCopy}</p>
            ) : null}
            {actionable ? (
                <div className="mt-3 flex gap-2">
                    <button
                        type="button"
                        disabled={sending}
                        onClick={onConfirm}
                        className="inline-flex items-center gap-1 rounded-lg bg-emerald-500/90 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-400 disabled:opacity-60"
                    >
                        <Check className="h-3.5 w-3.5" />
                        Confirm
                    </button>
                    <button
                        type="button"
                        disabled={sending}
                        onClick={onCancel}
                        className="rounded-lg border border-white/15 px-3 py-1.5 text-xs font-semibold text-slate-200 hover:bg-white/5 disabled:opacity-60"
                    >
                        Cancel
                    </button>
                </div>
            ) : null}
        </div>
    );
}
