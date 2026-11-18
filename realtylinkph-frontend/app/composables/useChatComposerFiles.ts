/**
 * Picking files for the next chat message: the 📎 button, drag and drop onto
 * the thread, and pasting a screenshot. Each file is checked against the same
 * rules as the server before it is accepted.
 */
export function useChatComposerFiles() {
  const files    = ref<File[]>([])
  const input    = ref<HTMLInputElement | null>(null)
  const dragging = ref(false)
  const toast    = useToast()

  function add(list: FileList | File[] | null | undefined) {
    if (!list || !list.length) return
    const { ok, errors } = checkChatFiles(files.value, Array.from(list))
    if (ok.length) files.value = [...files.value, ...ok]
    if (errors.length) toast.error(errors[0]!, errors.length > 1 ? { description: `${errors.length - 1} more file${errors.length > 2 ? 's' : ''} couldn’t be added.` } : undefined)
  }
  function remove(i: number) { files.value = files.value.filter((_, j) => j !== i) }
  function clear() { files.value = [] }
  function pick() { input.value?.click() }
  function onPicked(e: Event) {
    const el = e.target as HTMLInputElement
    add(el.files)
    el.value = ''   // picking the same file again still fires change
  }
  function onPaste(e: ClipboardEvent) {
    const pasted = Array.from(e.clipboardData?.files ?? [])
    if (pasted.length) { e.preventDefault(); add(pasted) }
  }
  let depth = 0
  function onDragEnter(e: DragEvent) { if (e.dataTransfer?.types?.includes('Files')) { depth++; dragging.value = true } }
  function onDragLeave() { depth = Math.max(0, depth - 1); if (!depth) dragging.value = false }
  function onDrop(e: DragEvent) { depth = 0; dragging.value = false; add(e.dataTransfer?.files) }

  return { files, input, dragging, add, remove, clear, pick, onPicked, onPaste, onDragEnter, onDragLeave, onDrop }
}
