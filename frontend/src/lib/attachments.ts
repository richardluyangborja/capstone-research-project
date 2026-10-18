import { api } from './api'
import type { Attachment } from '../types'

/**
 * Files on a communication (module 03 slice S3, spec 11). The uploader's filename is kept for
 * display; the bytes are streamed from an authorized endpoint, so nothing here ever handles a
 * storage path.
 */

export const uploadAttachment = async (
  communicationId: string,
  file: File,
): Promise<Attachment> => {
  const form = new FormData()
  form.append('file', file)

  const { data } = await api.post<{ data: Attachment }>(
    `/communications/${communicationId}/attachments`,
    form,
  )

  return data.data
}

export const deleteAttachment = async (id: string): Promise<void> => {
  await api.delete(`/attachments/${id}`)
}

/**
 * The file is fetched as a blob and saved by the browser, the way the contract document is —
 * the disk and path stay on the server.
 */
export const downloadAttachment = async (attachment: Attachment): Promise<void> => {
  const { data } = await api.get<Blob>(`/attachments/${attachment.id}/file`, {
    responseType: 'blob',
  })

  const url = URL.createObjectURL(data)
  const link = document.createElement('a')
  link.href = url
  link.download = attachment.name
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}
