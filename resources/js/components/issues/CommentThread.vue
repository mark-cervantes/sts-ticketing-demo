<script setup lang="ts">
import { ref, nextTick, computed } from 'vue'
import type { IssueComment } from '@/types/issue'
import { apiPost } from '@/composables/useApiFetch'
import { useKanbanBoard } from '@/composables/useKanbanBoard'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { Loader2Icon, MessageSquareIcon } from '@lucide/vue'
import { toast } from 'vue-sonner'
import CommentReactions from '@/components/issues/CommentReactions.vue'

interface CommentThreadProps {
  comments: readonly IssueComment[] | IssueComment[]
  issueId: number
  canComment: boolean
  commentsCount?: number
  issueStatus: string
}

const props = defineProps<CommentThreadProps>()

const localComments = ref<IssueComment[]>([...props.comments])
const commentBody = ref('')
const submitting = ref(false)
const validationError = ref<string | null>(null)
const threadRef = ref<HTMLDivElement | null>(null)

const { updateIssueInBoard } = useKanbanBoard()

const trimmedBody = computed(() => commentBody.value.trim())

function formatRelativeTime(dateString: string): string {
  const date = new Date(dateString)
  const now = new Date()
  const diffMs = now.getTime() - date.getTime()
  const diffSeconds = Math.floor(diffMs / 1000)

  const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' })

  if (diffSeconds < 60) return rtf.format(-diffSeconds, 'second')
  const diffMinutes = Math.floor(diffSeconds / 60)
  if (diffMinutes < 60) return rtf.format(-diffMinutes, 'minute')
  const diffHours = Math.floor(diffMinutes / 60)
  if (diffHours < 24) return rtf.format(-diffHours, 'hour')
  const diffDays = Math.floor(diffHours / 24)
  if (diffDays < 30) return rtf.format(-diffDays, 'day')
  const diffMonths = Math.floor(diffDays / 30)
  if (diffMonths < 12) return rtf.format(-diffMonths, 'month')
  const diffYears = Math.floor(diffMonths / 12)
  return rtf.format(-diffYears, 'year')
}

function scrollToBottom(): void {
  nextTick(() => {
    if (threadRef.value) {
      threadRef.value.scrollTo({
        top: threadRef.value.scrollHeight,
        behavior: 'smooth',
      })
    }
  })
}

async function handleSubmit(): Promise<void> {
  validationError.value = null

  if (!trimmedBody.value) {
    validationError.value = 'Comment cannot be empty.'
    return
  }

  submitting.value = true

  try {
    const response = await apiPost(`/api/issues/${props.issueId}/comments`, {
      body: trimmedBody.value,
    })

    if (response.status === 422) {
      const errorData = await response.json() as { errors?: { body?: string[] } }
      validationError.value = errorData.errors?.body?.[0] ?? 'Validation error.'
      return
    }

    if (response.status === 403) {
      toast.error('Permission denied')
      return
    }

    if (response.status === 401) {
      toast.error('Session expired — please log in again.')
      return
    }

    if (!response.ok) {
      toast.error('Failed to add comment.')
      return
    }

    const responseData = await response.json() as { data: IssueComment }
    localComments.value.push(responseData.data)
    commentBody.value = ''

    // Sync comments_count on kanban card
    const newCount = (props.commentsCount ?? 0) + localComments.value.length - props.comments.length
    updateIssueInBoard(
      { id: props.issueId, comments_count: newCount } as Parameters<typeof updateIssueInBoard>[0],
    )

    scrollToBottom()
  } catch {
    toast.error('Network error — could not add comment.')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center gap-2 text-sm font-medium text-foreground">
      <MessageSquareIcon class="size-4" />
      Comments
    </div>

    <!-- Comment thread list -->
    <div
      v-if="localComments.length > 0"
      ref="threadRef"
      class="max-h-64 space-y-3 overflow-y-auto pr-1"
    >
      <div
        v-for="comment in localComments"
        :key="comment.id"
        class="space-y-1 rounded-md border border-border bg-muted/30 px-3 py-2"
      >
        <div class="flex items-baseline justify-between gap-2">
          <span class="text-sm font-medium text-foreground">
            {{ comment.user.name }}
          </span>
          <span class="shrink-0 text-xs text-muted-foreground">
            {{ formatRelativeTime(comment.created_at) }}
          </span>
        </div>
        <p class="whitespace-pre-wrap text-sm text-foreground">
          {{ comment.body }}
        </p>
        <CommentReactions
          :comment-id="comment.id"
          :initial-reactions="comment.reactions_summary"
        />
      </div>
    </div>

    <!-- Empty state -->
    <p
      v-else
      class="py-4 text-center text-sm text-muted-foreground"
    >
      No comments yet
    </p>

    <!-- Add comment input -->
    <div v-if="canComment" class="space-y-2">
      <Textarea
        v-model="commentBody"
        placeholder="Add a comment…"
        class="min-h-[60px] resize-y"
        :disabled="submitting"
        :class="{ 'border-destructive': validationError }"
        @keydown.meta.enter.prevent="handleSubmit"
        @keydown.ctrl.enter.prevent="handleSubmit"
        @input="validationError = null"
      />
      <p
        v-if="validationError"
        class="text-sm text-destructive"
      >
        {{ validationError }}
      </p>
      <div class="flex justify-end">
        <Button
          size="sm"
          :disabled="submitting || !trimmedBody"
          @click="handleSubmit"
        >
          <Loader2Icon v-if="submitting" class="mr-2 size-4 animate-spin" />
          {{ submitting ? 'Posting…' : 'Comment' }}
        </Button>
      </div>
    </div>

    <!-- Permission explanation when commenting is disabled -->
    <div v-else class="rounded-md border border-dashed border-border bg-muted/20 px-4 py-3 text-center">
      <p class="text-sm text-muted-foreground">
        You don't have permission to comment on this issue.
      </p>
      <p class="mt-1 text-xs text-muted-foreground/70">
        Ask the owner to share it with you.
      </p>
    </div>
  </div>
</template>
