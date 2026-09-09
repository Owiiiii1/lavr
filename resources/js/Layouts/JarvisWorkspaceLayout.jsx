import { Head } from '@inertiajs/react';

export default function JarvisWorkspaceLayout({ title, header, sidebar, context, children, sidebarOpen, contextCollapsed, contextDrawer }) {
    return (
        <div className="jarvis-workspace h-screen text-slate-100">
            <Head title={title} />
            <div className="flex h-full min-h-0 flex-col">
                {header}
                <div className="relative flex min-h-0 flex-1">
                    <aside
                        className={`hidden min-h-0 w-[min(20rem,32vw)] shrink-0 border-r border-white/10 bg-black/20 lg:flex lg:flex-col ${
                            sidebarOpen ? '' : ''
                        }`}
                    >
                        {sidebar}
                    </aside>

                    {sidebarOpen ? (
                        <div className="absolute inset-0 z-30 flex lg:hidden">
                            <div className="flex h-full w-[min(20rem,86vw)] flex-col border-r border-white/10 bg-[#0b1220] shadow-2xl">
                                {sidebar}
                            </div>
                            <div className="min-h-0 flex-1 bg-black/50" data-sidebar-backdrop="true" />
                        </div>
                    ) : null}

                    <main className="flex min-w-0 min-h-0 flex-1 flex-col">{children}</main>

                    {context ? (
                        <aside
                            className={`hidden min-h-0 w-[min(22rem,28vw)] shrink-0 border-l border-white/10 bg-black/15 xl:flex xl:flex-col ${
                                contextCollapsed ? 'xl:hidden' : ''
                            }`}
                        >
                            {context}
                        </aside>
                    ) : null}

                    {context && contextDrawer ? (
                        <div className="absolute inset-0 z-30 flex justify-end xl:hidden">
                            <div className="min-h-0 flex-1 bg-black/50" data-context-backdrop="true" />
                            <div className="flex h-full w-[min(22rem,90vw)] flex-col border-l border-white/10 bg-[#0b1220] shadow-2xl">
                                {context}
                            </div>
                        </div>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
