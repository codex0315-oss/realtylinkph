<script setup lang="ts">
import type { Appointment, User } from '~/types'

definePageMeta({ layout: 'dashboard' })

const authStore = useAuthStore()
const { appointments, loading, fetchMyAppointments, confirm, complete, cancel } = useAppointment()
const propertyHref = usePropertyHref()

await fetchMyAppointments()

const ask   = useConfirm()
const toast = useToast()

const busy = ref<number | null>(null)

async function doConfirm(id: number) {
  busy.value = id
  if (await confirm(id)) { toast.success('Viewing confirmed'); await fetchMyAppointments() }
  busy.value = null
}

async function doCancel(appt: Appointment) {
  const isAgentDeclining = authStore.isAgent && appt.status === 'pending'
  const who = authStore.isAgent ? 'the buyer' : 'the agent'

  const ok = await ask({
    title:   isAgentDeclining ? 'Decline this viewing request?' : 'Cancel this viewing?',
    message: `${who.charAt(0).toUpperCase() + who.slice(1)} will be notified by email straight away.`,
    subject: appt.property?.title ?? `Property #${appt.property_id}`,
    consequences: [
      `Scheduled for ${fmtDateTime(appt.preferred_datetime)}`,
      'The time slot is released for other buyers',
      'It is removed from both Google Calendars',
    ],
    confirmLabel: isAgentDeclining ? 'Decline request' : 'Cancel viewing',
    cancelLabel:  'Keep it',
    tone: 'danger',
  })
  if (!ok) return

  busy.value = appt.id
  const done = await cancel(appt.id)
  busy.value = null
  if (done) { toast.info(isAgentDeclining ? 'Request declined' : 'Viewing cancelled'); await fetchMyAppointments() }
  else toast.error('Could not cancel this viewing')
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
    tone: 'primary',
  })
  if (!ok) return

  busy.value = appt.id
  const done = await complete(appt.id)
  busy.value = null
  if (done) { toast.success('Viewing marked as done'); await fetchMyAppointments() }
  else toast.error('Could not update this viewing')
}

function isPast(a: Appointment): boolean {
  return new Date(a.preferred_datetime).getTime() < Date.now()
}
function otherParty(a: Appointment): User | undefined {
  return authStore.user?.id === a.agent_id ? a.buyer : a.agent
}
function fmtDateTime(iso: string): string {
  return new Date(iso).toLocaleString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const byDate = (asc: boolean) => (a: Appointment, b: Appointment) =>
  (asc ? 1 : -1) * (+new Date(a.preferred_datetime) - +new Date(b.preferred_datetime))

const groups = computed(() => [
  {
    key: 'pending',
    label: authStore.isAgent ? 'Needs your response' : 'Pending',
    items: appointments.value.filter(a => a.status === 'pending' && !isPast(a)).sort(byDate(true)),
    dot: 'bg-amber-500',
  },
  {
    key: 'upcoming',
    label: 'Upcoming',
    items: appointments.value.filter(a => a.status === 'confirmed' && !isPast(a)).sort(byDate(true)),
    dot: 'bg-emerald-500',
  },
  {
    key: 'past',
    label: 'Past',
    // Cancelled & completed viewings move to History and no longer show here.
    items: appointments.value.filter(a => a.status !== 'cancelled' && a.status !== 'completed' && isPast(a)).sort(byDate(false)),
    dot: 'bg-gray-400',
  },
].filter(g => g.items.length))

const statusBadge: Record<string, string> = {
  pending:   'bg-amber-100 text-amber-700',
  confirmed: 'bg-emerald-100 text-emerald-700',
  completed: 'bg-emerald-100 text-emerald-700',
  cancelled: 'bg-red-100 text-red-600',
}
</script>

<template>
  <div class="max-w-4xl">
    <!-- Header — same shape as the other dashboard pages -->
    <header class="mb-8">
      <h1 class="font-display text-2xl sm:text-[28px] font-bold text-brand-navy dark:text-white leading-tight">Appointments</h1>
      <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/50">
        Property viewings {{ authStore.isAgent ? 'requested on your listings' : 'you\'ve scheduled' }}.
      </p>
    </header>

    <div v-if="loading" class="space-y-3">
      <AppSkeleton v-for="i in 4" :key="i" width="100%" height="96px" rounded="lg" />
    </div>

    <!-- Empty — sits directly on the page, no card, matching My Listings. The
         one-line "No appointments yet" also gets a sentence of context, since
         an agent can't do anything to cause a booking except wait. -->
    <div v-else-if="!groups.length" class="text-center py-24">
      <div class="h-14 w-14 rounded-2xl bg-brand-gold/10 dark:bg-brand-gold/15 flex items-center justify-center mx-auto mb-5">
        <svg class="h-7 w-7 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
      </div>
      <p class="text-lg font-bold text-brand-navy dark:text-white">No appointments yet</p>
      <p class="mx-auto mt-2 max-w-sm text-sm text-brand-text-secondary dark:text-white/50 leading-relaxed">
        {{ authStore.isAgent
          ? 'When a buyer books a viewing on one of your listings, it shows up here.'
          : 'Book a viewing from any listing and it will show up here.' }}
      </p>
      <NuxtLink
        v-if="!authStore.isAgent"
        to="/dashboard/browse"
        class="mt-5 inline-block text-sm font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline"
      >
        Browse listings →
      </NuxtLink>
    </div>

    <div v-else class="space-y-8">
      <section v-for="g in groups" :key="g.key">
        <div class="flex items-center gap-2 mb-3">
          <span class="h-2 w-2 rounded-full" :class="g.dot" />
          <h2 class="text-sm font-bold text-brand-navy uppercase tracking-wide">{{ g.label }}</h2>
          <span class="text-xs text-gray-400">({{ g.items.length }})</span>
        </div>

        <div class="space-y-3">
          <div
            v-for="appt in g.items"
            :key="appt.id"
            class="bg-white rounded-2xl border border-gray-200 p-4 flex gap-4"
            :class="{ 'opacity-70': appt.status === 'cancelled' }"
          >
            <!-- Property thumb (click → details) -->
            <NuxtLink :to="propertyHref(appt.property_id)" class="h-16 w-24 rounded-lg overflow-hidden flex-shrink-0 bg-gray-100">
              <img v-if="appt.property?.photos?.[0]" :src="appt.property.photos[0].url" :alt="appt.property?.title" class="h-full w-full object-cover" />
              <div v-else class="h-full w-full flex items-center justify-center text-gray-300">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.3" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M4 6h16v12H4z" /></svg>
              </div>
            </NuxtLink>

            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <NuxtLink :to="propertyHref(appt.property_id)" class="font-semibold text-brand-navy hover:text-brand-gold transition-colors line-clamp-1">{{ appt.property?.title ?? `Property #${appt.property_id}` }}</NuxtLink>
                <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded-full capitalize flex-shrink-0" :class="statusBadge[appt.status]">{{ appt.status }}</span>
              </div>
              <p class="text-xs text-gray-500 mt-1 flex items-center gap-1.5">
                <svg class="h-3.5 w-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                {{ fmtDateTime(appt.preferred_datetime) }}
              </p>
              <p class="text-xs text-gray-500 mt-0.5 flex items-center gap-1.5">
                <svg class="h-3.5 w-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                {{ authStore.isAgent ? 'Buyer' : 'Agent' }}: {{ otherParty(appt)?.name ?? '—' }}
              </p>
              <p v-if="appt.notes" class="text-xs text-gray-400 mt-1 italic line-clamp-2">“{{ appt.notes }}”</p>
            </div>

            <!-- Actions (cancel is available for any non-cancelled viewing) -->
            <div v-if="appt.status !== 'cancelled'" class="flex flex-col gap-2 flex-shrink-0">
              <button
                v-if="authStore.isAgent && appt.status === 'pending' && !isPast(appt)"
                :disabled="busy === appt.id"
                class="text-xs font-bold text-brand-navy bg-brand-gold rounded-lg px-4 py-2 hover:-translate-y-0.5 transition-all disabled:opacity-50"
                @click="doConfirm(appt.id)"
              >Confirm</button>
              <!-- Agent marks a confirmed viewing as done → moves to History -->
              <button
                v-if="authStore.isAgent && appt.status === 'confirmed'"
                :disabled="busy === appt.id"
                class="text-xs font-bold text-white rounded-lg px-4 py-2 hover:-translate-y-0.5 transition-all disabled:opacity-50 inline-flex items-center justify-center gap-1.5"
                style="background: linear-gradient(135deg, #10b981 0%, #059669 100%)"
                @click="doComplete(appt)"
              >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.4" d="M5 13l4 4L19 7" /></svg>
                Mark as Done
              </button>
              <button
                :disabled="busy === appt.id"
                class="text-xs font-semibold text-red-500 border border-red-200 rounded-lg px-4 py-2 hover:bg-red-50 transition-colors disabled:opacity-50"
                @click="doCancel(appt)"
              >{{ authStore.isAgent && appt.status === 'pending' ? 'Decline' : 'Cancel' }}</button>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>
