<script setup lang="ts">
import { onMounted, ref } from 'vue'
import type { IssueStatus, Issue } from '@/types/issue'
import { useKanbanBoard } from '@/composables/useKanbanBoard'
import KanbanColumn from '@/components/kanban/KanbanColumn.vue'

const { columns, initialLoading, loadInitial, loadMore, moveIssue } = useKanbanBoard()

const dragFromStatus = ref<IssueStatus | null>(null)

onMounted(() => {
  void loadInitial()
})

function handleLoadMore(status: IssueStatus): void {
  void loadMore(status)
}

/**
 * Global drag-start: capture the issue's original status so we can
 * detect cross-column moves even when vue-draggable-plus fires the
 * end event on the target list.
 */
function handleDragStart(evt: DragEvent): void {
  const target = evt.target as HTMLElement | null
  const wrapper = target?.closest('[data-from-status]') as HTMLElement | null
  if (wrapper?.dataset.fromStatus) {
    dragFromStatus.value = wrapper.dataset.fromStatus as IssueStatus
  }
}

function handleDragEnd(evt: DragEvent): void {
  const target = evt.target as HTMLElement | null
  const wrapper = target?.closest('[data-issue-id]') as HTMLElement | null
  if (!wrapper) return

  const issueId = Number(wrapper.dataset.issueId)
  if (!issueId || isNaN(issueId)) return

  // Determine which column the card was dropped into
  const columnEl = wrapper.closest('[data-status]') as HTMLElement | null
  const toStatus = columnEl?.dataset.status as IssueStatus | undefined
  if (!toStatus) return

  const fromStatus = dragFromStatus.value
  if (!fromStatus || fromStatus === toStatus) return

  void moveIssue(issueId, fromStatus, toStatus)
  dragFromStatus.value = null
}
</script>

<template>
  <div
    class="flex flex-col gap-4 p-4 md:flex-row md:gap-4 md:overflow-x-auto"
    @dragstart="handleDragStart"
    @dragend="handleDragEnd"
  >
    <KanbanColumn
      v-for="col in columns"
      :key="col.status"
      :column="col"
      :skeleton-loading="initialLoading"
      @load-more="handleLoadMore"
    />

    <!-- If no columns visible (all statuses filtered out) -->
    <div
      v-if="columns.length === 0 && !initialLoading"
      class="flex flex-1 items-center justify-center py-20 text-muted-foreground"
    >
      <p class="text-sm">Select at least one status filter to see issues.</p>
    </div>
  </div>
</template>
