<script setup lang="ts">
import type { Issue, KanbanColumnDef } from '@/types/issue'
import { PRIORITY_CONFIG } from '@/types/issue'
import IssueCard from '@/components/kanban/IssueCard.vue'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Input } from '@/components/ui/input'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { VueDraggable } from 'vue-draggable-plus'
import {
  ArrowDownUpIcon,
  ChevronDownIcon,
  ChevronRightIcon,
  GripVerticalIcon,
  InboxIcon,
  LoaderCircleIcon,
  LockIcon,
  XIcon,
} from '@lucide/vue'
import { onMounted, ref, watch } from 'vue'
import { toast } from 'vue-sonner'
import { apiDelete, apiPut } from '@/composables/useApiFetch'
import { useStatuses } from '@/composables/useStatuses'
import { useColumnSort, SORT_OPTIONS } from '@/composables/useColumnSort'
import type { SortKey } from '@/composables/useColumnSort'
import type { AcceptableValue } from 'reka-ui'

interface KanbanColumnProps {
  column: KanbanColumnDef
  skeletonLoading: boolean
  editMode?: boolean
  /** All columns — needed for migration target select (exclude self). */
  allColumns?: KanbanColumnDef[]
  /** Whether this column should start collapsed (controlled by the board). */
  defaultCollapsed?: boolean
}

const props = withDefaults(defineProps<KanbanColumnProps>(), {
  editMode: false,
  allColumns: () => [],
  defaultCollapsed: false,
})

const emit = defineEmits<{
  loadMore: [statusSlug: string]
  moveIssue: [issueId: number, fromStatusSlug: string, toStatusSlug: string, dropIndex: number]
  selectIssue: [issueId: number]
  columnDeleted: []
}>()

const { refresh: refreshStatuses } = useStatuses()
const { getSortKey, setSortKey } = useColumnSort()

// ── Collapse / expand ─────────────────────────────────────────────────────────
const collapsed = ref(false)
const localStorageKey = `kanban-col-${props.column.status}-collapsed`

onMounted(() => {
  const stored = localStorage.getItem(localStorageKey)
  if (stored !== null) {
    collapsed.value = stored === 'true'
  } else {
    collapsed.value = props.defaultCollapsed
  }
})

function toggleCollapsed(): void {
  collapsed.value = !collapsed.value
  localStorage.setItem(localStorageKey, String(collapsed.value))
}

// ── Local issues model ────────────────────────────────────────────────────────
// vue-draggable-plus uses v-model with the default slot (not #item slots).
// Keep a local copy that syncs from the parent's reactive column.issues.
//
// IMPORTANT: Only reset localIssues when the SET of issues changes (add/remove),
// NOT when an issue's fields change in-place (e.g., status update after drag).
// Deep-watching would reset the VueDraggable order on every field mutation,
// snapping dragged cards to the top of the column.
const localIssues = ref([...props.column.issues])

function issueIds(issues: Issue[]): string {
  return issues.map(i => i.id).join(',')
}

watch(() => props.column.issues, (newIssues) => {
  const oldIds = issueIds(localIssues.value)
  const newIds = issueIds(newIssues)
  if (oldIds !== newIds) {
    // Issue set changed (added/removed/filtered) — rebuild localIssues
    // The parent already applied sorting in the columns computed, so we
    // take the new array as-is.
    localIssues.value = [...newIssues]
  }
  // If only fields changed (same IDs), localIssues already has the same
  // object references via columnMap — no reset needed.
}, { deep: true })

// ── Sort key watcher ──────────────────────────────────────────────────────────
// The parent computed applies sorting before emitting issues via props.
// However, when ONLY the sort key changes (same IDs, different order),
// the ID-set watcher above won't fire. We must watch the sort key directly
// and re-apply sorting to localIssues to propagate the new order without
// discarding VueDraggable's internal state.
watch(
  () => getSortKey(props.column.status),
  () => {
    // The parent computed has already re-sorted props.column.issues.
    // Sync localIssues to match the new order.
    localIssues.value = [...props.column.issues]
  },
)

// ── Inline rename ─────────────────────────────────────────────────────────────
const renaming = ref(false)
const renameValue = ref('')
const renameLoading = ref(false)
const renameInput = ref<InstanceType<typeof Input> | null>(null)

function startRename(): void {
  if (!props.editMode) return
  renaming.value = true
  renameValue.value = props.column.label
  // Focus after DOM update
  setTimeout(() => {
    const el = renameInput.value?.$el as HTMLInputElement | undefined
    el?.focus()
    el?.select()
  }, 30)
}

function cancelRename(): void {
  renaming.value = false
  renameValue.value = ''
}

async function commitRename(): Promise<void> {
  const name = renameValue.value.trim()
  if (!name || name === props.column.label) {
    cancelRename()
    return
  }
  renameLoading.value = true
  try {
    const response = await apiPut(`/api/statuses/${props.column.statusId}`, { name })
    if (response.status === 422) {
      const body = (await response.json()) as { errors?: Record<string, string[]>; message?: string }
      toast.error(body.errors?.name?.[0] ?? body.message ?? 'Validation error.')
      return
    }
    if (!response.ok) {
      toast.error(`Failed to rename "${props.column.label}"`)
      return
    }
    await refreshStatuses()
    cancelRename()
    toast.success(`Status renamed to "${name}"`)
  } catch {
    toast.error('Network error — rename failed.')
  } finally {
    renameLoading.value = false
  }
}

// ── Delete (zero-issue) via AlertDialog ───────────────────────────────────────
const deleteLoading = ref(false)

async function handleSimpleDelete(): Promise<void> {
  deleteLoading.value = true
  try {
    const response = await apiDelete(`/api/statuses/${props.column.statusId}`)
    if (!response.ok) {
      const body = (await response.json()) as { message?: string }
      toast.error(body.message ?? `Failed to delete "${props.column.label}"`)
      return
    }
    toast.success(`Status "${props.column.label}" deleted`)
    await refreshStatuses()
    emit('columnDeleted')
  } catch {
    toast.error('Network error — delete failed.')
  } finally {
    deleteLoading.value = false
  }
}

// ── Delete with migration (has issues) via Dialog ────────────────────────────
const migrateDialogOpen = ref(false)
const migrateTargetId = ref<string>('')
const migrateDeleteAll = ref(false)
const migrateLoading = ref(false)

function openMigrateDialog(): void {
  migrateTargetId.value = ''
  migrateDeleteAll.value = false
  migrateDialogOpen.value = true
}

async function handleMigrateDelete(): Promise<void> {
  const body: Record<string, unknown> = {}
  if (migrateDeleteAll.value) {
    body.delete_issues = true
  } else if (migrateTargetId.value) {
    body.target_status_id = Number(migrateTargetId.value)
  } else {
    toast.error('Please choose a target status or check "Delete all issues".')
    return
  }

  migrateLoading.value = true
  try {
    const response = await fetch(`/api/statuses/${props.column.statusId}/migrate-and-delete`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': (() => {
          const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
          return m ? decodeURIComponent(m[1]) : ''
        })(),
      },
      credentials: 'same-origin',
      body: JSON.stringify(body),
    })

    if (!response.ok) {
      const err = (await response.json()) as { message?: string }
      toast.error(err.message ?? 'Failed to delete status.')
      return
    }

    toast.success(`Status "${props.column.label}" deleted`)
    migrateDialogOpen.value = false
    await refreshStatuses()
    emit('columnDeleted')
  } catch {
    toast.error('Network error — migrate and delete failed.')
  } finally {
    migrateLoading.value = false
  }
}

// ── Issue drag-end handler ────────────────────────────────────────────────────
/**
 * SortableJS `end` event fires on the source list after every drag operation.
 * `evt.from` is the source list element, `evt.to` is the destination list element.
 * Both carry `data-status` attributes set on the VueDraggable element.
 * `evt.item` is the dragged card wrapper div, which carries `data-issue-id`.
 */
function handleDragEnd(evt: { from: HTMLElement; to: HTMLElement; item: HTMLElement; newIndex?: number }): void {
  const fromStatus = evt.from.dataset.status
  const toStatus = evt.to.dataset.status

  // Same-column reorder — nothing to persist
  if (!fromStatus || !toStatus || fromStatus === toStatus) return

  const issueId = Number(evt.item.dataset.issueId)
  if (!issueId || isNaN(issueId)) return

  emit('moveIssue', issueId, fromStatus, toStatus, evt.newIndex ?? -1)
}

// Debounce guard so rapid taps don't spam toasts
let lastFilterToast = 0

function handleFilteredDrag(): void {
  const now = Date.now()
  if (now - lastFilterToast < 3000) return
  lastFilterToast = now

  toast.info('View-only issue', {
    description: 'You don\'t have edit access to this issue. Ask the owner to share it with you.',
  })
}

// ── Sort helpers ──────────────────────────────────────────────────────────────
function handleSortChange(value: AcceptableValue): void {
  if (typeof value === 'string') {
    setSortKey(props.column.status, value as SortKey)
  }
}

function currentSortLabel(): string {
  const key = getSortKey(props.column.status)
  return SORT_OPTIONS.find((o) => o.key === key)?.label ?? 'Priority'
}
</script>

<template>
  <div
    class="kanban-column flex min-w-[280px] flex-1 flex-col rounded-xl bg-muted/50 dark:bg-muted/30 transition-all"
    :class="editMode ? 'ring-2 ring-primary/30' : ''"
  >
    <!-- Column header -->
    <div class="flex items-center gap-2 px-3 py-2.5">
      <!-- Drag handle (edit mode only) -->
      <GripVerticalIcon
        v-if="editMode"
        class="column-drag-handle size-4 shrink-0 cursor-grab text-muted-foreground/60 active:cursor-grabbing"
      />

      <!-- Status color dot -->
      <span
        class="size-2.5 shrink-0 rounded-full"
        :style="{ backgroundColor: column.color }"
      />

      <!-- Column name: input in rename mode, heading otherwise -->
      <template v-if="renaming">
        <Input
          ref="renameInput"
          v-model="renameValue"
          class="h-6 flex-1 px-1.5 py-0 text-sm font-semibold"
          :disabled="renameLoading"
          @keydown.enter.prevent="commitRename"
          @keydown.escape.prevent="cancelRename"
          @blur="commitRename"
        />
      </template>
      <template v-else>
        <h3
          class="flex-1 text-sm font-semibold text-foreground"
          :class="editMode ? 'cursor-text hover:underline hover:underline-offset-2' : ''"
          :title="editMode ? 'Double-click to rename' : undefined"
          @dblclick="startRename"
        >
          {{ column.label }}
        </h3>
      </template>

      <!-- Issue count badge — shows server-side total from pagination meta -->
      <span
        class="flex h-5 min-w-5 items-center justify-center rounded-full bg-muted px-1 text-[10px] font-medium text-muted-foreground"
      >
        {{ column.totalCount }}
      </span>

      <!-- Sort dropdown — subtle, non-edit-mode -->
      <DropdownMenu v-if="!editMode">
        <DropdownMenuTrigger as-child>
          <Button
            variant="ghost"
            size="icon"
            class="size-6 shrink-0 text-muted-foreground/60 hover:text-muted-foreground"
            :title="`Sort: ${currentSortLabel()}`"
            aria-label="Sort column"
          >
            <ArrowDownUpIcon class="size-3.5" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-44">
          <DropdownMenuRadioGroup
            :model-value="getSortKey(column.status)"
            @update:model-value="handleSortChange"
          >
            <DropdownMenuRadioItem
              v-for="opt in SORT_OPTIONS"
              :key="opt.key"
              :value="opt.key"
              class="text-xs"
            >
              {{ opt.label }}
            </DropdownMenuRadioItem>
          </DropdownMenuRadioGroup>
        </DropdownMenuContent>
      </DropdownMenu>

      <!-- Collapse toggle (non-edit-mode only) -->
      <button
        v-if="!editMode"
        type="button"
        class="flex size-5 shrink-0 items-center justify-center rounded text-muted-foreground/60 transition-colors hover:bg-muted hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        :aria-label="collapsed ? `Expand ${column.label} column` : `Collapse ${column.label} column`"
        :title="collapsed ? `Expand ${column.label}` : `Collapse ${column.label}`"
        @click="toggleCollapsed"
      >
        <ChevronRightIcon v-if="collapsed" class="size-3.5" />
        <ChevronDownIcon v-else class="size-3.5" />
      </button>

      <!-- Delete button (edit mode only) -->
      <template v-if="editMode">
        <!-- Default status: show disabled button with tooltip -->
        <TooltipProvider v-if="column.isDefault">
          <Tooltip>
            <TooltipTrigger as-child>
              <Button
                variant="ghost"
                size="icon"
                class="size-6 shrink-0 cursor-not-allowed text-muted-foreground/40"
                disabled
                aria-label="Cannot delete default status"
              >
                <XIcon class="size-3.5" />
              </Button>
            </TooltipTrigger>
            <TooltipContent>
              <p class="text-xs">Cannot delete the default status</p>
            </TooltipContent>
          </Tooltip>
        </TooltipProvider>

        <!-- Non-default with issues: open migration dialog -->
        <Button
          v-else-if="column.issueCount > 0"
          variant="ghost"
          size="icon"
          class="size-6 shrink-0 text-muted-foreground hover:text-destructive"
          :disabled="deleteLoading"
          aria-label="Delete status"
          @click="openMigrateDialog"
        >
          <XIcon class="size-3.5" />
        </Button>

        <!-- Non-default with zero issues: simple confirm -->
        <AlertDialog v-else>
          <AlertDialogTrigger as-child>
            <Button
              variant="ghost"
              size="icon"
              class="size-6 shrink-0 text-muted-foreground hover:text-destructive"
              :disabled="deleteLoading"
              aria-label="Delete status"
            >
              <LoaderCircleIcon v-if="deleteLoading" class="size-3.5 animate-spin" />
              <XIcon v-else class="size-3.5" />
            </Button>
          </AlertDialogTrigger>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>Delete "{{ column.label }}"?</AlertDialogTitle>
              <AlertDialogDescription>
                This status has no issues. This action cannot be undone.
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>Cancel</AlertDialogCancel>
              <AlertDialogAction
                class="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                @click="handleSimpleDelete"
              >
                Delete
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </template>
    </div>

    <!-- Collapsed summary row — visible only when collapsed and not loading -->
    <div
      v-if="collapsed && !skeletonLoading"
      class="px-3 pb-3"
    >
      <button
        type="button"
        class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-muted-foreground/30 py-2 text-xs text-muted-foreground transition-colors hover:border-muted-foreground/60 hover:text-foreground"
        :aria-label="`Show ${column.totalCount} ${column.label} issue${column.totalCount === 1 ? '' : 's'}`"
        @click="toggleCollapsed"
      >
        <ChevronDownIcon class="size-3.5" />
        Show {{ column.totalCount }} issue{{ column.totalCount === 1 ? '' : 's' }}
      </button>
    </div>

    <!-- Skeleton loading state — hidden when collapsed -->
    <div v-if="skeletonLoading" v-show="!collapsed" class="space-y-2 px-2 pb-2">
      <Skeleton class="h-28 w-full rounded-lg" />
      <Skeleton class="h-24 w-full rounded-lg" />
      <Skeleton class="h-20 w-full rounded-lg" />
    </div>

    <!-- Card list with drag-drop (disabled in edit mode).
         NOTE: v-show (NOT v-if) is intentional here.
         v-if would destroy the SortableJS group registration, breaking
         cross-column drag-to-collapsed-column. v-show keeps the element
         in the DOM so SortableJS can still receive drops. -->
    <VueDraggable
      v-show="!collapsed && !skeletonLoading"
      v-model="localIssues"
      group="kanban"
      :animation="200"
      ghost-class="opacity-30"
      drag-class="rotate-2"
      :disabled="editMode"
      :filter="'.no-drag'"
      :prevent-on-filter="true"
      class="flex-1 space-y-2 overflow-y-auto px-2 pb-2"
      :class="column.issues.length === 0 ? 'min-h-[120px]' : ''"
      :data-status="column.status"
      @end="handleDragEnd"
      @filter="handleFilteredDrag"
    >
      <div
        v-for="issue in localIssues"
        :key="issue.id"
        :data-issue-id="issue.id"
        :data-from-status="issue.status"
        :class="{ 'no-drag': issue.can?.update === false }"
        class="relative"
      >
        <!-- View-only lock indicator -->
        <span
          v-if="issue.can?.update === false"
          class="absolute right-2 top-2 z-10 text-muted-foreground/50"
          title="You can only view this issue — ask the owner for edit access"
        >
          <LockIcon class="size-3" />
        </span>
        <IssueCard
          :issue="issue"
          @select="(id: number) => emit('selectIssue', id)"
        />
      </div>
    </VueDraggable>

    <!-- Empty state (shown when no items, not loading, and not collapsed) -->
    <div
      v-if="!collapsed && !skeletonLoading && column.issues.length === 0"
      class="flex flex-col items-center justify-center py-8 text-muted-foreground"
    >
      <InboxIcon class="mb-2 size-8 opacity-40" />
      <p class="text-xs">No issues</p>
    </div>

    <!-- Load more button (hidden when collapsed) -->
    <div v-if="!collapsed && column.hasMore && !skeletonLoading" class="px-2 pb-2">
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

  <!-- Migration dialog (has issues) -->
  <Dialog v-model:open="migrateDialogOpen">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Delete "{{ column.label }}"</DialogTitle>
        <DialogDescription>
          This status has {{ column.issueCount }} issue{{ column.issueCount === 1 ? '' : 's' }}.
          Choose what to do with them before deleting.
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-4 py-2">
        <!-- Target status select -->
        <div v-if="!migrateDeleteAll" class="space-y-1.5">
          <label class="text-sm font-medium text-foreground">Move issues to</label>
          <Select v-model="migrateTargetId">
            <SelectTrigger class="w-full">
              <SelectValue placeholder="Select a status…" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="col in allColumns.filter((c) => c.statusId !== column.statusId)"
                :key="col.statusId"
                :value="String(col.statusId)"
              >
                <span class="flex items-center gap-2">
                  <span
                    class="size-2 rounded-full shrink-0"
                    :style="{ backgroundColor: col.color }"
                  />
                  {{ col.label }}
                </span>
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <!-- Delete all checkbox -->
        <label class="flex cursor-pointer items-center gap-2 text-sm text-destructive">
          <input
            v-model="migrateDeleteAll"
            type="checkbox"
            class="rounded border-input accent-destructive"
          />
          Delete all {{ column.issueCount }} issue{{ column.issueCount === 1 ? '' : 's' }} permanently
        </label>
      </div>

      <DialogFooter>
        <Button variant="outline" :disabled="migrateLoading" @click="migrateDialogOpen = false">
          Cancel
        </Button>
        <Button
          variant="destructive"
          :disabled="migrateLoading || (!migrateDeleteAll && !migrateTargetId)"
          @click="handleMigrateDelete"
        >
          <LoaderCircleIcon v-if="migrateLoading" class="mr-1.5 size-4 animate-spin" />
          {{ migrateLoading ? 'Deleting…' : 'Delete Status' }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>

<style scoped>
.no-drag {
  cursor: default;
  opacity: 0.85;
}
</style>
