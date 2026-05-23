<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { usePage } from '@inertiajs/vue3'
import type { SharePermission } from '@/types/issue'
import { useIssueShares } from '@/composables/useIssueShares'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
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
import { Skeleton } from '@/components/ui/skeleton'
import { Loader2Icon, XIcon, UsersIcon } from '@lucide/vue'

/** Minimal shape — compatible with both Issue and DeepReadonly<Issue>. */
interface ShareSectionProps {
  issue: {
    id: number
    user_id: number
  }
}

const props = defineProps<ShareSectionProps>()

const page = usePage()

const {
  shares,
  loading,
  saving,
  fetchShares,
  addShare,
  updatePermission,
  removeShare,
} = useIssueShares()

// Owner gate
const isOwner = computed(
  () => page.props.auth.user.id === props.issue.user_id,
)

// Add share form state
const newEmail = ref('')
const newPermission = ref<SharePermission>('view')
const emailErrors = ref<string[]>([])
const adding = ref(false)

// Delete confirmation
const deleteDialogOpen = ref(false)
const deleteShareId = ref<number | null>(null)
const deleteShareName = ref('')

const permissionOptions: { value: SharePermission; label: string }[] = [
  { value: 'view', label: 'Can view' },
  { value: 'comment', label: 'Can comment' },
  { value: 'edit', label: 'Can edit' },
]

onMounted(() => {
  if (isOwner.value) {
    void fetchShares(props.issue.id)
  }
})

async function handleAddShare(): Promise<void> {
  const trimmed = newEmail.value.trim()
  if (!trimmed) {
    emailErrors.value = ['Email is required.']
    return
  }

  emailErrors.value = []
  adding.value = true

  const errors = await addShare(props.issue.id, trimmed, newPermission.value)

  if (errors) {
    emailErrors.value = errors.email ?? ['An error occurred.']
  } else {
    // Success — reset form
    newEmail.value = ''
    newPermission.value = 'view'
    emailErrors.value = []
  }

  adding.value = false
}

function handlePermissionChange(shareId: number, value: unknown): void {
  if (typeof value !== 'string') return
  void updatePermission(shareId, value as SharePermission)
}

function confirmRemoveShare(shareId: number, userName: string): void {
  deleteShareId.value = shareId
  deleteShareName.value = userName
  deleteDialogOpen.value = true
}

async function handleRemoveShare(): Promise<void> {
  if (deleteShareId.value === null) return
  const success = await removeShare(deleteShareId.value)
  if (success) {
    deleteDialogOpen.value = false
    deleteShareId.value = null
    deleteShareName.value = ''
  }
}
</script>

<template>
  <div v-if="isOwner" class="space-y-3">
    <div class="flex items-center gap-2 text-sm font-medium text-foreground">
      <UsersIcon class="size-4" />
      Sharing
    </div>

    <!-- Loading skeleton -->
    <div v-if="loading" class="space-y-2">
      <Skeleton class="h-8 w-full" />
      <Skeleton class="h-8 w-3/4" />
    </div>

    <template v-else>
      <!-- Empty state -->
      <p
        v-if="shares.length === 0"
        class="py-2 text-center text-sm text-muted-foreground"
      >
        No shares yet — add someone to collaborate
      </p>

      <!-- Share list -->
      <div v-else class="space-y-2">
        <div
          v-for="share in shares"
          :key="share.id"
          class="flex items-center gap-2 rounded-md border border-border px-3 py-2"
        >
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-foreground">
              {{ share.user.name }}
            </p>
            <p class="truncate text-xs text-muted-foreground">
              {{ share.user.email }}
            </p>
          </div>

          <!-- Permission select -->
          <Select
            :model-value="share.permission"
            :disabled="saving"
            @update:model-value="(v: unknown) => handlePermissionChange(share.id, v)"
          >
            <SelectTrigger class="h-7 w-[110px] text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="opt in permissionOptions"
                :key="opt.value"
                :value="opt.value"
              >
                {{ opt.label }}
              </SelectItem>
            </SelectContent>
          </Select>

          <!-- Remove button -->
          <Button
            variant="ghost"
            size="icon-sm"
            class="shrink-0 text-muted-foreground hover:text-destructive"
            :disabled="saving"
            @click="confirmRemoveShare(share.id, share.user.name)"
          >
            <XIcon class="size-4" />
            <span class="sr-only">Remove share for {{ share.user.name }}</span>
          </Button>
        </div>
      </div>

      <!-- Add share form -->
      <div class="space-y-2 rounded-md border border-border p-3">
        <div class="flex items-end gap-2">
          <div class="min-w-0 flex-1 space-y-1">
            <Input
              v-model="newEmail"
              type="email"
              placeholder="Email address"
              :disabled="adding || saving"
              :class="{ 'border-destructive': emailErrors.length > 0 }"
              @keydown.enter.prevent="handleAddShare"
            />
            <p
              v-for="(err, idx) in emailErrors"
              :key="idx"
              class="text-xs text-destructive"
            >
              {{ err }}
            </p>
          </div>

          <Select
            :model-value="newPermission"
            :disabled="adding || saving"
            @update:model-value="(v: unknown) => { if (typeof v === 'string') newPermission = v as SharePermission }"
          >
            <SelectTrigger class="h-9 w-[110px] text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="opt in permissionOptions"
                :key="opt.value"
                :value="opt.value"
              >
                {{ opt.label }}
              </SelectItem>
            </SelectContent>
          </Select>

          <Button
            size="sm"
            :disabled="adding || saving || !newEmail.trim()"
            @click="handleAddShare"
          >
            <Loader2Icon v-if="adding" class="mr-1 size-3 animate-spin" />
            Add
          </Button>
        </div>
      </div>
    </template>

    <!-- Delete share confirmation dialog -->
    <AlertDialog v-model:open="deleteDialogOpen">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Remove share</AlertDialogTitle>
          <AlertDialogDescription>
            Remove {{ deleteShareName }}'s access to this issue? They will no
            longer be able to view or interact with it.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel :disabled="saving">Cancel</AlertDialogCancel>
          <AlertDialogAction
            class="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            :disabled="saving"
            @click.prevent="handleRemoveShare"
          >
            <Loader2Icon v-if="saving" class="mr-2 size-4 animate-spin" />
            {{ saving ? 'Removing…' : 'Remove' }}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  </div>
</template>
