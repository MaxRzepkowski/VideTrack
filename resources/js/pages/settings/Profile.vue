<script setup lang="ts">
import MetaLine from '@/components/MetaLine.vue';
import { getInitials } from '@/lib/format';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import SettingsSaveBar from '@/components/settings/SettingsSaveBar.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { ToggleSwitch } from '@/components/ui/toggle-switch';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { signedInUser } from '@/lib/pageContext';
import { SETTINGS_CARD_CLASS, SETTINGS_FIELD_CLASS } from '@/lib/ui';
import { type SharedData } from '@/types';
import { trans } from 'laravel-vue-i18n';
import { toast } from 'vue-sonner';

defineProps<{
    /** The UI languages, each under its own name. */
    languages: { value: string; label: string }[];
}>();

const page = usePage<SharedData>();

// Computed, not a snapshot — the avatar box must react to the fresh
// auth.user shared prop after an upload round-trip.
const user = computed(() => signedInUser());

// The avatar is staged in the same form (with a local preview) and only
// persisted together with the rest on "Save". File uploads cannot travel
// in a real PATCH request, hence POST + method spoofing.
const form = useForm({
    _method: 'patch',
    name: user.value.name,
    username: user.value.username,
    email: user.value.email,
    bio: user.value.bio ?? '',
    is_private: user.value.is_private,
    locale: user.value.locale ?? page.props.locale,
    avatar: null as File | null,
    remove_avatar: false as boolean,
});

const avatarInput = ref<HTMLInputElement | null>(null);
const stagedPreview = ref<string | null>(null);

function dropStagedPreview(): void {
    if (stagedPreview.value) {
        URL.revokeObjectURL(stagedPreview.value);
        stagedPreview.value = null;
    }
}

onBeforeUnmount(dropStagedPreview);

/** What the avatar box shows right now: staged file > current > initials. */
const shownAvatar = computed(() => stagedPreview.value ?? (form.remove_avatar ? null : user.value.avatar));

function stageAvatar(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) return;

    dropStagedPreview();
    form.avatar = file;
    form.remove_avatar = false;
    stagedPreview.value = URL.createObjectURL(file);
}

function removeAvatar(): void {
    dropStagedPreview();
    form.avatar = null;
    form.remove_avatar = true;

    if (avatarInput.value) avatarInput.value.value = '';
}

useUnsavedChanges(() => form.isDirty);

function clearAvatarPick(): void {
    dropStagedPreview();

    if (avatarInput.value) avatarInput.value.value = '';
}

function discard(): void {
    clearAvatarPick();
    form.reset();
    form.clearErrors();
}

function submit(): void {
    if (!form.isDirty || form.processing) {
        return;
    }

    form.post(route('settings.profile.update'), {
        preserveScroll: true,
        onSuccess: () => {
            clearAvatarPick();
            form.avatar = null;
            form.remove_avatar = false;
            toast.success(trans('settings.saved_2'));
        },
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="trans('settings.profile_settings')" />

        <SettingsLayout>
            <form class="space-y-6" @submit.prevent="submit">
                <fieldset :disabled="form.processing" class="min-w-0 space-y-6">
                    <HeadingSmall
                        :title="trans('settings.profile_information')"
                        :description="trans('settings.update_your_profile_and_account_details')"
                    />

                    <div :class="[SETTINGS_CARD_CLASS, 'flex flex-wrap items-center gap-4']">
                        <div
                            class="from-brand to-brand-strong text-on-accent flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gradient-to-br text-xl font-extrabold"
                        >
                            <img
                                v-if="shownAvatar"
                                :src="shownAvatar"
                                :alt="user.name"
                                width="64"
                                height="64"
                                loading="lazy"
                                class="h-full w-full object-cover"
                            />
                            <span v-else>{{ getInitials(user.name) }}</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-ink text-base font-semibold">{{ trans('settings.avatar') }}</div>
                            <MetaLine
                                :parts="[trans('settings.jpg_png_or_webp_up_to'), trans('settings.applied_when_you_save')]"
                                class="text-ink-muted mt-0.5 text-sm"
                            />
                            <InputError class="mt-1" :message="form.errors.avatar" />
                        </div>
                        <input ref="avatarInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="stageAvatar" />
                        <div class="flex shrink-0 items-center gap-3">
                            <Button type="button" variant="outline" class="h-10 py-2" @click="avatarInput?.click()">{{
                                trans('settings.choose_image')
                            }}</Button>
                            <Button v-if="shownAvatar" type="button" variant="ghost" class="h-10 py-2" @click="removeAvatar">{{
                                trans('settings.remove')
                            }}</Button>
                        </div>
                    </div>

                    <div :class="SETTINGS_CARD_CLASS">
                        <Label for="name" class="text-ink text-base font-semibold">{{ trans('settings.display_name') }}</Label>
                        <input id="name" v-model="form.name" required autocomplete="name" :class="[SETTINGS_FIELD_CLASS, 'mt-3 block w-full']" />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div :class="SETTINGS_CARD_CLASS">
                        <Label for="username" class="text-ink text-base font-semibold">{{ trans('common.username') }}</Label>
                        <p class="text-ink-muted mt-0.5 mb-3 text-sm">{{ trans('settings.lowercase_letters_digits_and_underscores_used') }}</p>
                        <input
                            id="username"
                            v-model="form.username"
                            required
                            autocomplete="username"
                            :placeholder="trans('settings.username_placeholder')"
                            :class="[SETTINGS_FIELD_CLASS, 'block w-full']"
                        />
                        <InputError class="mt-2" :message="form.errors.username" />
                    </div>

                    <div :class="SETTINGS_CARD_CLASS">
                        <Label for="bio" class="text-ink text-base font-semibold">{{ trans('settings.bio') }}</Label>
                        <textarea
                            id="bio"
                            v-model="form.bio"
                            rows="3"
                            maxlength="500"
                            :placeholder="trans('settings.a_few_words_about_you')"
                            :class="[SETTINGS_FIELD_CLASS, 'mt-3 block w-full']"
                        ></textarea>
                        <InputError class="mt-2" :message="form.errors.bio" />
                    </div>

                    <div :class="SETTINGS_CARD_CLASS">
                        <Label for="email" class="text-ink text-base font-semibold">{{ trans('common.email_address') }}</Label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            autocomplete="email"
                            :class="[SETTINGS_FIELD_CLASS, 'mt-3 block w-full']"
                        />
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>

                    <div :class="SETTINGS_CARD_CLASS">
                        <Label for="locale" class="text-ink text-base font-semibold">{{ trans('settings.language') }}</Label>
                        <select id="locale" v-model="form.locale" :class="[SETTINGS_FIELD_CLASS, 'mt-3 block w-full']">
                            <option v-for="language in languages" :key="language.value" :value="language.value">{{ language.label }}</option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.locale" />
                    </div>

                    <div :class="[SETTINGS_CARD_CLASS, 'flex items-center justify-between gap-6']">
                        <div class="min-w-0">
                            <div class="text-ink text-base font-semibold">{{ trans('settings.private_profile') }}</div>
                            <div class="text-ink-muted mt-0.5 text-sm">
                                {{ trans('settings.only_you_and_admins_can_see') }}
                            </div>
                            <InputError class="mt-1" :message="form.errors.is_private" />
                        </div>
                        <ToggleSwitch v-model="form.is_private" :label="trans('settings.private_profile')" />
                    </div>
                </fieldset>
                <SettingsSaveBar :dirty="form.isDirty" :processing="form.processing" @save="submit" @discard="discard" />
            </form>
        </SettingsLayout>
    </AppLayout>
</template>
