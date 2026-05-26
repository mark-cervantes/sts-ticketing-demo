<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { useKanbanBoard } from '@/composables/useKanbanBoard'
import { useIssueDetail } from '@/composables/useIssueDetail'
import KanbanColumn from '@/components/kanban/KanbanColumn.vue'
import IssueDetailSheet from '@/components/issues/IssueDetailSheet.vue'

const { columns, initialLoading, loadInitial, loadMore, moveIssue } = useKanbanBoard()
const { getIssueQueryParam } = useIssueDetail()

const sheetOpen = ref(false)
const selectedIssueId = ref<number | null>(null)

function handleIssueCreated(): void {
  void loadInitial()
}

onMounted(() => {
  void loadInitial()

  // Auto-open slide-over if ?issue= query param is present
  const issueId = getIssueQueryParam()
  if (issueId) {
    selectedIssueId.value = issueId
    sheetOpen.value = true
  }

  window.addEventListener('issue:created', handleIssueCreated)
})

onUnmounted(() => {
  window.removeEventListener('issue:created', handleIssueCreated)
})

function handleLoadMore(statusSlug: string): void {
  void loadMore(statusSlug)
}

function handleSelectIssue(issueId: number): void {
  selectedIssueId.value = issueId
  sheetOpen.value = true
}

function handleSheetOpenChange(value: boolean): void {
  sheetOpen.value = value
  if (!value) {
    selectedIssueId.value = null
  }
}

function handleMoveIssue(issueId: number, fromStatus: string, toStatus: string): void {
  void moveIssue(issueId, fromStatus, toStatus)
}
</script>

<template>
  <div class="flex flex-col gap-4 p-4 md:flex-row md:gap-4 md:overflow-x-auto">
    <KanbanColumn
      v-for="col in columns"
      :key="col.status"
      :column="col"
      :skeleton-loading="initialLoading"
      @load-more="handleLoadMore"
      @select-issue="handleSelectIssue"
      @move-issue="handleMoveIssue"
    />

    <!-- If no columns visible (all statuses filtered out) -->
    <div
      v-if="columns.length === 0 && !initialLoading"
      class="flex flex-1 items-center justify-center py-20 text-muted-foreground"
    >
      <p class="text-sm">Select at least one status filter to see issues.</p>
    </div>
  </div>

  <!-- Issue detail slide-over -->
  <IssueDetailSheet
    :open="sheetOpen"
    :issue-id="selectedIssueId"
    @update:open="handleSheetOpenChange"
  />
</template>
