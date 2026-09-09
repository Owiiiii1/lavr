<?php

namespace App\Http\Middleware;

use App\Enums\AiRoleKey;
use App\Models\AiProviderSetting;
use App\Models\AiRoleSetting;
use App\Models\TelegramBotSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            'flash' => [
                'analysis' => $request->session()->get('analysis'),
                'success' => $request->session()->get('success'),
                'warning' => $request->session()->get('warning'),
                'error' => $request->session()->get('error'),
            ],

            'owlAdmin' => fn () => [
                ...config('owl-admin.branding', [
                    'brand_name' => config('owl-admin.brand_name', config('owl-admin.name', 'Service Admin')),
                    'logo_path' => config('owl-admin.logo_path', '/images/company-logo.svg'),
                ]),
                'ai' => (function (): array {
                    $fallback = [
                        'connected' => false,
                        'provider' => null,
                        'provider_label' => null,
                        'model' => null,
                        'status_label' => 'AI: not connected',
                    ];

                    try {
                        if (! class_exists(AiProviderSetting::class)) {
                            return $fallback;
                        }

                        if (! Schema::hasTable('ai_role_settings')) {
                            return $fallback;
                        }

                        $role = AiRoleSetting::query()
                            ->where('role_key', AiRoleKey::OwnerConversation->value)
                            ->where('is_enabled', true)
                            ->first();

                        if ($role === null || ! filled($role->provider) || ! filled($role->model)) {
                            return $fallback;
                        }

                        $credential = AiProviderSetting::query()
                            ->where('provider', $role->provider)
                            ->where('is_connected', true)
                            ->first();

                        if ($credential === null) {
                            return $fallback;
                        }

                        $providerLabel = $credential->label ?: ucfirst((string) $role->provider);

                        return [
                            'connected' => true,
                            'provider' => $role->provider,
                            'provider_label' => $providerLabel,
                            'model' => $role->model,
                            'status_label' => sprintf(
                                'AI: %s — %s / %s',
                                'Owner Conversation',
                                $providerLabel,
                                $role->model
                            ),
                        ];
                    } catch (\Throwable) {
                        return $fallback;
                    }
                })(),
                'telegram' => (function (): array {
                    $fallback = [
                        'configured' => false,
                        'connected' => false,
                        'webhook_set' => false,
                        'bot_username' => null,
                        'status' => 'not_connected',
                        'status_label' => 'Bot: not connected',
                    ];

                    try {
                        if (! class_exists(TelegramBotSetting::class)) {
                            return $fallback;
                        }

                        if (! Schema::hasTable('telegram_bot_settings')) {
                            return $fallback;
                        }

                        $setting = TelegramBotSetting::query()->first();
                        if ($setting === null || ! filled($setting->bot_token)) {
                            return $fallback;
                        }

                        $username = $setting->bot_username;
                        $connected = (bool) $setting->is_connected;
                        $webhookSet = (bool) $setting->is_webhook_set;

                        if ($connected && $webhookSet && filled($username)) {
                            return [
                                'configured' => true,
                                'connected' => true,
                                'webhook_set' => true,
                                'bot_username' => $username,
                                'status' => 'connected',
                                'status_label' => sprintf('Bot: connected — @%s', $username),
                            ];
                        }

                        return [
                            'configured' => true,
                            'connected' => $connected,
                            'webhook_set' => $webhookSet,
                            'bot_username' => $username,
                            'status' => 'incomplete',
                            'status_label' => 'Bot: incomplete',
                        ];
                    } catch (\Throwable) {
                        return $fallback;
                    }
                })(),
            ],
        ];
    }
}
