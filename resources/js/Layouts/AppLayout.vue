<script setup lang="ts">
import { ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppHeader from '@/Layouts/partials/AppHeader.vue'
import AppSidebar from '@/Layouts/partials/AppSidebar.vue'
import { Toaster } from '@/components/ui/sonner'
import {
  Sheet,
  SheetContent,
  SheetTitle,
} from '@/components/ui/sheet'

interface AppLayoutProps {
  title?: string
}

defineProps<AppLayoutProps>()

const mobileSidebarOpen = ref(false)

function toggleMobileSidebar(): void {
  mobileSidebarOpen.value = !mobileSidebarOpen.value
}
</script>

<template>
  <div class="flex min-h-screen flex-col bg-background text-foreground">
    <Head :title="title" />

    <AppHeader :on-toggle-sidebar="toggleMobileSidebar" />

    <div class="flex flex-1 overflow-hidden">
      <!-- Desktop sidebar -->
      <div class="hidden md:flex md:flex-shrink-0 border-r border-sidebar-border">
        <AppSidebar />
      </div>

      <!-- Mobile sidebar (Sheet overlay) -->
      <Sheet v-model:open="mobileSidebarOpen">
        <SheetContent side="left" class="w-[var(--sidebar-width)] p-0">
          <SheetTitle class="sr-only">Navigation</SheetTitle>
          <AppSidebar />
        </SheetContent>
      </Sheet>

      <!-- Main content -->
      <main class="flex-1 overflow-y-auto">
        <slot />
      </main>
    </div>

    <Toaster />
  </div>
</template>
