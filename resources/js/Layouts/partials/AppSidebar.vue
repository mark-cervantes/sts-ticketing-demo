<script setup lang="ts">
import { PlusIcon, CircleDotIcon, AlertTriangleIcon, TagIcon, BarChart3Icon } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
</script>

<template>
  <aside class="flex h-full w-[var(--sidebar-width)] flex-col bg-sidebar text-sidebar-foreground">
    <!-- New Issue button -->
    <div class="p-4">
      <Button class="w-full gap-2" size="lg">
        <PlusIcon class="size-4" />
        New Issue
      </Button>
    </div>

    <!-- Filter sections -->
    <nav class="flex-1 space-y-6 overflow-y-auto px-4 pb-4">
      <!-- Status filter -->
      <div>
        <h3 class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          <CircleDotIcon class="size-3.5" />
          Status
        </h3>
        <div class="space-y-1">
          <Skeleton class="h-7 w-full" />
          <Skeleton class="h-7 w-full" />
          <Skeleton class="h-7 w-3/4" />
        </div>
      </div>

      <!-- Priority filter -->
      <div>
        <h3 class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          <AlertTriangleIcon class="size-3.5" />
          Priority
        </h3>
        <div class="space-y-1">
          <Skeleton class="h-7 w-full" />
          <Skeleton class="h-7 w-full" />
          <Skeleton class="h-7 w-2/3" />
        </div>
      </div>

      <!-- Category filter -->
      <div>
        <h3 class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          <TagIcon class="size-3.5" />
          Category
        </h3>
        <div class="space-y-1">
          <Skeleton class="h-7 w-full" />
          <Skeleton class="h-7 w-5/6" />
        </div>
      </div>
    </nav>

    <!-- Stats placeholder -->
    <div class="border-t border-sidebar-border p-4">
      <h3 class="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
        <BarChart3Icon class="size-3.5" />
        Stats
      </h3>
      <div class="grid grid-cols-2 gap-2">
        <div class="rounded-lg bg-sidebar-accent p-3">
          <Skeleton class="mb-1 h-5 w-8" />
          <Skeleton class="h-3 w-12" />
        </div>
        <div class="rounded-lg bg-sidebar-accent p-3">
          <Skeleton class="mb-1 h-5 w-8" />
          <Skeleton class="h-3 w-12" />
        </div>
      </div>
    </div>
  </aside>
</template>
