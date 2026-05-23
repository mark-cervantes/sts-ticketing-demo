import { ref, watch, onUnmounted, type Ref } from 'vue'
import type { SummaryStatus } from '@/types/issue'
import { apiFetch } from '@/composables/useApiFetch'

/**
 * SSE payload emitted by the backend on summary.ready.
 */
interface SummaryReadyPayload {
  summary_status: 'ready'
  summary: string | null
  suggested_next_action: string | null
}

/**
 * SSE payload emitted by the backend on summary.failed.
 */
interface SummaryFailedPayload {
  summary_status: 'failed'
}

type SummaryPayload = SummaryReadyPayload | SummaryFailedPayload

/**
 * Return shape from useSummaryStream.
 */
export interface SummaryStreamResult {
  /** Latest summary_status received from the stream, or null if none yet. */
  status: Ref<SummaryStatus | null>
  /** Summary text received from the stream. null until 'ready'. */
  summary: Ref<string | null>
  /** Suggested next action received from the stream. null until 'ready'. */
  suggestedNextAction: Ref<string | null>
  /** True when SSE connection has errored and fallback polling is active. */
  connectionError: Ref<boolean>
}

/**
 * Composable that opens a Server-Sent Events connection to
 * GET /api/issues/{id}/stream and reacts to summary status transitions.
 *
 * Lifecycle:
 *  - Opens when `enabled` is true and `issueId` is non-null.
 *  - Closes on `enabled` becoming false, `issueId` changing, or unmount.
 *  - Falls back to polling GET /api/issues/{id} every 10 s after 3 SSE errors.
 *
 * @param issueId  Reactive issue ID; null means no connection.
 * @param enabled  When false, no connection is opened / existing one closes.
 * @param onUpdate Callback invoked with the parsed payload on each terminal event.
 *
 * @see SRS §FR-12 / task 03.06.00
 */
export function useSummaryStream(
  issueId: Ref<number | null>,
  enabled: Ref<boolean>,
  onUpdate: (payload: SummaryPayload) => void,
): SummaryStreamResult {
  const status = ref<SummaryStatus | null>(null)
  const summary = ref<string | null>(null)
  const suggestedNextAction = ref<string | null>(null)
  const connectionError = ref(false)

  let eventSource: EventSource | null = null
  let pollTimer: ReturnType<typeof setInterval> | null = null
  let errorCount = 0
  const MAX_SSE_ERRORS = 3
  const POLL_INTERVAL_MS = 10_000

  function closeEventSource(): void {
    if (eventSource !== null) {
      eventSource.close()
      eventSource = null
    }
  }

  function stopPolling(): void {
    if (pollTimer !== null) {
      clearInterval(pollTimer)
      pollTimer = null
    }
  }

  function disconnect(): void {
    closeEventSource()
    stopPolling()
    errorCount = 0
    connectionError.value = false
  }

  function startPolling(id: number): void {
    connectionError.value = true
    stopPolling()

    pollTimer = setInterval(async () => {
      try {
        const response = await apiFetch<{ data: { summary_status: SummaryStatus; summary: string | null; suggested_next_action: string | null } }>(
          `/api/issues/${id}`,
        )
        const s = response.data.summary_status
        status.value = s

        if (s === 'ready') {
          summary.value = response.data.summary
          suggestedNextAction.value = response.data.suggested_next_action
          onUpdate({
            summary_status: 'ready',
            summary: response.data.summary,
            suggested_next_action: response.data.suggested_next_action,
          })
          stopPolling()
        } else if (s === 'failed') {
          onUpdate({ summary_status: 'failed' })
          stopPolling()
        }
      } catch {
        // Network error during poll — keep retrying.
      }
    }, POLL_INTERVAL_MS)
  }

  function connect(id: number): void {
    closeEventSource()
    errorCount = 0

    // EventSource uses cookies for session auth automatically (same-origin).
    const source = new EventSource(`/api/issues/${id}/stream`)
    eventSource = source

    source.addEventListener('summary.ready', (event: MessageEvent) => {
      try {
        const payload = JSON.parse(event.data as string) as SummaryReadyPayload
        status.value = 'ready'
        summary.value = payload.summary
        suggestedNextAction.value = payload.suggested_next_action
        onUpdate(payload)
      } catch {
        // Malformed JSON — ignore.
      }
      closeEventSource()
    })

    source.addEventListener('summary.failed', (event: MessageEvent) => {
      try {
        const payload = JSON.parse(event.data as string) as SummaryFailedPayload
        status.value = 'failed'
        onUpdate(payload)
      } catch {
        // Malformed JSON — ignore.
      }
      closeEventSource()
    })

    source.onerror = () => {
      // EventSource does NOT auto-reconnect on 4xx/5xx — readyState becomes CLOSED.
      // For network errors it does auto-reconnect, but we track repeated failures.
      errorCount++

      if (source.readyState === EventSource.CLOSED || errorCount >= MAX_SSE_ERRORS) {
        closeEventSource()
        startPolling(id)
      }
    }
  }

  // Watch enabled + issueId together; open/close connection reactively.
  watch(
    [issueId, enabled] as const,
    ([id, isEnabled], _old, onCleanup) => {
      if (isEnabled && id !== null) {
        connect(id)
      } else {
        disconnect()
      }

      onCleanup(() => {
        disconnect()
      })
    },
    { immediate: true },
  )

  onUnmounted(() => {
    disconnect()
  })

  return {
    status,
    summary,
    suggestedNextAction,
    connectionError,
  }
}
