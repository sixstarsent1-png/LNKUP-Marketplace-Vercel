import { isLiveApiEnabled } from "@/lib/api/config"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchFormData } from "@/lib/api/wp-client"

export async function uploadProfileImage(
  path: string,
  file: { uri: string; fileName: string; mimeType?: string },
  auth?: WpAuth,
): Promise<{ attachmentId: number; url: string }> {
  if (!isLiveApiEnabled()) {
    return { attachmentId: 1, url: file.uri }
  }

  const formData = new FormData()
  formData.append("file", {
    uri: file.uri,
    name: file.fileName,
    type: file.mimeType || "image/jpeg",
  } as unknown as Blob)

  const result = await wpFetchFormData<{
    logo_attachment_id?: number
    logo_id?: number
    logo_url?: string
  }>(path, formData, { auth })

  return {
    attachmentId: Number(result.logo_attachment_id ?? result.logo_id ?? 0) || 0,
    url: result.logo_url ?? "",
  }
}
