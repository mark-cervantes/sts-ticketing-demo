<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { toast } from 'vue-sonner'
import {
  CircleDotIcon,
  PlusIcon,
  Trash2Icon,
  Loader2Icon,
  CheckIcon,
  ChevronUpIcon,
  ChevronDownIcon,
  PencilIcon,
  XIcon,
  StarIcon,
} from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
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
import { apiFetch, apiPost, apiDelete, apiPut } from '@/composables/useApiFetch'
import type { IssueStatus } from '@/types/issue'

defineOptions({ layout: AppLayout })

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

const statuses = ref<IssueStatus[]>([])
const isLoading = ref(false)

// Create form
const newName = ref('')
const newColor = ref('#6366f1')
const nameError = ref<string | null>(null)
const isCreating = ref(false)

// Inline rename
const editingId = ref<number | null>(null)
const editingName = ref('')
const editingColor = ref('')
const isSavingEdit = ref(false)

// Per-status error messages (e.g. 409 "has issues")
const deleteErrors = ref<Record<number, string>>({})
// Track which IDs are currently being deleted / reordering
const deletingIds = ref<Set<number>>(new Set())
const reorderingIds = ref<Set<number>>(new Set())
const settingDefaultId = ref<number | null>(null)

// Predefined color swatches for quick selection
const COLOR_SWATCHES = [
  '#6366f1', // indigo
  '#22c55e', // green
  '#f59e0b', // amber
  '#ef4444', // red
  '#8b5cf6', // violet
  '#06b6d4', // cyan
  '#ec4899', // pink
  '#64748b', // slate
  '#f97316', // orange
  '#14b8a6', // teal
]

// ---------------------------------------------------------------------------
// Load
// ---------------------------------------------------------------------------

async function fetchStatuses(): Promise<void> {
  isLoading.value = true
  try {
    statuses.value = await apiFetch<IssueStatus[]>('/api/statuses')
  } catch {
    toast.error('Failed to load statuses')
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  void fetchStatuses()
})

// ---------------------------------------------------------------------------
// Create
// ---------------------------------------------------------------------------

async function handleCreate(): Promise<void> {
  const name = newName.value.trim()
  if (!name) {
    nameError.value = 'Status name is required.'
    return
  }

  nameError.value = null
  isCreating.value = true

  try {
    const response = await apiPost('/api/statuses', {
      name,
      color: newColor.value,
    })

    if (response.status === 422) {
      const body = (await response.json()) as { errors?: Record<string, string[]>; message?: string }
      nameError.value = body.errors?.name?.[0] ?? body.message ?? 'Validation error.'
      return
    }

    if (!response.ok) {
      toast.error('Failed to create status')
      return
    }

    const created = (await response.json()) as IssueStatus
    statuses.value.push(created)
    newName.value = ''
    newColor.value = '#6366f1'
    toast.success(`Status "${created.name}" created`)
  } catch {
    toast.error('Failed to create status')
  } finally {
    isCreating.value = false
  }
}

function clearNameError(): void {
  if (nameError.value) nameError.value = null
}

// ---------------------------------------------------------------------------
// Inline rename
// ---------------------------------------------------------------------------

function startEdit(status: IssueStatus): void {
  editingId.value = status.id
  editingName.value = status.name
  editingColor.value = status.color
}

function cancelEdit(): void {
  editingId.value = null
  editingName.value = ''
  editingColor.value = ''
}

async function commitEdit(status: IssueStatus): Promise<void> {
  const name = editingName.value.trim()
  if (!name) return

  isSavingEdit.value = true
  try {
    const response = await apiPut(`/api/statuses/${status.id}`, {
      name,
      color: editingColor.value,
      sort_order: status.sort_order,
    })

    if (response.status === 422) {
      const body = (await response.json()) as { errors?: Record<string, string[]>; message?: string }
      toast.error(body.errors?.name?.[0] ?? body.message ?? 'Validation error.')
      return
    }

    if (!response.ok) {
      toast.error(`Failed to update "${status.name}"`)
      return
    }

    const updated = (await response.json()) as IssueStatus
    const idx = statuses.value.findIndex((s) => s.id === status.id)
    if (idx !== -1) {
      statuses.value.splice(idx, 1, updated)
    }
    cancelEdit()
    toast.success(`Status renamed to "${updated.name}"`)
  } catch {
    toast.error('Failed to update status')
  } finally {
    isSavingEdit.value = false
  }
}

// ---------------------------------------------------------------------------
// Set default
// ---------------------------------------------------------------------------

async function handleSetDefault(status: IssueStatus): Promise<void> {
  if (status.is_default || settingDefaultId.value !== null) return
  settingDefaultId.value = status.id

  try {
    const response = await apiPut(`/api/statuses/${status.id}`, {
      name: status.name,
      color: status.color,
      sort_order: status.sort_order,
      is_default: true,
    })

    if (!response.ok) {
      toast.error(`Failed to set "${status.name}" as default`)
      return
    }

    // Refresh to get updated is_default across all statuses
    await fetchStatuses()
    toast.success(`"${status.name}" is now the default status`)
  } catch {
    toast.error('Failed to update default status')
  } finally {
    settingDefaultId.value = null
  }
}

// ---------------------------------------------------------------------------
// Reorder (up/down)
// ---------------------------------------------------------------------------

async function handleReorder(status: IssueStatus, direction: 'up' | 'down'): Promise<void> {
  if (reorderingIds.value.has(status.id)) return

  const sorted = [...statuses.value].sort((a, b) => a.sort_order - b.sort_order)
  const idx = sorted.findIndex((s) => s.id === status.id)

  const swapIdx = direction === 'up' ? idx - 1 : idx + 1
  if (swapIdx < 0 || swapIdx >= sorted.length) return

  const other = sorted[swapIdx]
  reorderingIds.value = new Set(reorderingIds.value).add(status.id)

  try {
    // Swap sort_order values
    await Promise.all([
      apiPut(`/api/statuses/${status.id}`, {
        name: status.name,
        color: status.color,
        sort_order: other.sort_order,
      }),
      apiPut(`/api/statuses/${other.id}`, {
        name: other.name,
        color: other.color,
        sort_order: status.sort_order,
      }),
    ])

    // Update local state
    const aIdx = statuses.value.findIndex((s) => s.id === status.id)
    const bIdx = statuses.value.findIndex((s) => s.id === other.id)
    if (aIdx !== -1 && bIdx !== -1) {
      statuses.value[aIdx] = { ...statuses.value[aIdx], sort_order: other.sort_order }
      statuses.value[bIdx] = { ...statuses.value[bIdx], sort_order: status.sort_order }
      // Re-sort the array
      statuses.value = [...statuses.value].sort((a, b) => a.sort_order - b.sort_order)
    }
  } catch {
    toast.error('Failed to reorder statuses')
  } finally {
    const next = new Set(reorderingIds.value)
    next.delete(status.id)
    reorderingIds.value = next
  }
}

// ---------------------------------------------------------------------------
// Delete
// ---------------------------------------------------------------------------

async function handleDelete(status: IssueStatus): Promise<void> {
  if (deletingIds.value.has(status.id)) return

  // Clear any prior inline error
  const errors = { ...deleteErrors.value }
  delete errors[status.id]
  deleteErrors.value = errors

  deletingIds.value = new Set(deletingIds.value).add(status.id)

  try {
    const response = await apiDelete(`/api/statuses/${status.id}`)

    if (response.status === 409) {
      const body = (await response.json()) as { message?: string; count?: number }
      const count = body.count ?? '?'
      deleteErrors.value = {
        ...deleteErrors.value,
        [status.id]: body.message ?? `Cannot delete — ${count} issue${count === 1 ? '' : 's'} use this status`,
      }
      return
    }

    if (!response.ok) {
      toast.error(`Failed to delete "${status.name}"`)
      return
    }

    statuses.value = statuses.value.filter((s) => s.id !== status.id)
    toast.success(`Status "${status.name}" deleted`)
  } catch {
    toast.error(`Failed to delete "${status.name}"`)
  } finally {
    const next = new Set(deletingIds.value)
    next.delete(status.id)
    deletingIds.value = next
  }
}
</script>

<template>
  <div class="mx-auto max-w-2xl px-4 py-8">
    <!-- Page header -->
    <div class="mb-6 flex items-center gap-3">
      <div class="flex size-10 items-center justify-center rounded-lg bg-primary/10">
        <CircleDotIcon class="size-5 text-primary" />
      </div>
      <div>
        <h1 class="text-xl font-semibold text-foreground">Status Management</h1>
        <p class="text-sm text-muted-foreground">
          Configure workflow statuses for your Kanban board
        </p>
      </div>
    </div>

    <!-- Create form -->
    <div class="mb-6 rounded-lg border border-border bg-card p-4">
      <h2 class="mb-3 text-sm font-semibold text-foreground">Add new status</h2>
      <div class="space-y-3">
        <div class="flex gap-2">
          <Input
            v-model="newName"
            placeholder="Status name…"
            class="flex-1"
            :disabled="isCreating"
            @input="clearNameError"
            @keydown.enter.prevent="handleCreate"
          />
          <Button :disabled="isCreating" @click="handleCreate">
            <Loader2Icon v-if="isCreating" class="size-4 animate-spin" />
            <PlusIcon v-else class="size-4" />
            <span class="ml-1.5">Add</span>
          </Button>
        </div>

        <!-- Color picker -->
        <div>
          <p class="mb-1.5 text-xs text-muted-foreground">Color</p>
          <div class="flex items-center gap-2">
            <!-- Swatches -->
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="swatch in COLOR_SWATCHES"
                :key="swatch"
                type="button"
                class="size-6 rounded-full border-2 transition-all hover:scale-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :style="{ backgroundColor: swatch }"
                :class="newColor === swatch ? 'border-foreground' : 'border-transparent'"
                :aria-label="`Select color ${swatch}`"
                @click="newColor = swatch"
              >
                <CheckIcon
                  v-if="newColor === swatch"
                  class="size-3 mx-auto text-white drop-shadow"
                />
              </button>
            </div>
            <!-- Hex input -->
            <input
              v-model="newColor"
              type="color"
              class="size-7 cursor-pointer rounded border border-input bg-transparent p-0.5"
              aria-label="Custom color"
            />
          </div>
        </div>

        <p v-if="nameError" class="text-sm text-destructive">{{ nameError }}</p>
      </div>
    </div>

    <!-- Status list -->
    <div>
      <!-- Skeleton while loading -->
      <div v-if="isLoading" class="space-y-2">
        <Skeleton v-for="n in 3" :key="n" class="h-14 w-full rounded-md" />
      </div>

      <!-- Empty state -->
      <p v-else-if="statuses.length === 0" class="text-sm text-muted-foreground">
        No statuses yet. Create one above.
      </p>

      <!-- Status rows sorted by sort_order -->
      <ul v-else class="space-y-2">
        <li
          v-for="(status, index) in [...statuses].sort((a, b) => a.sort_order - b.sort_order)"
          :key="status.id"
          class="flex flex-col gap-1"
        >
          <!-- Editing row -->
          <div
            v-if="editingId === status.id"
            class="rounded-md border border-primary bg-card px-3 py-2"
          >
            <div class="flex items-center gap-2">
              <div class="flex flex-wrap gap-1.5 flex-1">
                <Input
                  v-model="editingName"
                  class="h-8 flex-1 min-w-[140px] text-sm"
                  :disabled="isSavingEdit"
                  @keydown.enter.prevent="commitEdit(status)"
                  @keydown.escape.prevent="cancelEdit"
                />
                <!-- Color swatches inline -->
                <div class="flex items-center gap-1">
                  <button
                    v-for="swatch in COLOR_SWATCHES"
                    :key="swatch"
                    type="button"
                    class="size-5 rounded-full border-2 transition-all hover:scale-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :style="{ backgroundColor: swatch }"
                    :class="editingColor === swatch ? 'border-foreground' : 'border-transparent'"
                    :aria-label="`Select color ${swatch}`"
                    @click="editingColor = swatch"
                  />
                  <input
                    v-model="editingColor"
                    type="color"
                    class="size-6 cursor-pointer rounded border border-input bg-transparent p-0.5"
                    aria-label="Custom color"
                  />
                </div>
              </div>
              <div class="flex shrink-0 items-center gap-1">
                <Button
                  size="icon"
                  class="size-7"
                  :disabled="isSavingEdit"
                  @click="commitEdit(status)"
                >
                  <Loader2Icon v-if="isSavingEdit" class="size-3.5 animate-spin" />
                  <CheckIcon v-else class="size-3.5" />
                </Button>
                <Button
                  variant="ghost"
                  size="icon"
                  class="size-7"
                  :disabled="isSavingEdit"
                  @click="cancelEdit"
                >
                  <XIcon class="size-3.5" />
                </Button>
              </div>
            </div>
          </div>

          <!-- Normal row -->
          <div
            v-else
            class="flex items-center justify-between rounded-md border border-border bg-card px-3 py-2"
          >
            <div class="flex items-center gap-2.5 min-w-0">
              <!-- Color dot -->
              <span
                class="size-3 rounded-full shrink-0"
                :style="{ backgroundColor: status.color }"
              />
              <!-- Name + slug + default badge -->
              <div class="min-w-0">
                <div class="flex items-center gap-1.5">
                  <span class="text-sm font-medium text-foreground truncate">{{ status.name }}</span>
                  <Badge v-if="status.is_default" variant="secondary" class="text-[10px] px-1 py-0 h-4">
                    <StarIcon class="size-2.5 mr-0.5" />
                    Default
                  </Badge>
                </div>
                <span class="text-xs text-muted-foreground font-mono">{{ status.slug }}</span>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex shrink-0 items-center gap-0.5 ml-2">
              <!-- Reorder up -->
              <Button
                variant="ghost"
                size="icon"
                class="size-7 text-muted-foreground"
                :disabled="index === 0 || reorderingIds.has(status.id)"
                :aria-label="`Move ${status.name} up`"
                @click="handleReorder(status, 'up')"
              >
                <ChevronUpIcon class="size-3.5" />
              </Button>

              <!-- Reorder down -->
              <Button
                variant="ghost"
                size="icon"
                class="size-7 text-muted-foreground"
                :disabled="index === statuses.length - 1 || reorderingIds.has(status.id)"
                :aria-label="`Move ${status.name} down`"
                @click="handleReorder(status, 'down')"
              >
                <ChevronDownIcon class="size-3.5" />
              </Button>

              <!-- Set as default -->
              <Button
                v-if="!status.is_default"
                variant="ghost"
                size="icon"
                class="size-7 text-muted-foreground hover:text-amber-500"
                :disabled="settingDefaultId !== null"
                :aria-label="`Set ${status.name} as default`"
                @click="handleSetDefault(status)"
              >
                <Loader2Icon v-if="settingDefaultId === status.id" class="size-3.5 animate-spin" />
                <StarIcon v-else class="size-3.5" />
              </Button>

              <!-- Edit (rename + color) -->
              <Button
                variant="ghost"
                size="icon"
                class="size-7 text-muted-foreground"
                :aria-label="`Edit ${status.name}`"
                @click="startEdit(status)"
              >
                <PencilIcon class="size-3.5" />
              </Button>

              <!-- Delete — wrapped in AlertDialog for confirmation -->
              <AlertDialog>
                <AlertDialogTrigger as-child>
                  <Button
                    variant="ghost"
                    size="icon"
                    class="size-7 text-muted-foreground hover:text-destructive"
                    :disabled="deletingIds.has(status.id) || status.is_default"
                    :aria-label="`Delete status ${status.name}`"
                    :title="status.is_default ? 'Cannot delete the default status' : undefined"
                  >
                    <Loader2Icon v-if="deletingIds.has(status.id)" class="size-3.5 animate-spin" />
                    <Trash2Icon v-else class="size-3.5" />
                  </Button>
                </AlertDialogTrigger>

                <AlertDialogContent>
                  <AlertDialogHeader>
                    <AlertDialogTitle>Delete "{{ status.name }}"?</AlertDialogTitle>
                    <AlertDialogDescription>
                      This action cannot be undone. If any issues use this status the delete will be blocked.
                    </AlertDialogDescription>
                  </AlertDialogHeader>
                  <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction
                      class="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                      @click="handleDelete(status)"
                    >
                      Delete
                    </AlertDialogAction>
                  </AlertDialogFooter>
                </AlertDialogContent>
              </AlertDialog>
            </div>
          </div>

          <!-- Inline 409 error -->
          <p
            v-if="deleteErrors[status.id]"
            class="pl-1 text-xs text-destructive"
          >
            {{ deleteErrors[status.id] }}
          </p>
        </li>
      </ul>
    </div>
  </div>
</template>
