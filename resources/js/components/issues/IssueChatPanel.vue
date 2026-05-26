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

import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import type { Ref } from 'vue'
import { useIssueChat } from '@/composables/useIssueChat'
import ChatMessage from '@/components/issues/ChatMessage.vue'
import ChatInput from '@/components/issues/ChatInput.vue'
import SuggestionChips from '@/components/issues/SuggestionChips.vue'
import SavedConversationsList from '@/components/issues/SavedConversationsList.vue'
import CreateIssueDialog from '@/components/issues/CreateIssueDialog.vue'
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
import type { Priority } from '@/types'

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
  suggestionChips,
  send,
  save,
  loadConversations,
  continueConversation,
  clearSession,
  loadSuggestionChips,
  confirmTool,
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

// ── Load conversations + chips when issueId becomes valid ──────────────────

watch(
  () => props.issueId,
  (id) => {
    if (id !== null) {
      void loadConversations()
    }
  },
  { immediate: true },
)

// ── Placeholder rotation ───────────────────────────────────────────────────

const PLACEHOLDERS = [
  'Ask about this issue...',
  'Try: create a ticket for this',
  'Ask anything about this issue...',
]
const currentPlaceholder = ref(PLACEHOLDERS[0])
const isInputFocused = ref(false)
let placeholderInterval: ReturnType<typeof setInterval> | null = null

function startPlaceholderRotation(): void {
  let idx = 0
  placeholderInterval = setInterval(() => {
    // Stop if messages exist or input is focused
    if (messages.value.length > 0 || isInputFocused.value) return
    idx = (idx + 1) % PLACEHOLDERS.length
    currentPlaceholder.value = PLACEHOLDERS[idx]
  }, 8000)
}

function stopPlaceholderRotation(): void {
  if (placeholderInterval !== null) {
    clearInterval(placeholderInterval)
    placeholderInterval = null
  }
}

// ── First-time onboarding tooltip ─────────────────────────────────────────

const ONBOARDED_KEY = 'ai-chat-onboarded'
const showOnboardingTooltip = ref(false)
let tooltipTimeout: ReturnType<typeof setTimeout> | null = null

function dismissTooltip(): void {
  showOnboardingTooltip.value = false
  if (tooltipTimeout !== null) {
    clearTimeout(tooltipTimeout)
    tooltipTimeout = null
  }
  try {
    localStorage.setItem(ONBOARDED_KEY, 'true')
  } catch {
    // localStorage unavailable — ignore
  }
}

// ── Lifecycle ──────────────────────────────────────────────────────────────

onMounted(() => {
  // Load suggestion chips (cached after first call)
  void loadSuggestionChips()

  // Start placeholder rotation
  startPlaceholderRotation()

  // Show first-time tooltip if not yet onboarded
  try {
    if (!localStorage.getItem(ONBOARDED_KEY)) {
      showOnboardingTooltip.value = true
      tooltipTimeout = setTimeout(dismissTooltip, 8000)
    }
  } catch {
    // localStorage unavailable — skip tooltip
  }
})

onUnmounted(() => {
  stopPlaceholderRotation()
  if (tooltipTimeout !== null) {
    clearTimeout(tooltipTimeout)
    tooltipTimeout = null
  }
})

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

// ── Tool call handling ─────────────────────────────────────────────────────

async function handleToolConfirm(
  _messageIndex: number,
  tool: string,
  args: Record<string, unknown>,
): Promise<void> {
  // Find the actual index of the tool_call message in the messages array
  const idx = messages.value.findIndex(
    (m) => m.role === 'tool_call' && m.toolCall?.tool === tool && !m.toolResult,
  )
  if (idx !== -1) {
    await confirmTool(idx, tool, args)
  }
}

// ── Edit & Create (opens CreateIssueDialog with prefill) ──────────────────

const createDialogOpen = ref(false)
const createDialogPrefill = ref<{
  title?: string
  description?: string
  priority?: Priority
  category_id?: number | null
} | undefined>(undefined)

function handleToolEditAndCreate(prefill: {
  title?: string
  description?: string
  priority?: Priority
  category_id?: number | null
}): void {
  // Set prefill BEFORE open to avoid race with the watcher in CreateIssueDialog
  createDialogPrefill.value = prefill
  createDialogOpen.value = true
}

// ── Chip select ────────────────────────────────────────────────────────────

function handleChipSelect(text: string): void {
  void send(text)
}
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
      class="max-h-64 space-y-3 overflow-y-auto rounded-lg border border-border bg-background/50 p-3"
    >
      <ChatMessage
        v-for="(msg, idx) in messages"
        :key="idx"
        :message="msg"
        :issue-id="issueId ?? 0"
        @tool-confirm="handleToolConfirm"
        @tool-edit-and-create="handleToolEditAndCreate"
      />

      <!-- Live streaming message -->
      <ChatMessage
        v-if="streamingMessage"
        :message="streamingMessage"
        :streaming="true"
      />

      <!-- Auto-scroll sentinel -->
      <div
        ref="scrollAnchor"
        class="h-px"
      />
    </div>

    <!-- ── Empty state — suggestion chips ────────────────────────────────── -->
    <div
      v-else
      class="space-y-3"
    >
      <SuggestionChips
        :chips="suggestionChips"
        @select="handleChipSelect"
      />
    </div>

    <!-- ── Chat input (with first-time tooltip) ──────────────────────────── -->
    <div class="relative">
      <!-- First-time onboarding tooltip -->
      <Transition
        enter-active-class="transition-all duration-300 ease-out"
        enter-from-class="opacity-0 translate-y-1"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition-all duration-200 ease-in"
        leave-from-class="opacity-100 translate-y-0"
        leave-to-class="opacity-0 translate-y-1"
      >
        <div
          v-if="showOnboardingTooltip"
          class="absolute bottom-full left-0 right-0 z-10 mb-2 cursor-pointer rounded-lg border border-border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md"
          @click="dismissTooltip"
        >
          💡 You can ask the AI to create tickets and more. Just describe what you need.
          <div class="absolute -bottom-1.5 left-4 size-3 rotate-45 border-b border-r border-border bg-popover" />
        </div>
      </Transition>

      <ChatInput
        :disabled="isStreaming || !issueId"
        :placeholder="currentPlaceholder"
        @send="send"
        @focus="isInputFocused = true"
        @blur="isInputFocused = false"
      />
    </div>

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
          <Loader2Icon
            v-if="saving"
            class="mr-2 size-4 animate-spin"
          />
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

  <!-- ── Create Issue Dialog (Edit & Create from tool call) ──────────────── -->
  <CreateIssueDialog
    :open="createDialogOpen"
    :prefill="createDialogPrefill"
    @update:open="createDialogOpen = $event"
    @created="createDialogOpen = false"
  />
</template>
