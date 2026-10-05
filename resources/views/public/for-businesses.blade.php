<x-public-layout title="For Businesses">
    <section class="mx-auto max-w-4xl px-4 py-12 sm:px-6">
        <h1 class="font-display text-3xl font-semibold">Raise ethical capital for real business activity</h1>
        <p class="mt-3 text-ink-600">Choose the structure that fits your project: Mudarabah profit-sharing, Musharakah partnership, or Murabaha asset financing.</p>
        <ul class="mt-6 list-disc space-y-1 pl-5 text-ink-700"><li>Create a business profile and complete verification</li><li>Submit a project with financials and supporting documents</li><li>Projects are reviewed, including Shariah review, before publication</li></ul>
        <x-ui.button :href="route('business.projects.create')" class="mt-8" size="lg">Submit Your Project</x-ui.button>
    </section>
    <x-public.transparency />
</x-public-layout>
