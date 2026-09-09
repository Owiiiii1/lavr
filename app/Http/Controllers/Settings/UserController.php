<?php

namespace App\Http\Controllers\Settings;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Telegram\Pairing\TelegramPairingService;
use App\Services\Users\ImpersonationService;
use App\Services\Users\UserAdministrationService;
use App\Services\Users\UserCapability;
use App\Support\Timezones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * @deprecated LAVR is a single-client instance. Product-level user administration
 * routes were removed in Phase 1. This class is kept only as unused legacy.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly UserAdministrationService $users,
        private readonly ImpersonationService $impersonation,
        private readonly TelegramPairingService $pairing,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $this->assertUsersAdmin($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'timezone' => ['required', 'timezone:all'],
        ]);

        if (User::query()->where('role', UserRole::Owner)->exists() === false) {
            throw ValidationException::withMessages([
                'email' => 'Cannot create users before an owner exists.',
            ]);
        }

        $user = $this->users->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'timezone' => $validated['timezone'],
        ]);

        return redirect()
            ->route('settings.users.show', $user)
            ->with('success', 'User created. The password cannot be recovered later.');
    }

    public function show(Request $request, User $user): Response
    {
        $this->assertUsersAdmin($request);

        return Inertia::render('Settings/UserCard', [
            'managedUser' => $this->users->card($user),
            'timezones' => Timezones::options($user->timezone),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->assertUsersAdmin($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'timezone' => ['required', 'timezone:all'],
        ]);

        $this->users->updateProfile($user, $validated);

        return back()->with('success', 'Profile saved.');
    }

    public function setStatus(Request $request, User $user): RedirectResponse
    {
        $this->assertUsersAdmin($request);

        if ($user->isOwner()) {
            return back()->withErrors([
                'status' => 'The owner account cannot be disabled.',
            ]);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::enum(UserStatus::class)],
        ]);

        $status = $validated['status'] instanceof UserStatus
            ? $validated['status']
            : UserStatus::from((string) $validated['status']);

        $this->users->setStatus($user, $status);

        return back()->with('success', $status === UserStatus::Disabled
            ? 'User disabled.'
            : 'User enabled.');
    }

    public function setPassword(Request $request, User $user): RedirectResponse
    {
        $this->assertUsersAdmin($request);

        if ($user->isOwner()) {
            return back()->withErrors([
                'password' => 'The owner password cannot be reset from user management.',
            ]);
        }

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $this->users->setPassword($user, $validated['password']);

        return back()->with('success', 'Password updated. Existing sessions for this user were signed out.');
    }

    public function updateGeneralPrompt(Request $request, User $user): RedirectResponse
    {
        $this->assertUsersAdmin($request);

        $validated = $request->validate([
            'general_prompt' => ['nullable', 'string', 'max:10000'],
        ]);

        $this->users->updateGeneralPrompt($user, $validated['general_prompt'] ?? null);

        return back()->with('success', 'General Prompt saved.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->assertUsersAdmin($request);

        return back()->withErrors([
            'user_delete' => 'Hard delete is not available. Disable the account instead.',
        ]);
    }

    public function unlinkTelegram(Request $request, User $user): RedirectResponse
    {
        $this->assertUsersAdmin($request);

        $this->pairing->unlinkTelegram($user);

        return back()->with('success', 'Telegram identity unlinked. Chats and history were kept.');
    }

    public function regenerateAccessCode(Request $request, User $user): RedirectResponse
    {
        $this->assertUsersAdmin($request);

        if ($user->isOwner()) {
            return back()->withErrors([
                'access_code' => 'The owner access code cannot be regenerated.',
            ]);
        }

        $this->users->regenerateAccessCode($user);

        return back()->with('success', 'Telegram access code regenerated. The current Telegram link was not removed.');
    }

    public function impersonate(Request $request, User $user): RedirectResponse
    {
        $this->assertUsersAdmin($request);

        $this->impersonation->start($request, $request->user(), $user);

        return redirect()->route('chat.index');
    }

    private function assertUsersAdmin(Request $request): void
    {
        $actor = $request->user();

        if ($actor === null || ! $actor->canUseCapability(UserCapability::USERS_ADMIN) || ! $actor->isOwner()) {
            abort(403);
        }
    }
}
