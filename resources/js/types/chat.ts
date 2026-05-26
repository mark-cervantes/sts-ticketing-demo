/**
 * TypeScript interfaces for the AI chat feature.
 * Mirrors the JSON shapes from IssueChatController and IssueConversationController.
 */

export interface ToolCallData {
  type: 'tool_call'
  tool: string
  arguments: Record<string, unknown>
  requires_confirmation: boolean
}

export interface ToolConfirmResult {
  toolName: string
  success: boolean
  message: string
  data?: { id: number; title: string; [key: string]: unknown }
}

export interface ChatMessage {
  id?: number
  role: 'user' | 'assistant' | 'tool_call'
  content: string
  created_at?: string
  toolCall?: ToolCallData
  toolResult?: ToolConfirmResult
  showNudge?: boolean
}

export interface SavedConversation {
  id: number
  title: string | null
  saved_by: { id: number; name: string }
  messages_count: number
  created_at: string
  updated_at: string
}

export interface ConversationDetail extends SavedConversation {
  messages: ChatMessage[]
}
