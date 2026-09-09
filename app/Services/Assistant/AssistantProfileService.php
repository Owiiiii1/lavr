<?php

namespace App\Services\Assistant;

use App\Enums\OnboardingStatus;
use App\Enums\OnboardingStep;
use App\Enums\OwnerLocale;
use App\Models\Conversation;
use App\Models\User;
use App\Models\UserAssistantProfile;
use App\Services\Conversations\ConversationService;
use InvalidArgumentException;

final class AssistantProfileService
{
    public const ONBOARDING_TITLE = 'Знакомство';

    public const OWNER_DEFAULT_NAME = 'LAVR';

    public const USER_FALLBACK_NAME = 'Assistant';

    public const NAME_MAX = 80;

    public const TEXT_MAX = 2000;

    public const ABOUT_MAX = 4000;

    public function __construct(
        private readonly ConversationService $conversations,
    ) {}

    public function profileFor(User $user, bool $persist = false): UserAssistantProfile
    {
        $existing = UserAssistantProfile::query()->where('user_id', $user->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $profile = new UserAssistantProfile($this->defaultsFor($user));
        $profile->user_id = $user->id;

        if ($persist || $user->isOwner()) {
            $profile->save();
        }

        return $profile;
    }

    public function presentationName(User $user): string
    {
        $name = trim((string) ($this->profileFor($user)->assistant_name ?? ''));

        return $name !== '' ? $name : self::OWNER_DEFAULT_NAME;
    }

    /**
     * @return array<string, mixed>
     */
    public function workspacePayload(User $user): array
    {
        $profile = $this->profileFor($user);

        return $this->toArray($profile, $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(UserAssistantProfile $profile, ?User $user = null): array
    {
        $user ??= $profile->user ?? User::query()->find($profile->user_id);
        $owner = $user?->isOwner() ?? false;
        $name = trim((string) ($profile->assistant_name ?? ''));

        return [
            'assistant_name' => $name !== '' ? $name : null,
            'personality' => $this->nullableTrim($profile->personality),
            'interaction_style' => $this->nullableTrim($profile->interaction_style),
            'about_user' => $this->nullableTrim($profile->about_user),
            'interface_locale' => OwnerLocale::fromMixed($profile->interface_locale)->value,
            'assistant_locale' => OwnerLocale::fromMixed($profile->assistant_locale)->value,
            'onboarding_status' => ($profile->onboarding_status ?? OnboardingStatus::NotStarted)->value,
            'onboarding_step' => $profile->onboarding_step?->value,
            'onboarding_conversation_id' => $profile->onboarding_conversation_id !== null
                ? (int) $profile->onboarding_conversation_id
                : null,
            'onboarding_started_at' => optional($profile->onboarding_started_at)?->toIso8601String(),
            'onboarding_completed_at' => optional($profile->onboarding_completed_at)?->toIso8601String(),
            'presentation_name' => $owner
                ? self::OWNER_DEFAULT_NAME
                : ($name !== '' ? $name : self::USER_FALLBACK_NAME),
            'show_onboarding' => ! $owner,
        ];
    }

    public function identityContext(User $user): string
    {
        $profile = $this->profileFor($user);
        $payload = $this->toArray($profile, $user);
        $name = $payload['assistant_name'] ?? ($user->isOwner() ? self::OWNER_DEFAULT_NAME : 'not chosen yet');

        $assistantLocale = OwnerLocale::fromMixed($payload['assistant_locale'] ?? null);

        $lines = [
            'Assistant identity (structured; not General Prompt, not Memory):',
            'Name: '.$name,
            'Onboarding status: '.$payload['onboarding_status'],
        ];

        if (is_string($payload['personality']) && $payload['personality'] !== '') {
            $lines[] = 'Personality: '.$payload['personality'];
        }

        if (is_string($payload['interaction_style']) && $payload['interaction_style'] !== '') {
            $lines[] = 'Interaction style: '.$payload['interaction_style'];
        }

        if (is_string($payload['about_user']) && $payload['about_user'] !== '') {
            $lines[] = 'User onboarding summary: '.$payload['about_user'];
        }

        $lines[] = 'Identify yourself using this name. Telegram bot username is infrastructure and must not be treated as the assistant name.';
        $lines[] = 'User General Prompt may refine behavior but must not silently replace this name.';
        $lines[] = 'about_user is a compact onboarding summary. Long-term facts belong in Memory; do not dump the onboarding transcript into every turn.';

        if ($payload['onboarding_status'] !== OnboardingStatus::Completed->value && ! $user->isOwner()) {
            $lines[] = 'Onboarding is optional. Help with ordinary requests even if it is not finished. Do not block chat.';
        }

        $lines[] = 'Preferred assistant response language: '.$assistantLocale->englishName().' ('.$assistantLocale->value.').';
        $lines[] = 'Reply in this language by default.';
        $lines[] = 'If the current user message is clearly written in English or Russian, or the user explicitly asks for a language this turn (for example "summarize in English", "відповідай англійською"), reply in that language for this turn only.';
        $lines[] = 'Do not change the stored preferred assistant language unless the user explicitly asks to change the language setting permanently. Permanent language changes happen in Settings.';
        $lines[] = 'Do not translate source artifacts (emails, Telegram messages, transcripts, documents, original quotes) when storing or quoting them. Translate only on explicit request, as presentation.';
        $lines[] = 'Keep names of people, organizations, projects, files, and original quotes unchanged.';

        return implode("\n", $lines);
    }

    public function onboardingEventFor(User $user, Conversation $conversation): ?string
    {
        if ($user->isOwner()) {
            return null;
        }

        $profile = $this->profileFor($user);

        if ($profile->onboarding_status !== OnboardingStatus::InProgress) {
            return null;
        }

        if ((int) $profile->onboarding_conversation_id !== (int) $conversation->id) {
            return null;
        }

        return implode("\n", [
            'Onboarding mode for this conversation only.',
            'Collect preferences naturally. Do not use a rigid questionnaire.',
            'Cover, in a comfortable order:',
            '1) Assistant name — how the user wants to call you.',
            '2) Character/personality (formal or informal, calm or direct, humorous or serious, concise or detailed, proactive or reserved).',
            '3) Interaction style (clarify vs assume, how proactive, how concise, preferred communication, languages if mentioned).',
            '4) About the user (preferred address if they want, work/interests, current goals, preferences, anything the assistant should know).',
            'When the user clearly states a preference, call update_assistant_profile with only those fields. Never invent or overwrite unspecified fields. Never pass user_id.',
            'Do not call complete_assistant_onboarding until assistant_name, personality, interaction_style, and about_user are all present.',
            'Then briefly summarize the chosen name, personality, interaction approach, and what you understood about the user. Confirm if needed, then complete.',
            'Ordinary help is still allowed in this chat.',
        ]);
    }

    public function isOnboardingConversation(User $user, Conversation $conversation): bool
    {
        $profile = $this->profileFor($user);

        return $profile->onboarding_status === OnboardingStatus::InProgress
            && $profile->onboarding_conversation_id !== null
            && (int) $profile->onboarding_conversation_id === (int) $conversation->id;
    }

    public function startOnboarding(User $user): Conversation
    {
        if ($user->isOwner()) {
            throw new InvalidArgumentException('Owner identity is already established.');
        }

        $profile = $this->profileFor($user, persist: true);

        if ($profile->onboarding_status === OnboardingStatus::Completed) {
            throw new InvalidArgumentException('Onboarding already completed.');
        }

        $conversation = $this->ownedOnboardingConversation($user, $profile);

        if ($conversation === null) {
            $conversation = $this->conversations->createPersonal($user, self::ONBOARDING_TITLE);
        } elseif ($conversation->title !== self::ONBOARDING_TITLE) {
            $this->conversations->rename($user, $conversation, self::ONBOARDING_TITLE);
        }

        $now = now();
        $profile->forceFill([
            'onboarding_status' => OnboardingStatus::InProgress,
            'onboarding_step' => $this->nextStep($profile) ?? OnboardingStep::AssistantName,
            'onboarding_conversation_id' => $conversation->id,
            'onboarding_started_at' => $profile->onboarding_started_at ?? $now,
            'onboarding_completed_at' => null,
        ])->save();

        return $conversation->fresh() ?? $conversation;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function updateFields(User $user, array $fields): UserAssistantProfile
    {
        $profile = $this->profileFor($user, persist: true);
        $updates = [];

        if (array_key_exists('assistant_name', $fields)) {
            $updates['assistant_name'] = $this->normalizeName($fields['assistant_name']);
        }

        if (array_key_exists('personality', $fields)) {
            $updates['personality'] = $this->normalizeText($fields['personality'], self::TEXT_MAX);
        }

        if (array_key_exists('interaction_style', $fields)) {
            $updates['interaction_style'] = $this->normalizeText($fields['interaction_style'], self::TEXT_MAX);
        }

        if (array_key_exists('about_user', $fields)) {
            $updates['about_user'] = $this->normalizeText($fields['about_user'], self::ABOUT_MAX);
        }

        if ($updates === []) {
            return $profile;
        }

        if ($profile->onboarding_status === OnboardingStatus::NotStarted && ! $user->isOwner()) {
            $updates['onboarding_status'] = OnboardingStatus::InProgress;
            $updates['onboarding_started_at'] = $profile->onboarding_started_at ?? now();
        }

        $profile->forceFill($updates);
        $profile->onboarding_step = $this->nextStep($profile);
        $profile->save();

        return $profile->fresh() ?? $profile;
    }

    public function updateLocales(User $user, mixed $interfaceLocale, mixed $assistantLocale): UserAssistantProfile
    {
        $profile = $this->profileFor($user, persist: true);
        $profile->forceFill([
            'interface_locale' => OwnerLocale::fromMixed($interfaceLocale)->value,
            'assistant_locale' => OwnerLocale::fromMixed($assistantLocale)->value,
        ])->save();

        return $profile->fresh() ?? $profile;
    }

    public function completeOnboarding(User $user): UserAssistantProfile
    {
        $profile = $this->profileFor($user, persist: true);

        if ($profile->onboarding_status === OnboardingStatus::Completed && $this->missingRequired($profile) === []) {
            return $profile;
        }

        $missing = $this->missingRequired($profile);

        if ($missing !== []) {
            throw new AssistantProfileException('incomplete', 'Onboarding is incomplete.', $missing);
        }

        $profile->forceFill([
            'onboarding_status' => OnboardingStatus::Completed,
            'onboarding_step' => null,
            'onboarding_completed_at' => now(),
        ])->save();

        return $profile->fresh() ?? $profile;
    }

    /**
     * @return list<string>
     */
    public function missingRequired(UserAssistantProfile $profile): array
    {
        $missing = [];

        foreach (['assistant_name', 'personality', 'interaction_style', 'about_user'] as $field) {
            if ($this->nullableTrim($profile->{$field}) === null) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function ownedOnboardingConversation(User $user, UserAssistantProfile $profile): ?Conversation
    {
        if ($profile->onboarding_conversation_id === null) {
            return Conversation::query()
                ->where('user_id', $user->id)
                ->where('title', self::ONBOARDING_TITLE)
                ->orderByDesc('id')
                ->first();
        }

        return $this->conversations->findOwned($user, (int) $profile->onboarding_conversation_id);
    }

    private function nextStep(UserAssistantProfile $profile): ?OnboardingStep
    {
        if ($this->nullableTrim($profile->assistant_name) === null) {
            return OnboardingStep::AssistantName;
        }

        if ($this->nullableTrim($profile->personality) === null) {
            return OnboardingStep::Personality;
        }

        if ($this->nullableTrim($profile->interaction_style) === null) {
            return OnboardingStep::InteractionStyle;
        }

        if ($this->nullableTrim($profile->about_user) === null) {
            return OnboardingStep::AboutUser;
        }

        return OnboardingStep::Summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultsFor(User $user): array
    {
        if ($user->isOwner()) {
            return [
                'assistant_name' => self::OWNER_DEFAULT_NAME,
                'personality' => null,
                'interaction_style' => null,
                'about_user' => null,
                'onboarding_status' => OnboardingStatus::Completed,
                'onboarding_step' => null,
                'onboarding_conversation_id' => null,
                'onboarding_started_at' => null,
                'onboarding_completed_at' => now(),
            ];
        }

        return [
            'assistant_name' => null,
            'personality' => null,
            'interaction_style' => null,
            'about_user' => null,
            'onboarding_status' => OnboardingStatus::NotStarted,
            'onboarding_step' => null,
            'onboarding_conversation_id' => null,
            'onboarding_started_at' => null,
            'onboarding_completed_at' => null,
        ];
    }

    private function normalizeName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, self::NAME_MAX);
    }

    private function normalizeText(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, $max);
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
