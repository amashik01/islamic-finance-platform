<x-dynamic-component :component="$portal.'-layout'" title="Profile">
    <div class="mx-auto max-w-2xl space-y-6">
        <x-ui.card title="Profile information"><livewire:profile.update-profile-information-form /></x-ui.card>
        <x-ui.card title="Password"><livewire:profile.update-password-form /></x-ui.card>
        <x-ui.card title="Delete account"><livewire:profile.delete-user-form /></x-ui.card>
    </div>
</x-dynamic-component>
