<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { VueDraggable } from 'vue-draggable-plus'
import { toast } from 'vue-sonner'
import { LockIcon, LockOpenIcon, PlusIcon, CheckIcon, LoaderCircleIcon } from '@lucide/vue'
import { useKanbanBoard } from '@/composables/useKanbanBoard'
import { useIssueDetail } from '@/composables/useIssueDetail'
import { useStatuses } from '@/composables/useStatuses'
import { apiPut, apiPost } from '@/composables/useApiFetch'
import KanbanColumn from '@/components/kanban/KanbanColumn.vue'
import IssueDetailSheet from '@/components/issues/IssueDetailSheet.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import type { KanbanColumnDef } from '@/types/issue'

const { columns, initialLoading, loadInitial, loadMore, moveIssue } = useKanbanBoard()
const { getIssueQueryParam } = useIssueDetail()
const { refresh: refreshStatuses } = useStatuses()

// ── Issue detail sheet ────────────────────────────────────────────────────────
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

// ── Edit mode ─────────────────────────────────────────────────────────────────
const editMode = ref(false)

function toggleEditMode(): void {
  editMode.value = !editMode.value
}

// ── Column reorder via drag ───────────────────────────────────────────────────
/**
 * Local mutable copy of columns for drag-and-drop reordering.
 * Synced from the computed `columns` whenever statuses refresh.
 */
const localColumns = ref<KanbanColumnDef[]>([])

// Keep localColumns in sync with computed columns
watch(columns, (newCols) => {
  localColumns.value = [...newCols]
}, { immediate: true })

const reorderLoading = ref(false)

async function handleColumnDragEnd(): Promise<void> {
  if (reorderLoading.value) return
  reorderLoading.value = true

  try {
    // Send PUT for each column with its new sort_order (0-based index)
    await Promise.all(
      localColumns.value.map((col, index) =>
        apiPut(`/api/statuses/${col.statusId}`, { sort_order: index }),
      ),
    )
    // Bust the singleton cache so `columns` computed picks up new sort_order
    await refreshStatuses()
    toast.success('Column order saved')
  } catch {
    toast.error('Failed to save column order — reverting.')
    // Revert local order back to server order
    localColumns.value = [...columns.value]
  } finally {
    reorderLoading.value = false
  }
}

// ── Add status card ───────────────────────────────────────────────────────────
const addingStatus = ref(false)
const newStatusName = ref('')
const newStatusColor = ref('#6366f1')
const addStatusLoading = ref(false)
const addStatusError = ref<string | null>(null)

const COLOR_SWATCHES = [
  '#6366f1', '#22c55e', '#f59e0b', '#ef4444',
  '#8b5cf6', '#06b6d4', '#ec4899', '#64748b',
]

function openAddStatus(): void {
  addingStatus.value = true
  newStatusName.value = ''
  newStatusColor.value = '#6366f1'
  addStatusError.value = null
}

function cancelAddStatus(): void {
  addingStatus.value = false
}

async function handleCreateStatus(): Promise<void> {
  const name = newStatusName.value.trim()
  if (!name) {
    addStatusError.value = 'Name is required.'
    return
  }
  addStatusError.value = null
  addStatusLoading.value = true

  try {
    const response = await apiPost('/api/statuses', {
      name,
      color: newStatusColor.value,
      sort_order: columns.value.length,
    })

    if (response.status === 422) {
      const body = (await response.json()) as { errors?: Record<string, string[]>; message?: string }
      addStatusError.value = body.errors?.name?.[0] ?? body.message ?? 'Validation error.'
      return
    }

    if (!response.ok) {
      toast.error('Failed to create status')
      return
    }

    await refreshStatuses()
    await loadInitial()
    cancelAddStatus()
    toast.success(`Status "${name}" created`)
  } catch {
    toast.error('Network error — status not created.')
  } finally {
    addStatusLoading.value = false
  }
}

// When a column is deleted, reload the board so column counts stay accurate
async function handleColumnDeleted(): Promise<void> {
  await loadInitial()
}
</script>

<template>
  <div class="relative">
    <!-- Edit mode toggle — floated top-right, zero vertical cost -->
    <div class="absolute right-4 top-3 z-10 flex items-center gap-1.5">
      <LoaderCircleIcon
        v-if="reorderLoading"
        class="size-3.5 animate-spin text-muted-foreground"
      />
      <Button
        variant="ghost"
        size="icon-sm"
        :class="editMode ? 'text-primary' : 'text-muted-foreground'"
        :title="editMode ? 'Lock columns (exit edit mode)' : 'Edit columns'"
        :aria-label="editMode ? 'Lock columns (exit edit mode)' : 'Edit columns'"
        :aria-pressed="editMode"
        @click="toggleEditMode"
      >
        <LockOpenIcon v-if="editMode" class="size-3.5" />
        <LockIcon v-else class="size-3.5" />
      </Button>
    </div>

    <!-- Kanban board — columns wrapped in VueDraggable for column reordering -->
    <div class="flex flex-col gap-4 p-4 md:flex-row md:gap-4 md:overflow-x-auto">
      <VueDraggable
        v-model="localColumns"
        group="kanban-columns"
        :animation="200"
        handle=".column-drag-handle"
        ghost-class="opacity-40"
        :disabled="!editMode"
        class="flex flex-col gap-4 md:flex-row md:gap-4"
        @end="handleColumnDragEnd"
      >
        <KanbanColumn
          v-for="col in localColumns"
          :key="col.statusId"
          :column="col"
          :skeleton-loading="initialLoading"
          :edit-mode="editMode"
          :all-columns="localColumns"
          @load-more="handleLoadMore"
          @select-issue="handleSelectIssue"
          @move-issue="handleMoveIssue"
          @column-deleted="handleColumnDeleted"
        />
      </VueDraggable>

      <!-- Add Status card (edit mode only) -->
      <div
        v-if="editMode"
        class="flex min-w-[280px] shrink-0 flex-col rounded-xl border-2 border-dashed border-primary/30 bg-muted/20 dark:bg-muted/10"
      >
        <!-- Collapsed: show + button -->
        <template v-if="!addingStatus">
          <button
            type="button"
            class="flex flex-1 flex-col items-center justify-center gap-2 py-8 text-muted-foreground transition-colors hover:text-primary"
            @click="openAddStatus"
          >
            <PlusIcon class="size-6" />
            <span class="text-sm font-medium">Add Status</span>
          </button>
        </template>

        <!-- Expanded: inline form -->
        <template v-else>
          <div class="space-y-3 p-3">
            <p class="text-sm font-semibold text-foreground">New Status</p>

            <Input
              v-model="newStatusName"
              placeholder="Status name…"
              :disabled="addStatusLoading"
              @keydown.enter.prevent="handleCreateStatus"
              @keydown.escape.prevent="cancelAddStatus"
            />

            <!-- Color swatches -->
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="swatch in COLOR_SWATCHES"
                :key="swatch"
                type="button"
                class="size-6 rounded-full border-2 transition-all hover:scale-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :style="{ backgroundColor: swatch }"
                :class="newStatusColor === swatch ? 'border-foreground' : 'border-transparent'"
                :aria-label="`Select color ${swatch}`"
                @click="newStatusColor = swatch"
              >
                <CheckIcon
                  v-if="newStatusColor === swatch"
                  class="mx-auto size-3 text-white drop-shadow"
                />
              </button>
              <input
                v-model="newStatusColor"
                type="color"
                class="size-6 cursor-pointer rounded border border-input bg-transparent p-0.5"
                aria-label="Custom color"
              />
            </div>

            <p v-if="addStatusError" class="text-xs text-destructive">{{ addStatusError }}</p>

            <div class="flex gap-2">
              <Button
                size="sm"
                class="flex-1"
                :disabled="addStatusLoading"
                @click="handleCreateStatus"
              >
                <LoaderCircleIcon v-if="addStatusLoading" class="mr-1.5 size-3.5 animate-spin" />
                Create
              </Button>
              <Button
                variant="ghost"
                size="sm"
                :disabled="addStatusLoading"
                @click="cancelAddStatus"
              >
                Cancel
              </Button>
            </div>
          </div>
        </template>
      </div>

      <!-- If no columns visible (all statuses filtered out) -->
      <div
        v-if="localColumns.length === 0 && !initialLoading && !editMode"
        class="flex flex-1 items-center justify-center py-20 text-muted-foreground"
      >
        <p class="text-sm">Select at least one status filter to see issues.</p>
      </div>
    </div>
  </div>

  <!-- Issue detail slide-over -->
  <IssueDetailSheet
    :open="sheetOpen"
    :issue-id="selectedIssueId"
    @update:open="handleSheetOpenChange"
  />
</template>
