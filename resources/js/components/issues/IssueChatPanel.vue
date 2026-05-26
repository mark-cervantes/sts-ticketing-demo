<script setup lang="ts">
/**
 * IssueChatPanel — self-contained AI chat UI for the issue detail sheet.
 *
 * Layout (top to bottom):
 *  1. Ephemeral warning banner (amber) — shown when !isSaved && messages.length > 0
 *  2. Saved indicator (green) — shown when isSaved
 *  3. Error inline message
 *  4. Messages area (max-h-64, scrollable) with sentinel for auto-scroll
 *  5. Streaming message placeholder
 *  6. ChatInput
 *  7. SavedConversationsList + Continue confirm dialog
 *  8. Save dialog (Dialog with optional title input)
 *
 * Architecture notes:
 * - The parent SheetContent already has overflow-y-auto (IssueDetailSheet.vue line 355).
 *   Do NOT add another overflow-y-auto here — that breaks auto-scroll on the sentinel.
 * - The message list has max-h-64 overflow-y-auto for a contained scroll area.
 *   The auto-scroll sentinel lives INSIDE the scrollable div so scrollIntoView works.
 */

import { ref, computed, watch, nextTick } from 'vue'
import type { Ref } from 'vue'
import { useIssueChat } from '@/composables/useIssueChat'
import ChatMessage from '@/components/issues/ChatMessage.vue'
import ChatInput from '@/components/issues/ChatInput.vue'
import SavedConversationsList from '@/components/issues/SavedConversationsList.vue'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
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
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { CheckIcon, TriangleAlertIcon, Loader2Icon } from '@lucide/vue'

// ── Props ──────────────────────────────────────────────────────────────────

interface Props {
  issueId: number | null
}

const props = defineProps<Props>()

// ── Composable ─────────────────────────────────────────────────────────────

// Wrap prop in a computed ref so useIssueChat receives a proper Ref<number|null>
const issueIdRef = computed(() => props.issueId) as Ref<number | null>

const {
  messages,
  isStreaming,
  streamingContent,
  isSaved,
  activeConversationId,
  savedConversations,
  error,
  send,
  save,
  loadConversations,
  continueConversation,
  clearSession,
} = useIssueChat(issueIdRef)

// ── Auto-scroll ────────────────────────────────────────────────────────────

const scrollAnchor = ref<HTMLDivElement | null>(null)

function scrollToBottom(): void {
  nextTick(() => {
    scrollAnchor.value?.scrollIntoView({ behavior: 'smooth' })
  })
}

watch(messages, scrollToBottom, { deep: true })
watch(streamingContent, scrollToBottom)

// ── Load conversations when issueId becomes valid ──────────────────────────

watch(
  () => props.issueId,
  (id) => {
    if (id !== null) {
      void loadConversations()
    }
  },
  { immediate: true },
)

// ── Save dialog ────────────────────────────────────────────────────────────

const saveDialogOpen = ref(false)
const saveTitle = ref('')
const saving = ref(false)

function openSaveDialog(): void {
  // Auto-suggest from first user message
  const firstUser = messages.value.find((m) => m.role === 'user')
  saveTitle.value = firstUser ? firstUser.content.slice(0, 60) : ''
  saveDialogOpen.value = true
}

async function confirmSave(): Promise<void> {
  saving.value = true
  try {
    await save(saveTitle.value.trim() || undefined)
    saveDialogOpen.value = false
  } finally {
    saving.value = false
  }
}

// ── Continue-conversation confirm dialog ───────────────────────────────────

const continueConfirmOpen = ref(false)
const pendingContinueId = ref<number | null>(null)

function requestContinue(id: number): void {
  if (messages.value.length > 0 && !isSaved.value) {
    // Warn — unsaved messages will be discarded
    pendingContinueId.value = id
    continueConfirmOpen.value = true
  } else {
    void continueConversation(id)
  }
}

async function confirmContinue(): Promise<void> {
  if (pendingContinueId.value !== null) {
    clearSession()
    await continueConversation(pendingContinueId.value)
    pendingContinueId.value = null
  }
  continueConfirmOpen.value = false
}

function cancelContinue(): void {
  pendingContinueId.value = null
  continueConfirmOpen.value = false
}

// ── Streaming message (live preview) ──────────────────────────────────────

const streamingMessage = computed(() =>
  streamingContent.value
    ? { role: 'assistant' as const, content: streamingContent.value }
    : null,
)
</script>

<template>
  <div class="space-y-3">
    <!-- ── Ephemeral warning banner ──────────────────────────────────────── -->
    <div
      v-if="!isSaved && messages.length > 0"
      class="flex items-center justify-between gap-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-200"
    >
      <div class="flex items-center gap-2">
        <TriangleAlertIcon class="size-4 shrink-0" />
        <span>This chat is not saved. It will be lost when you close the browser.</span>
      </div>
      <Button
        type="button"
        variant="outline"
        size="sm"
        class="h-7 shrink-0 border-amber-300 bg-transparent text-xs text-amber-800 hover:bg-amber-100 dark:border-amber-700 dark:text-amber-200 dark:hover:bg-amber-900/50"
        @click="openSaveDialog"
      >
        Save
      </Button>
    </div>

    <!-- ── Saved conversation indicator ─────────────────────────────────── -->
    <div
      v-if="isSaved"
      class="flex items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200"
    >
      <CheckIcon class="size-4 shrink-0" />
      <span>Conversation saved — further messages will auto-persist.</span>
    </div>

    <!-- ── Inline error message ──────────────────────────────────────────── -->
    <div
      v-if="error"
      class="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive"
    >
      {{ error }}
    </div>

    <!-- ── Messages area ─────────────────────────────────────────────────── -->
    <div
      v-if="messages.length > 0 || streamingMessage"
      class="max-h-64 overflow-y-auto space-y-3 rounded-lg border border-border bg-background/50 p-3"
    >
      <ChatMessage
        v-for="(msg, idx) in messages"
        :key="idx"
        :message="msg"
      />

      <!-- Live streaming message -->
      <ChatMessage
        v-if="streamingMessage"
        :message="streamingMessage"
        :streaming="true"
      />

      <!-- Auto-scroll sentinel -->
      <div ref="scrollAnchor" class="h-px" />
    </div>

    <!-- ── Empty state ───────────────────────────────────────────────────── -->
    <p
      v-else
      class="text-center text-xs text-muted-foreground"
    >
      Ask AI anything about this issue
    </p>

    <!-- ── Chat input ────────────────────────────────────────────────────── -->
    <ChatInput
      :disabled="isStreaming || !issueId"
      @send="send"
    />

    <!-- ── Saved conversations list ──────────────────────────────────────── -->
    <SavedConversationsList
      :conversations="savedConversations"
      @continue="requestContinue"
    />
  </div>

  <!-- ── Save dialog ───────────────────────────────────────────────────── -->
  <Dialog v-model:open="saveDialogOpen">
    <DialogContent class="sm:max-w-sm">
      <DialogHeader>
        <DialogTitle>Save conversation</DialogTitle>
        <DialogDescription>
          Give this conversation a title so you can find it later. The title is optional.
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-2">
        <Label for="save-title">Title (optional)</Label>
        <Input
          id="save-title"
          v-model="saveTitle"
          placeholder="e.g. Timeout investigation"
          maxlength="100"
          @keydown.enter.prevent="confirmSave"
        />
      </div>

      <DialogFooter>
        <Button
          type="button"
          variant="outline"
          :disabled="saving"
          @click="saveDialogOpen = false"
        >
          Cancel
        </Button>
        <Button
          type="button"
          :disabled="saving"
          @click="confirmSave"
        >
          <Loader2Icon v-if="saving" class="mr-2 size-4 animate-spin" />
          Save
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <!-- ── Continue confirm dialog (unsaved messages warning) ───────────────── -->
  <AlertDialog v-model:open="continueConfirmOpen">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Discard unsaved chat?</AlertDialogTitle>
        <AlertDialogDescription>
          You have unsaved messages. Loading a saved conversation will discard your current session.
          This cannot be undone.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel @click.prevent="cancelContinue">
          Keep current chat
        </AlertDialogCancel>
        <AlertDialogAction
          class="bg-destructive text-destructive-foreground hover:bg-destructive/90"
          @click.prevent="confirmContinue"
        >
          Discard &amp; continue
        </AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
