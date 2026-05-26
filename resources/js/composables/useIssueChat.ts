/**
 * useIssueChat — instance-scoped composable for AI chat on an issue.
 *
 * Design notes:
 * - NOT a module-level singleton. Every call gets its own refs.
 *   (Contrast useKanbanBoard.ts which is module-scoped.)
 * - SSE via fetch() + ReadableStream, NOT EventSource (EventSource is GET-only).
 * - sessionStorage key: `ai-chat-${issueId}` — survives navigation/refresh but
 *   not tab close.
 * - When activeConversationId is non-null, send() routes to the /continue endpoint.
 *   The backend persists messages; we just append the final AI message locally.
 */

import { ref, watch } from 'vue'
import type { Ref } from 'vue'
import { useStorage } from '@vueuse/core'
import { apiPost, apiFetch, getCsrfToken } from '@/composables/useApiFetch'
import type { ChatMessage, SavedConversation } from '@/types/chat'

export function useIssueChat(issueId: Ref<number | null>) {
  // ── Instance state (NOT module-level) ──────────────────────────────────────
  const messages = ref<ChatMessage[]>([])
  const isStreaming = ref(false)
  const streamingContent = ref('')
  const isSaved = ref(false)
  const activeConversationId = ref<number | null>(null)
  const savedConversations = ref<SavedConversation[]>([])
  const error = ref<string | null>(null)

  // sessionStorage-backed ref for the current issue.
  // Key changes with issueId so each issue has its own slot.
  // We manage this manually (not useStorage) because the key is dynamic.
  function sessionKey(): string {
    return `ai-chat-${issueId.value ?? 'null'}`
  }

  function persistToSession(): void {
    if (isSaved.value) return // saved conversations load from DB
    try {
      sessionStorage.setItem(sessionKey(), JSON.stringify(messages.value))
    } catch {
      // sessionStorage full or unavailable — silently ignore
    }
  }

  function restoreFromSession(): void {
    try {
      const raw = sessionStorage.getItem(sessionKey())
      if (raw) {
        const parsed = JSON.parse(raw) as ChatMessage[]
        if (Array.isArray(parsed)) {
          messages.value = parsed
        }
      }
    } catch {
      messages.value = []
    }
  }

  function clearSession(): void {
    try {
      sessionStorage.removeItem(sessionKey())
    } catch {
      // ignore
    }
    messages.value = []
    isSaved.value = false
    activeConversationId.value = null
    streamingContent.value = ''
    isStreaming.value = false
    error.value = null
  }

  // Reset all state when the issueId changes (sheet opens a different issue).
  watch(
    issueId,
    (newId, oldId) => {
      if (newId === oldId) return

      // Reset all state
      messages.value = []
      isSaved.value = false
      activeConversationId.value = null
      streamingContent.value = ''
      isStreaming.value = false
      error.value = null
      savedConversations.value = []

      // Attempt restore from sessionStorage for the new issue
      if (newId !== null) {
        restoreFromSession()
      }
    },
    { immediate: true },
  )

  // ── SSE streaming helper ───────────────────────────────────────────────────

  async function consumeStream(response: Response): Promise<string> {
    const reader = response.body?.getReader()
    if (!reader) throw new Error('No response body')

    const decoder = new TextDecoder()
    let accumulated = ''
    let partial = ''

    // eslint-disable-next-line no-constant-condition
    while (true) {
      const { done, value } = await reader.read()
      if (done) break

      partial += decoder.decode(value, { stream: true })

      // Split on newlines; handle multi-chunk lines
      const lines = partial.split('\n')
      // Keep the last potentially-incomplete line for next iteration
      partial = lines.pop() ?? ''

      for (const line of lines) {
        const trimmed = line.trim()
        if (!trimmed.startsWith('data: ')) continue

        const payload = trimmed.slice('data: '.length)

        if (payload === '[DONE]') {
          return accumulated
        }

        try {
          const parsed = JSON.parse(payload) as { token?: string; error?: string; content?: string }
          if (parsed.error) {
            throw new Error(parsed.error)
          }
          // Backend sends {"token":"..."} (IssueChatController line 62)
          const token = parsed.token ?? parsed.content ?? ''
          accumulated += token
          streamingContent.value += token
        } catch (e) {
          if (payload === '[DONE]') return accumulated
          // non-JSON line — skip
        }
      }
    }

    // Handle any remaining partial data after stream closes
    if (partial.startsWith('data: ')) {
      const payload = partial.slice('data: '.length).trim()
      if (payload !== '[DONE]') {
        try {
          const parsed = JSON.parse(payload) as { token?: string }
          if (parsed.token) accumulated += parsed.token
        } catch {
          // ignore
        }
      }
    }

    return accumulated
  }

  // ── Send a message ─────────────────────────────────────────────────────────

  async function send(message: string): Promise<void> {
    if (!issueId.value || isStreaming.value || !message.trim()) return

    error.value = null
    isStreaming.value = true
    streamingContent.value = ''

    // Append user message optimistically
    const userMsg: ChatMessage = { role: 'user', content: message.trim() }
    messages.value = [...messages.value, userMsg]
    persistToSession()

    try {
      let response: Response

      if (activeConversationId.value !== null) {
        // ── Saved conversation mode: POST to /continue ──────────────────────
        response = await apiPost(
          `/api/issues/${issueId.value}/conversations/${activeConversationId.value}/continue`,
          { message: message.trim() },
        )
      } else {
        // ── Stateless mode: POST to /chat with full history ─────────────────
        const history = messages.value
          .slice(0, -1) // exclude the just-added user message
          .map((m) => ({ role: m.role, content: m.content }))

        response = await apiPost(`/api/issues/${issueId.value}/chat`, {
          message: message.trim(),
          history,
        })
      }

      // Check before reading body — 429/503 return JSON, not SSE
      if (!response.ok) {
        const json = (await response.json()) as { message?: string; retry_after?: number }
        const errMsg =
          response.status === 429
            ? `Rate limited. Try again in ${json.retry_after ?? '60'} seconds.`
            : response.status === 503
              ? 'AI is not configured. Contact your administrator.'
              : json.message ?? `Error ${response.status}`
        error.value = errMsg
        // Remove the optimistically added user message
        messages.value = messages.value.slice(0, -1)
        persistToSession()
        return
      }

      // Consume the SSE stream
      const finalContent = await consumeStream(response)

      if (finalContent) {
        const assistantMsg: ChatMessage = { role: 'assistant', content: finalContent }
        messages.value = [...messages.value, assistantMsg]
        persistToSession()
      }
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Network error. Please try again.'
      // Remove the optimistically added user message on hard failure
      messages.value = messages.value.slice(0, -1)
      persistToSession()
    } finally {
      isStreaming.value = false
      streamingContent.value = ''
    }
  }

  // ── Save conversation to DB ────────────────────────────────────────────────

  async function save(title?: string): Promise<SavedConversation | null> {
    if (!issueId.value || messages.value.length === 0) return null

    try {
      const response = await apiPost(`/api/issues/${issueId.value}/conversations`, {
        title: title ?? null,
        messages: messages.value.map((m) => ({ role: m.role, content: m.content })),
      })

      if (!response.ok) {
        const json = (await response.json()) as { message?: string }
        error.value = json.message ?? 'Failed to save conversation'
        return null
      }

      const json = (await response.json()) as { data: SavedConversation }
      const saved = json.data

      // Remove from sessionStorage — conversation is now persisted in DB
      try {
        sessionStorage.removeItem(sessionKey())
      } catch {
        // ignore
      }

      isSaved.value = true
      activeConversationId.value = saved.id
      // messages stay as-is — we don't need to reload from DB for the initial save

      // Refresh saved list
      await loadConversations()

      return saved
    } catch {
      error.value = 'Network error when saving conversation'
      return null
    }
  }

  // ── Load saved conversations list ──────────────────────────────────────────

  async function loadConversations(): Promise<void> {
    if (!issueId.value) return

    try {
      const data = await apiFetch<{ data: SavedConversation[] }>(
        `/api/issues/${issueId.value}/conversations`,
      )
      savedConversations.value = data.data
    } catch {
      // silently fail — not critical
    }
  }

  // ── Continue a saved conversation ──────────────────────────────────────────

  async function continueConversation(id: number): Promise<void> {
    if (!issueId.value) return

    try {
      const data = await apiFetch<{
        data: { messages: ChatMessage[] }
      }>(`/api/issues/${issueId.value}/conversations/${id}`)

      // Clear session and load DB messages
      sessionStorage.removeItem(sessionKey())
      messages.value = data.data.messages
      isSaved.value = true
      activeConversationId.value = id
      streamingContent.value = ''
      isStreaming.value = false
      error.value = null
    } catch {
      error.value = 'Failed to load conversation'
    }
  }

  return {
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
  }
}
