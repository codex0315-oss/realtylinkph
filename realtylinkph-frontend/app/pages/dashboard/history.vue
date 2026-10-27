<script setup lang="ts">
import type { AgentReview, Appointment, Property } from '~/types'

definePageMeta({ layout: 'dashboard' })

const authStore = useAuthStore()
const { appointments, loading, fetchMyAppointments } = useAppointment()
const { properties: inventory, fetchInventory, relistProperty } = useProperty()
const toast = useToast()
const ask   = useConfirm()

/* Buyers: their reviews, so a viewing with an already-reviewed agent offers Edit.
   Agents: reviews about them, so a past viewing shows the buyer's rating. */
const { reviews: myReviews, fetchMyReviews, fetchAgentReviews } = useReview()

await fetchMyAppointments()
if (authStore.isAgent) await Promise.all([fetchInventory(), authStore.user ? fetchAgentReviews(authStore.user.id) : null])
else await fetchMyReviews()

/* ── Buyers: rate / edit (one window) ── */
const rateDialog = ref(false)
const rateAppt   = ref<Appointment | null>(null)
const editReview = ref<AgentReview | null>(null)

/* ── Agents: bring a sold listing back as a draft ── */
async function relist(prop: Property) {
  const ok = await ask({
    title:   'Re-list this property?',
    message: 'It comes back as a draft so you can review the details before it goes live again.',
    subject: prop.title,
    consequences: [
      'It returns to My Listings as a draft',
      'Buyers will not see it until you publish it',
      'We will open the edit form so you can update the price',
    ],
    confirmLabel: 'Re-list as draft',
    tone: 'primary',
  })
  if (!ok) return

  if (await relistProperty(prop.id)) {
    toast.success('Back in your listings as a draft')
    await navigateTo(`/dashboard/listings/${prop.id}/edit`)
  } else {
    toast.error('Could not re-list this property')
  }
}
</script>

<template>
  <div class="max-w-6xl mx-auto">
    <div v-if="loading && !appointments.length" class="space-y-3">
      <AppSkeleton v-for="i in 3" :key="i" width="100%" height="120px" rounded="lg" />
    </div>

    <!-- Agents: past viewings + sold listings -->
    <AgentHistory
      v-else-if="authStore.isAgent"
      :appointments="appointments"
      :inventory="inventory"
      :reviews="myReviews"
      @relist="relist"
      @changed="fetchMyAppointments()"
    />

    <!-- Buyers: past viewings, with Rate / Edit review -->
    <template v-else>
      <BuyerHistory
        :appointments="appointments"
        :reviews="myReviews"
        @rate="editReview = null; rateAppt = $event; rateDialog = true"
        @edit="rateAppt = null; editReview = $event; rateDialog = true"
      />
      <RateAgentDialog :open="rateDialog" :appointment="rateAppt" :review="editReview" @close="rateDialog = false" @rated="fetchMyAppointments(); fetchMyReviews()" />
    </template>
  </div>
</template>
