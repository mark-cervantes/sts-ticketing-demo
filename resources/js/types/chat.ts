/**
 * TypeScript interfaces for the AI chat feature.
 * Mirrors the JSON shapes from IssueChatController and IssueConversationController.
 */

export interface ChatMessage {
  id?: number
  role: 'user' | 'assistant'
  content: string
  created_at?: string
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
