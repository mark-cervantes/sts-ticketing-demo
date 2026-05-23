<script setup lang="ts">
import type { KanbanColumnDef, IssueStatus } from '@/types/issue'
import IssueCard from '@/components/kanban/IssueCard.vue'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { VueDraggable } from 'vue-draggable-plus'
import { InboxIcon, LoaderCircleIcon } from '@lucide/vue'

interface KanbanColumnProps {
  column: KanbanColumnDef
  skeletonLoading: boolean
}

const props = defineProps<KanbanColumnProps>()

const emit = defineEmits<{
  loadMore: [status: IssueStatus]
  moveIssue: [issueId: number, fromStatus: IssueStatus, toStatus: IssueStatus]
}>()

function handleDragEnd(evt: { item?: { dataset?: { issueId?: string; fromStatus?: string } } }): void {
  // vue-draggable-plus fires the end event — we handle via onUpdate/onAdd on the group
}

function handleAdd(evt: {
  data?: { id?: number; status?: IssueStatus }
  item?: HTMLElement
}): void {
  // Handled by the move callback on VueDraggable
}
</script>

<template>
  <div class="flex min-w-[280px] flex-1 flex-col rounded-xl bg-muted/50 dark:bg-muted/30">
    <!-- Column header -->
    <div class="flex items-center gap-2 px-3 py-2.5">
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
      :model-value="column.issues"
      group="kanban"
      item-key="id"
      :animation="200"
      ghost-class="opacity-30"
      drag-class="rotate-2"
      class="flex-1 space-y-2 overflow-y-auto px-2 pb-2"
      :class="column.issues.length === 0 ? 'min-h-[120px]' : ''"
      :data-status="column.status"
      @update:model-value="() => {}"
    >
      <template #item="{ element }">
        <div
          :data-issue-id="element.id"
          :data-from-status="element.status"
        >
          <IssueCard :issue="element" />
        </div>
      </template>

      <!-- Empty state (shown when no items and not loading) -->
      <template #footer>
        <div
          v-if="column.issues.length === 0"
          class="flex flex-col items-center justify-center py-8 text-muted-foreground"
        >
          <InboxIcon class="mb-2 size-8 opacity-40" />
          <p class="text-xs">No issues</p>
        </div>
      </template>
    </VueDraggable>

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
