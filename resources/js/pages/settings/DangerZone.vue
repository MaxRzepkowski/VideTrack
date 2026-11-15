<script setup lang="ts">
import ClearUserData from '@/components/ClearUserData.vue';
import DeleteUser from '@/components/DeleteUser.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.danger_zone')" />

        <SettingsLayout>
            <div class="space-y-12">
                <ClearUserData />
                <DeleteUser />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
