<script setup lang="ts">
import { ref, watch, computed, nextTick } from 'vue'
import type { Issue, IssueStatus, IssuePriority, IssueVisibility } from '@/types/issue'
import { STATUS_CONFIG, PRIORITY_CONFIG } from '@/types/issue'
import { useIssueDetail } from '@/composables/useIssueDetail'
import { useCategories } from '@/composables/useCategories'
import { useSummaryStream } from '@/composables/useSummaryStream'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from '@/components/ui/sheet'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import {
  FlameIcon,
  MoreHorizontalIcon,
  Trash2Icon,
  Loader2Icon,
  SparklesIcon,
  CalendarIcon,
  AlertCircleIcon,
} from '@lucide/vue'
import { Calendar } from '@/components/ui/calendar'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { getLocalTimeZone, today, type DateValue, parseDate } from '@internationalized/date'
import { toDate } from 'reka-ui/date'

interface IssueDetailSheetProps {
  open: boolean
  issueId: number | null
}

const props = defineProps<IssueDetailSheetProps>()

const emit = defineEmits<{
  'update:open': [value: boolean]
}>()

const {
  issue,
  loading,
  saving,
  deleting,
  error,
  fetchIssue,
  patchIssue,
  deleteIssue,
  clearIssue,
  setIssueQueryParam,
  clearIssueQueryParam,
} = useIssueDetail()

const { categories, fetchCategories } = useCategories()

const deleteDialogOpen = ref(false)
const datePickerOpen = ref(false)
const editingTitle = ref(false)
const titleInputRef = ref<HTMLInputElement | null>(null)
const localTitle = ref('')
const localDescription = ref('')

const todayDate = today(getLocalTimeZone())

// Reactive refs for SSE composable inputs
const streamIssueId = computed(() => (props.open ? props.issueId : null))
const streamEnabled = computed(
  () =>
    props.open &&
    props.issueId !== null &&
    (issue.value?.summary_status === 'processing' || issue.value?.summary_status === 'pending'),
)

// Open SSE stream when issue is processing; re-fetch on terminal event.
useSummaryStream(streamIssueId, streamEnabled, (_payload) => {
  // Re-fetch the issue to get the authoritative state from the API.
  // This ensures the UI reflects the full updated record.
  if (props.issueId !== null) {
    void fetchIssue(props.issueId)
  }
})

// Priority options for select
const priorityOptions: { value: IssuePriority; label: string }[] = [
  { value: 'critical', label: 'Critical' },
  { value: 'high', label: 'High' },
  { value: 'medium', label: 'Medium' },
  { value: 'low', label: 'Low' },
]

// Status options for select
const statusOptions: { value: IssueStatus; label: string }[] = [
  { value: 'open', label: 'Open' },
  { value: 'in_progress', label: 'In Progress' },
  { value: 'resolved', label: 'Resolved' },
]

// Summary section computed state
const summaryState = computed<'ready' | 'processing' | 'failed'>(() => {
  if (!issue.value) return 'processing'
  const status = issue.value.summary_status
  if (status === 'ready') return 'ready'
  if (status === 'failed') return 'failed'
  return 'processing' // pending or processing
})

const formattedDeadline = computed(() => {
  if (!issue.value?.deadline_at) return null
  const date = new Date(issue.value.deadline_at)
  return date.toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
})

const calendarValue = computed<DateValue | undefined>(() => {
  if (!issue.value?.deadline_at) return undefined
  try {
    const dateStr = issue.value.deadline_at.split('T')[0]
    return parseDate(dateStr)
  } catch {
    return undefined
  }
})

// Watch for open/close + issueId changes
watch(
  () => [props.open, props.issueId] as const,
  async ([isOpen, id]) => {
    if (isOpen && id) {
      await fetchIssue(id)
      await fetchCategories()
      setIssueQueryParam(id)
      syncLocalFields()
    } else if (!isOpen) {
      clearIssueQueryParam()
      clearIssue()
      editingTitle.value = false
    }
  },
  { immediate: true },
)

function syncLocalFields(): void {
  if (issue.value) {
    localTitle.value = issue.value.title
    localDescription.value = issue.value.description
  }
}

// Watch issue changes to keep local fields synced (e.g. after re-fetch on 409)
watch(issue, (val) => {
  if (val) {
    localTitle.value = val.title
    localDescription.value = val.description
  }
})

function handleOpenChange(value: boolean): void {
  emit('update:open', value)
}

// --- Field edit handlers ---

async function startEditTitle(): Promise<void> {
  editingTitle.value = true
  await nextTick()
  titleInputRef.value?.focus()
}

async function commitTitle(): Promise<void> {
  editingTitle.value = false
  const trimmed = localTitle.value.trim()
  if (!trimmed || !issue.value || trimmed === issue.value.title) {
    // Revert if empty or unchanged
    if (issue.value) localTitle.value = issue.value.title
    return
  }
  await patchIssue({ title: trimmed })
}

async function commitDescription(): Promise<void> {
  if (!issue.value || localDescription.value === issue.value.description) return
  await patchIssue({ description: localDescription.value })
}

function handlePriorityChange(value: unknown): void {
  if (typeof value !== 'string') return
  void patchIssue({ priority: value })
}

function handleStatusChange(value: unknown): void {
  if (typeof value !== 'string') return
  void patchIssue({ status: value })
}

function handleCategoryChange(value: unknown): void {
  if (typeof value !== 'string') return
  void patchIssue({ category_id: Number(value) })
}

async function handleVisibilityChange(checked: boolean): Promise<void> {
  const visibility: IssueVisibility = checked ? 'shared' : 'private'
  await patchIssue({ visibility })
}

async function handleDateSelect(date: DateValue | undefined): Promise<void> {
  if (!date) return
  const jsDate = toDate(date)
  const isoDate = jsDate.toISOString().split('T')[0]
  datePickerOpen.value = false
  await patchIssue({ deadline_at: isoDate })
}

async function handleClearDeadline(): Promise<void> {
  await patchIssue({ deadline_at: null })
}

async function handleDelete(): Promise<void> {
  const success = await deleteIssue()
  if (success) {
    deleteDialogOpen.value = false
    emit('update:open', false)
  }
}
</script>

<template>
  <Sheet :open="open" @update:open="handleOpenChange">
    <SheetContent
      side="right"
      class="w-full overflow-y-auto sm:max-w-lg"
    >
      <SheetHeader class="pr-8">
        <SheetTitle class="sr-only">Issue Details</SheetTitle>
        <SheetDescription class="sr-only">
          View and edit issue details
        </SheetDescription>
      </SheetHeader>

      <!-- Loading skeleton -->
      <div v-if="loading" class="space-y-4 p-1">
        <Skeleton class="h-7 w-3/4" />
        <Skeleton class="h-4 w-1/2" />
        <Skeleton class="h-24 w-full" />
        <div class="grid grid-cols-2 gap-3">
          <Skeleton class="h-9 w-full" />
          <Skeleton class="h-9 w-full" />
        </div>
        <Skeleton class="h-20 w-full" />
      </div>

      <!-- Error state -->
      <div v-else-if="error" class="flex flex-col items-center gap-3 py-12 text-muted-foreground">
        <AlertCircleIcon class="size-8 opacity-50" />
        <p class="text-sm">{{ error }}</p>
        <Button
          v-if="issueId"
          variant="outline"
          size="sm"
          @click="fetchIssue(issueId)"
        >
          Retry
        </Button>
      </div>

      <!-- Issue detail content -->
      <div v-else-if="issue" class="space-y-5 p-1">
        <!-- Title row: editable + needs_attention + actions menu -->
        <div class="flex items-start gap-2">
          <div class="min-w-0 flex-1">
            <!-- Inline title edit -->
            <Input
              v-if="editingTitle"
              ref="titleInputRef"
              v-model="localTitle"
              class="text-lg font-semibold"
              :disabled="saving"
              @blur="commitTitle"
              @keydown.enter.prevent="commitTitle"
              @keydown.escape.prevent="editingTitle = false; localTitle = issue.title"
            />
            <h2
              v-else
              class="cursor-pointer text-lg font-semibold leading-snug text-foreground hover:text-foreground/80"
              @click="startEditTitle"
            >
              {{ issue.title }}
            </h2>
          </div>

          <FlameIcon
            v-if="issue.needs_attention"
            class="mt-1 size-5 shrink-0 text-orange-500 dark:text-orange-400"
            aria-label="Needs attention"
          />

          <!-- Actions menu -->
          <DropdownMenu>
            <DropdownMenuTrigger as-child>
              <Button variant="ghost" size="icon-sm" class="shrink-0">
                <MoreHorizontalIcon class="size-4" />
                <span class="sr-only">Issue actions</span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem
                class="text-destructive focus:text-destructive"
                @click="deleteDialogOpen = true"
              >
                <Trash2Icon class="mr-2 size-4" />
                Delete issue
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>

        <!-- needs_attention banner -->
        <div
          v-if="issue.needs_attention"
          class="flex items-center gap-2 rounded-md border border-orange-200 bg-orange-50 px-3 py-2 text-sm text-orange-800 dark:border-orange-900 dark:bg-orange-950/50 dark:text-orange-200"
        >
          <FlameIcon class="size-4 shrink-0" />
          <span>This issue needs attention</span>
        </div>

        <!-- Description -->
        <div class="space-y-1.5">
          <Label for="detail-description">Description</Label>
          <Textarea
            id="detail-description"
            v-model="localDescription"
            placeholder="Add a description…"
            class="min-h-[80px] resize-y"
            :disabled="saving"
            @blur="commitDescription"
          />
        </div>

        <!-- Priority & Status row -->
        <div class="grid grid-cols-2 gap-3">
          <div class="space-y-1.5">
            <Label>Priority</Label>
            <Select
              :model-value="issue.priority"
              :disabled="saving"
              @update:model-value="handlePriorityChange"
            >
              <SelectTrigger>
                <SelectValue placeholder="Priority" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="opt in priorityOptions"
                  :key="opt.value"
                  :value="opt.value"
                >
                  {{ opt.label }}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div class="space-y-1.5">
            <Label>Status</Label>
            <Select
              :model-value="issue.status"
              :disabled="saving"
              @update:model-value="handleStatusChange"
            >
              <SelectTrigger>
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="opt in statusOptions"
                  :key="opt.value"
                  :value="opt.value"
                >
                  {{ opt.label }}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        <!-- Category -->
        <div class="space-y-1.5">
          <Label>Category</Label>
          <Select
            :model-value="String(issue.category_id)"
            :disabled="saving"
            @update:model-value="handleCategoryChange"
          >
            <SelectTrigger>
              <SelectValue placeholder="Category" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="cat in categories"
                :key="cat.id"
                :value="String(cat.id)"
              >
                {{ cat.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <!-- Deadline -->
        <div class="space-y-1.5">
          <Label>Deadline</Label>
          <Popover v-model:open="datePickerOpen">
            <PopoverTrigger as-child>
              <Button
                type="button"
                variant="outline"
                class="w-full justify-start text-left font-normal"
                :class="{ 'text-muted-foreground': !issue.deadline_at }"
                :disabled="saving"
              >
                <CalendarIcon class="size-4" />
                <span v-if="formattedDeadline">{{ formattedDeadline }}</span>
                <span v-else>Pick a date</span>
              </Button>
            </PopoverTrigger>
            <PopoverContent class="w-auto p-0" align="start">
              <Calendar
                :model-value="calendarValue"
                @update:model-value="handleDateSelect"
              />
            </PopoverContent>
          </Popover>
          <Button
            v-if="issue.deadline_at"
            type="button"
            variant="link"
            size="sm"
            class="h-auto p-0 text-xs text-muted-foreground"
            :disabled="saving"
            @click="handleClearDeadline"
          >
            Clear deadline
          </Button>
        </div>

        <!-- Visibility -->
        <div class="flex items-center justify-between">
          <div>
            <Label for="detail-visibility">Shared</Label>
            <p class="text-xs text-muted-foreground">
              Make this issue visible to others
            </p>
          </div>
          <Switch
            id="detail-visibility"
            :checked="issue.visibility === 'shared'"
            :disabled="saving"
            @update:checked="handleVisibilityChange"
          />
        </div>

        <!-- AI Summary section -->
        <div class="space-y-2 rounded-lg border border-border bg-muted/30 p-3">
          <div class="flex items-center gap-2 text-sm font-medium text-foreground">
            <SparklesIcon class="size-4" />
            AI Summary
          </div>

          <!-- Ready -->
          <template v-if="summaryState === 'ready'">
            <p class="text-sm leading-relaxed text-foreground">
              {{ issue.summary }}
            </p>
            <div
              v-if="issue.suggested_next_action"
              class="mt-2 rounded-md border border-border bg-background px-3 py-2"
            >
              <p class="text-xs font-medium text-muted-foreground">Suggested next action</p>
              <p class="mt-0.5 text-sm text-foreground">
                {{ issue.suggested_next_action }}
              </p>
            </div>
          </template>

          <!-- Processing / Pending -->
          <div
            v-else-if="summaryState === 'processing'"
            class="flex items-center gap-2 py-2 text-sm text-muted-foreground"
          >
            <Loader2Icon class="size-4 animate-spin" />
            <span>Generating summary…</span>
          </div>

          <!-- Failed -->
          <p
            v-else
            class="py-2 text-sm text-muted-foreground"
          >
            Summary unavailable
          </p>
        </div>

        <!-- Sharing placeholder -->
        <div class="rounded-lg border border-dashed border-border px-3 py-4 text-center text-sm text-muted-foreground">
          Sharing — coming soon
        </div>

        <!-- Comment thread placeholder (slot for 03.05) -->
        <div class="rounded-lg border border-dashed border-border px-3 py-4 text-center text-sm text-muted-foreground">
          Comments — coming soon
        </div>

        <!-- Meta info -->
        <div class="space-y-1 border-t border-border pt-3 text-xs text-muted-foreground">
          <p>Created by {{ issue.user.name }}</p>
          <p>Created {{ new Date(issue.created_at).toLocaleDateString() }}</p>
          <p>Last updated {{ new Date(issue.updated_at).toLocaleDateString() }}</p>
        </div>
      </div>

      <!-- Delete confirmation dialog -->
      <AlertDialog v-model:open="deleteDialogOpen">
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete issue</AlertDialogTitle>
            <AlertDialogDescription>
              This action cannot be undone. This will permanently delete the issue
              and all associated data.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel :disabled="deleting">Cancel</AlertDialogCancel>
            <AlertDialogAction
              class="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              :disabled="deleting"
              @click.prevent="handleDelete"
            >
              <Loader2Icon v-if="deleting" class="mr-2 size-4 animate-spin" />
              {{ deleting ? 'Deleting…' : 'Delete' }}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </SheetContent>
  </Sheet>
</template>
