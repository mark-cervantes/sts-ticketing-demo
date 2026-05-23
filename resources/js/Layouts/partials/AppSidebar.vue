<script setup lang="ts">
import { onMounted, computed } from 'vue'
import {
  PlusIcon,
  CircleDotIcon,
  AlertTriangleIcon,
  TagIcon,
  BarChart3Icon,
  XIcon,
} from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useIssueFilters } from '@/composables/useIssueFilters'
import type { IssueStatus, IssuePriority } from '@/types/issue'
import { STATUS_CONFIG, PRIORITY_CONFIG } from '@/types/issue'

const {
  filters,
  categories,
  categoriesLoading,
  fetchCategories,
  toggleStatus,
  togglePriority,
  setCategory,
  clearFilters,
} = useIssueFilters()

const allStatuses: IssueStatus[] = ['open', 'in_progress', 'resolved']
const allPriorities: IssuePriority[] = ['critical', 'high', 'medium', 'low']

onMounted(() => {
  void fetchCategories()
})

function isStatusActive(status: IssueStatus): boolean {
  return filters.value.statuses.includes(status)
}

function isPriorityActive(priority: IssuePriority): boolean {
  return filters.value.priorities.includes(priority)
}

function handleCategoryChange(value: string | number | bigint | Record<string, unknown> | null): void {
  const slug = typeof value === 'string' ? value : null
  setCategory(slug === '__all__' ? null : slug)
}

const hasActiveFilters = computed(() => {
  return (
    filters.value.statuses.length < 3 ||
    filters.value.priorities.length > 0 ||
    filters.value.category !== null
  )
})
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
      <!-- Status filter — toggles hide/show entire columns -->
      <div>
        <h3 class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          <CircleDotIcon class="size-3.5" />
          Status
        </h3>
        <div class="flex flex-wrap gap-1.5">
          <Button
            v-for="status in allStatuses"
            :key="status"
            size="sm"
            :variant="isStatusActive(status) ? 'default' : 'outline'"
            class="h-7 gap-1.5 text-xs"
            @click="toggleStatus(status)"
          >
            {{ STATUS_CONFIG[status].label }}
          </Button>
        </div>
      </div>

      <!-- Priority filter — filters cards within visible columns -->
      <div>
        <h3 class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          <AlertTriangleIcon class="size-3.5" />
          Priority
        </h3>
        <div class="flex flex-wrap gap-1.5">
          <Button
            v-for="priority in allPriorities"
            :key="priority"
            size="sm"
            :variant="isPriorityActive(priority) ? 'default' : 'outline'"
            class="h-7 gap-1.5 text-xs"
            @click="togglePriority(priority)"
          >
            {{ PRIORITY_CONFIG[priority].label }}
          </Button>
        </div>
      </div>

      <!-- Category filter — select dropdown -->
      <div>
        <h3 class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          <TagIcon class="size-3.5" />
          Category
        </h3>
        <div v-if="categoriesLoading" class="space-y-1">
          <Skeleton class="h-9 w-full" />
        </div>
        <Select
          v-else
          :model-value="filters.category ?? '__all__'"
          @update:model-value="handleCategoryChange"
        >
          <SelectTrigger class="h-9 text-xs">
            <SelectValue placeholder="All categories" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="__all__">All categories</SelectItem>
            <SelectItem
              v-for="cat in categories"
              :key="cat.slug"
              :value="cat.slug"
            >
              {{ cat.name }}
            </SelectItem>
          </SelectContent>
        </Select>
      </div>

      <!-- Clear filters -->
      <div v-if="hasActiveFilters">
        <Button
          variant="ghost"
          size="sm"
          class="h-7 gap-1.5 text-xs text-muted-foreground"
          @click="clearFilters()"
        >
          <XIcon class="size-3" />
          Clear filters
        </Button>
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
