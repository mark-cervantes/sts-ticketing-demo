<script setup lang="ts">
import type { KanbanColumnDef } from '@/types/issue'
import IssueCard from '@/components/kanban/IssueCard.vue'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { VueDraggable } from 'vue-draggable-plus'
import { InboxIcon, LoaderCircleIcon } from '@lucide/vue'
import { ref, watch } from 'vue'

interface KanbanColumnProps {
  column: KanbanColumnDef
  skeletonLoading: boolean
}

const props = defineProps<KanbanColumnProps>()

const emit = defineEmits<{
  loadMore: [statusSlug: string]
  moveIssue: [issueId: number, fromStatusSlug: string, toStatusSlug: string]
  selectIssue: [issueId: number]
}>()

// vue-draggable-plus uses v-model with the default slot (not #item slots).
// Keep a local copy that syncs from the parent's reactive column.issues.
const localIssues = ref([...props.column.issues])
watch(() => props.column.issues, (newIssues) => {
  localIssues.value = [...newIssues]
}, { deep: true })

/**
 * SortableJS `end` event fires on the source list after every drag operation.
 * `evt.from` is the source list element, `evt.to` is the destination list element.
 * Both carry `data-status` attributes set on the VueDraggable element.
 * `evt.item` is the dragged card wrapper div, which carries `data-issue-id`.
 */
function handleDragEnd(evt: { from: HTMLElement; to: HTMLElement; item: HTMLElement }): void {
  const fromStatus = evt.from.dataset.status
  const toStatus = evt.to.dataset.status

  // Same-column reorder — nothing to persist
  if (!fromStatus || !toStatus || fromStatus === toStatus) return

  const issueId = Number(evt.item.dataset.issueId)
  if (!issueId || isNaN(issueId)) return

  emit('moveIssue', issueId, fromStatus, toStatus)
}
</script>

<template>
  <div class="kanban-column flex min-w-[280px] flex-1 flex-col rounded-xl bg-muted/50 dark:bg-muted/30">
    <!-- Column header -->
    <div class="flex items-center gap-2 px-3 py-2.5">
      <!-- Status color dot -->
      <span
        class="size-2.5 rounded-full shrink-0"
        :style="{ backgroundColor: column.color }"
      />
      <h3 class="text-sm font-semibold text-foreground">
        {{ column.label }}
      </h3>
      <span
        class="flex size-5 items-center justify-center rounded-full bg-muted text-[10px] font-medium text-muted-foreground"
      >
        {{ column.issues.length }}
      </span>
    </div>

    <!-- Skeleton loading state -->
    <div v-if="skeletonLoading" class="space-y-2 px-2 pb-2">
      <Skeleton class="h-28 w-full rounded-lg" />
      <Skeleton class="h-24 w-full rounded-lg" />
      <Skeleton class="h-20 w-full rounded-lg" />
    </div>

    <!-- Card list with drag-drop -->
    <VueDraggable
      v-else
      v-model="localIssues"
      group="kanban"
      :animation="200"
      ghost-class="opacity-30"
      drag-class="rotate-2"
      class="flex-1 space-y-2 overflow-y-auto px-2 pb-2"
      :class="column.issues.length === 0 ? 'min-h-[120px]' : ''"
      :data-status="column.status"
      @end="handleDragEnd"
    >
      <div
        v-for="issue in localIssues"
        :key="issue.id"
        :data-issue-id="issue.id"
        :data-from-status="issue.status"
      >
        <IssueCard
          :issue="issue"
          @select="(id: number) => emit('selectIssue', id)"
        />
      </div>
    </VueDraggable>

    <!-- Empty state (shown when no items and not loading) -->
    <div
      v-if="!skeletonLoading && column.issues.length === 0"
      class="flex flex-col items-center justify-center py-8 text-muted-foreground"
    >
      <InboxIcon class="mb-2 size-8 opacity-40" />
      <p class="text-xs">No issues</p>
    </div>

    <!-- Load more button -->
    <div v-if="column.hasMore && !skeletonLoading" class="px-2 pb-2">
      <Button
        variant="ghost"
        size="sm"
        class="w-full text-xs"
        :disabled="column.loading"
        @click="emit('loadMore', column.status)"
      >
        <LoaderCircleIcon v-if="column.loading" class="mr-1.5 size-3 animate-spin" />
        {{ column.loading ? 'Loading…' : 'Load more' }}
      </Button>
    </div>
  </div>
</template>
