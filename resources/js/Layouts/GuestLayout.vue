<script setup lang="ts">
// No imports needed — pure layout shell
</script>

<template>
    <div class="flex min-h-screen">
        <!-- Left panel: brand side — always dark, hidden on mobile -->
        <aside
            class="relative hidden lg:flex lg:w-1/2 flex-col justify-between overflow-hidden bg-primary p-12 text-primary-foreground"
            aria-hidden="true"
        >
            <!-- Subtle geometric decorations -->
            <div class="pointer-events-none absolute inset-0 overflow-hidden">
                <div class="absolute -top-32 -right-32 h-96 w-96 rounded-full bg-white/5" />
                <div class="absolute -bottom-24 -left-24 h-72 w-72 rounded-full bg-white/5" />
                <div class="absolute top-1/2 left-1/3 h-40 w-40 -translate-y-1/2 rounded-full bg-white/[0.03]" />
                <div class="absolute right-16 bottom-32 h-px w-48 rotate-45 bg-white/10" />
                <div class="absolute right-24 bottom-40 h-px w-32 rotate-45 bg-white/10" />
            </div>

            <!-- Brand content — top -->
            <div class="relative z-10">
                <div class="mb-2 flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-white/15 text-lg font-bold">
                        S
                    </div>
                    <span class="text-lg font-semibold tracking-wide opacity-90">STS</span>
                </div>
            </div>

            <!-- Central headline -->
            <div class="relative z-10 flex flex-1 flex-col justify-center">
                <h1 class="text-4xl font-bold leading-tight tracking-tight">
                    Smart<br />Ticketing<br />System
                </h1>
                <p class="mt-4 max-w-xs text-base leading-relaxed opacity-75">
                    Streamline your support workflow. Track issues, collaborate
                    with your team, and resolve faster.
                </p>
                <ul class="mt-8 space-y-3 text-sm opacity-70">
                    <li class="flex items-center gap-2">
                        <span class="inline-block h-1.5 w-1.5 rounded-full bg-white/80" />
                        Kanban-first issue board
                    </li>
                    <li class="flex items-center gap-2">
                        <span class="inline-block h-1.5 w-1.5 rounded-full bg-white/80" />
                        AI-powered smart summaries
                    </li>
                    <li class="flex items-center gap-2">
                        <span class="inline-block h-1.5 w-1.5 rounded-full bg-white/80" />
                        Real-time status updates
                    </li>
                </ul>
            </div>

            <!-- Bottom watermark -->
            <div class="relative z-10 text-xs opacity-40">
                &copy; {{ new Date().getFullYear() }} Smart Ticketing System
            </div>
        </aside>

        <!-- Right panel: form area -->
        <main class="flex flex-1 flex-col items-center justify-center bg-background px-6 py-12 sm:px-10">
            <!-- Mobile logo (only visible when left panel is hidden) -->
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary text-lg font-bold text-primary-foreground">
                    S
                </div>
                <span class="text-lg font-semibold text-foreground">Smart Ticketing System</span>
            </div>

            <div class="w-full max-w-sm">
                <slot />
            </div>
        </main>
    </div>
</template>
