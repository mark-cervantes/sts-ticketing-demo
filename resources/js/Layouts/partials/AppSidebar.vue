<script setup lang="ts">
import { onMounted, computed } from 'vue'
import {
  PlusIcon,
  CircleDotIcon,
  AlertTriangleIcon,
  TagIcon,
  BarChart3Icon,
  UserIcon,
  XIcon,
} from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useIssueFilters } from '@/composables/useIssueFilters'
import { useStatuses } from '@/composables/useStatuses'
import type { IssuePriority } from '@/types/issue'
import { PRIORITY_CONFIG } from '@/types/issue'

const emit = defineEmits<{
  (e: 'create-issue'): void
}>()

const {
  filters,
  myTickets,
  categories,
  categoriesLoading,
  fetchCategories,
  toggleStatus,
  togglePriority,
  setCategory,
  toggleMyTickets,
  clearFilters,
} = useIssueFilters()

const { statuses, loading: statusesLoading, fetchStatuses } = useStatuses()

const allPriorities: IssuePriority[] = ['critical', 'high', 'medium', 'low']

onMounted(() => {
  void fetchCategories()
  void fetchStatuses()
})

function isStatusActive(statusId: number): boolean {
  return filters.value.statuses.includes(statusId)
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
    myTickets.value === true ||
    filters.value.statuses.length < statuses.value.length ||
    filters.value.priorities.length > 0 ||
    filters.value.category !== null
  )
})
</script>

<template>
  <aside class="flex h-full w-[var(--sidebar-width)] flex-col bg-sidebar text-sidebar-foreground">
    <!-- New Issue button -->
    <div class="p-4">
      <Button class="w-full gap-2" size="lg" @click="emit('create-issue')">
        <PlusIcon class="size-4" />
        New Issue
      </Button>
    </div>

    <!-- Filter sections -->
    <nav class="flex-1 space-y-6 overflow-y-auto px-4 pb-4">

      <!-- MY TICKETS — top of sidebar, above all other filters -->
      <div>
        <h3 class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          <UserIcon class="size-3.5" />
          My Tickets
        </h3>
        <div class="flex items-center justify-between rounded-lg px-1 py-0.5">
          <span class="text-sm text-foreground">Show only mine</span>
          <Switch
            :checked="myTickets"
            aria-label="Show only my tickets"
            @update:checked="toggleMyTickets"
          />
        </div>
        <p v-if="myTickets" class="mt-1.5 text-[11px] text-muted-foreground">
          Filtering to your tickets only
        </p>
      </div>

      <!-- Status filter — toggles hide/show entire columns -->
      <div>
        <h3 class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          <CircleDotIcon class="size-3.5" />
          Status
        </h3>
        <div v-if="statusesLoading" class="flex flex-wrap gap-1.5">
          <Skeleton class="h-7 w-16 rounded-md" />
          <Skeleton class="h-7 w-20 rounded-md" />
          <Skeleton class="h-7 w-16 rounded-md" />
        </div>
        <div v-else class="flex flex-wrap gap-1.5">
          <Button
            v-for="status in statuses"
            :key="status.id"
            size="sm"
            :variant="isStatusActive(status.id) ? 'default' : 'outline'"
            class="h-7 gap-1.5 text-xs"
            @click="toggleStatus(status.id)"
          >
            <!-- Color dot -->
            <span
              class="size-2 rounded-full shrink-0"
              :style="{ backgroundColor: status.color }"
            />
            {{ status.name }}
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
