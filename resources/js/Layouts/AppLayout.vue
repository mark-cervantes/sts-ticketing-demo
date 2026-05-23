<script setup lang="ts">
import { ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppHeader from '@/Layouts/partials/AppHeader.vue'
import AppSidebar from '@/Layouts/partials/AppSidebar.vue'
import CreateIssueDialog from '@/components/issues/CreateIssueDialog.vue'
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
const createIssueOpen = ref(false)

function toggleMobileSidebar(): void {
  mobileSidebarOpen.value = !mobileSidebarOpen.value
}

function openCreateIssue(): void {
  createIssueOpen.value = true
}
</script>

<template>
  <div class="flex min-h-screen flex-col bg-background text-foreground">
    <Head :title="title" />

    <AppHeader :on-toggle-sidebar="toggleMobileSidebar" />

    <div class="flex flex-1 overflow-hidden">
      <!-- Desktop sidebar -->
      <div class="hidden md:flex md:flex-shrink-0 border-r border-sidebar-border">
        <AppSidebar @create-issue="openCreateIssue" />
      </div>

      <!-- Mobile sidebar (Sheet overlay) -->
      <Sheet v-model:open="mobileSidebarOpen">
        <SheetContent side="left" class="w-[var(--sidebar-width)] p-0">
          <SheetTitle class="sr-only">Navigation</SheetTitle>
          <AppSidebar @create-issue="openCreateIssue" />
        </SheetContent>
      </Sheet>

      <!-- Create Issue Dialog -->
      <CreateIssueDialog v-model:open="createIssueOpen" />

      <!-- Main content -->
      <main class="flex-1 overflow-y-auto">
        <slot />
      </main>
    </div>

    <Toaster />
  </div>
</template>
