import { usePage } from '@inertiajs/react';

export default function AuthLayout({ children }) {
    const { owlAdmin = {}, locale = 'en' } = usePage().props;
    const brandName = owlAdmin.brand_name ?? 'LAVR';
    const logoPath = owlAdmin.logo_path ?? '/images/company-logo.svg';

    const copy = {
        en: {
            headline: 'Quiet control for the whole operation.',
            sub: 'Settings, Telegram, and AI — one workspace that stays out of the way until you need it.',
        },
        ru: {
            headline: 'Спокойное управление всей операцией.',
            sub: 'Настройки, Telegram и ИИ — одно рабочее пространство, которое не мешает, пока оно не нужно.',
        },
        uk: {
            headline: 'Спокійне керування всією операцією.',
            sub: 'Налаштування, Telegram і ШІ — один робочий простір, який не заважає, поки він не потрібен.',
        },
    };
    const t = copy[locale] ?? copy.en;

    return (
        <main className="min-h-screen bg-[#F4EFE4] text-slate-900">
            <div className="grid min-h-screen lg:grid-cols-2">
                <section className="relative hidden overflow-hidden bg-[#0B1220] p-10 text-white lg:flex lg:flex-col lg:justify-between">
                    <div
                        className="absolute inset-0 bg-cover bg-center"
                        style={{ backgroundImage: "url('/images/auth-abstract-bg.svg')" }}
                    />
                    <div className="absolute inset-0 bg-gradient-to-br from-[#0B1220]/50 via-[#0B1220]/35 to-[#0B1220]/80" />

                    <div className="relative z-10 flex items-center gap-3">
                        <img src={logoPath} alt={brandName} className="h-11 w-11 rounded-2xl shadow-lg shadow-black/30" />
                        <div>
                            <p className="text-2xl font-semibold tracking-tight">{brandName}</p>
                            <p className="text-[11px] uppercase tracking-[0.22em] text-amber-200/80">OwlSolutions</p>
                        </div>
                    </div>

                    <div className="relative z-10 max-w-lg space-y-5">
                        <p className="text-xs font-semibold uppercase tracking-[0.28em] text-amber-300/90">
                            Admin workspace
                        </p>
                        <h1 className="text-5xl font-semibold leading-[1.1] text-white">
                            {t.headline}
                        </h1>
                        <p className="max-w-md text-base leading-relaxed text-slate-300">
                            {t.sub}
                        </p>
                    </div>

                    <p className="relative z-10 text-xs uppercase tracking-[0.22em] text-slate-500">
                        {brandName}
                    </p>
                </section>

                <section className="relative flex items-center justify-center px-5 py-10 sm:p-12">
                    <div className="w-full max-w-md">
                        <div className="mb-6 flex items-center gap-3 lg:hidden">
                            <img src={logoPath} alt={brandName} className="h-10 w-10 rounded-2xl" />
                            <div>
                                <p className="text-lg font-semibold text-slate-900">{brandName}</p>
                                <p className="text-[11px] uppercase tracking-[0.2em] text-amber-700/80">OwlSolutions</p>
                            </div>
                        </div>
                        <div className="rounded-2xl border border-[#E6DCC8] bg-white/90 p-8 shadow-[0_20px_50px_-28px_rgba(28,25,23,0.35)] backdrop-blur">
                            {children}
                        </div>
                    </div>
                </section>
            </div>
        </main>
    );
}
