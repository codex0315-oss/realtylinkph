<script setup lang="ts">
import type { Appointment } from '~/types'

definePageMeta({ layout: 'dashboard' })

const authStore = useAuthStore()
const { appointments, loading, fetchMyAppointments, confirm, complete, cancel, error: apptError } = useAppointment()
const { fetchMe: refreshMe } = useAuth()

/** My own standing — shown as a banner when booking is paused. */
const lockedUntil = computed(() => authStore.user?.booking_locked_until ?? null)
const myReliability = computed(() => authStore.user?.reliability ?? null)
function fmtDay(iso: string) {
  return new Date(iso).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
}

await fetchMyAppointments()

const ask   = useConfirm()
const toast = useToast()

const busy = ref<number | null>(null)

async function doConfirm(id: number) {
  busy.value = id
  if (await confirm(id)) { toast.success('Viewing confirmed'); await fetchMyAppointments() }
  busy.value = null
}

/*
 * Cancelling needs a reason, so the generic confirm dialog isn't enough —
 * CancelViewingModal collects the category and warns when this one will count
 * as a late cancellation.
 */
const cancelTarget  = ref<Appointment | null>(null)
const cancelSending = ref(false)

function doCancel(appt: Appointment) {
  cancelTarget.value = appt
}

async function submitCancel(payload: { code: string; note: string }) {
  const appt = cancelTarget.value
  if (!appt) return
  const isAgentDeclining = authStore.isAgent && appt.status === 'pending'

  cancelSending.value = true
  const done = await cancel(appt.id, payload.code, payload.note)
  cancelSending.value = false

  if (done) {
    cancelTarget.value = null
    toast.info(isAgentDeclining ? 'Request declined' : 'Viewing cancelled')
    await fetchMyAppointments()
    await refreshMe()   // reliability / lockout may have changed
  } else {
    toast.error(apptError.value || 'Could not cancel this viewing')
  }
}

async function doComplete(appt: Appointment) {
  const ok = await ask({
    title:   'Mark this viewing as done?',
    message: 'Do this after you have met the buyer at the property.',
    subject: appt.property?.title ?? `Property #${appt.property_id}`,
    consequences: [
      'It moves to History for both of you',
      'The buyer is then able to leave you a review',
    ],
    confirmLabel: 'Mark as done',
    busyLabel: 'Updating…',
    icon: 'calendar',
    action: async () => (await complete(appt.id)) || apptError.value || 'Could not update this viewing.',
  })
  if (!ok) return
  toast.success('Viewing marked as done', { description: 'The buyer can now leave you a review.' })
  await fetchMyAppointments()
}

</script>

<template>
  <!-- Buyers: the redesigned agenda (BuyerAppointments) -->
  <div v-if="!authStore.isAgent" class="max-w-6xl mx-auto">
    <div v-if="loading" class="space-y-3">
      <AppSkeleton width="100%" height="220px" rounded="lg" />
      <AppSkeleton v-for="i in 2" :key="i" width="100%" height="140px" rounded="lg" />
    </div>
    <BuyerAppointments v-else :appointments="appointments" @cancel="doCancel">
      <template #banners>
        <div v-if="lockedUntil" class="mb-6 rounded-2xl border border-red-200 dark:border-red-500/30 bg-red-50 dark:bg-red-500/10 px-5 py-4">
          <p class="text-sm font-bold text-red-700 dark:text-red-300">Booking is paused</p>
          <p class="text-xs text-red-700/80 dark:text-red-200/70 mt-1 leading-relaxed">
            Five or more confirmed viewings were cancelled within 24 hours of the slot in the last
            30 days. This lifts on <span class="font-semibold">{{ fmtDay(lockedUntil) }}</span>.
          </p>
        </div>
      </template>
    </BuyerAppointments>
    <CancelViewingModal
      :open="cancelTarget !== null"
      :appointment="cancelTarget"
      :is-agent="false"
      :sending="cancelSending"
      @close="cancelTarget = null"
      @confirm="submitCancel"
    />
  </div>

  <!-- Agents: requests, upcoming viewings and ones to close (AgentAppointments) -->
  <div v-else class="max-w-6xl mx-auto">
    <div v-if="loading" class="space-y-3">
      <AppSkeleton width="100%" height="220px" rounded="lg" />
      <AppSkeleton v-for="i in 2" :key="i" width="100%" height="140px" rounded="lg" />
    </div>
    <AgentAppointments
      v-else
      :appointments="appointments"
      :busy-id="busy"
      @confirm="doConfirm($event.id)"
      @complete="doComplete"
      @cancel="doCancel"
    >
      <template #banners>
        <!-- Booking paused: say it here, not only when a booking is refused -->
        <div v-if="lockedUntil" class="mb-6 rounded-2xl border border-red-200 dark:border-red-500/30 bg-red-50 dark:bg-red-500/10 px-5 py-4">
          <p class="text-sm font-bold text-red-700 dark:text-red-300">Your listings are not accepting viewings</p>
          <p class="text-xs text-red-700/80 dark:text-red-200/70 mt-1 leading-relaxed">
            Five or more confirmed viewings were cancelled within 24 hours of the slot in the last
            30 days. This lifts on <span class="font-semibold">{{ fmtDay(lockedUntil) }}</span>.
          </p>
        </div>
        <!-- Quiet nudge once there are strikes but before a lockout -->
        <div v-else-if="myReliability?.missed" class="mb-6 rounded-2xl border border-amber-200 dark:border-amber-500/25 bg-amber-50 dark:bg-amber-500/10 px-5 py-3">
          <p class="text-xs text-amber-900 dark:text-amber-200/90 leading-relaxed">
            <span class="font-bold">{{ myReliability.missed }}</span>
            late cancellation{{ myReliability.missed === 1 ? '' : 's' }} on record. Cancelling more than 24 hours ahead never counts.
          </p>
        </div>
      </template>
    </AgentAppointments>

    <CancelViewingModal
      :open="cancelTarget !== null"
      :appointment="cancelTarget"
      :is-agent="true"
      :sending="cancelSending"
      @close="cancelTarget = null"
      @confirm="submitCancel"
    />
  </div>
</template>
