import { DashboardHeader } from "@/components/dashboard-header"
import { DashboardShell } from "@/components/dashboard-shell"
import { DashboardSidebar } from "@/components/dashboard-sidebar"
import { SidebarProvider } from "@/components/ui/sidebar"
import { StudioCalendar } from "@/components/studio-calendar"
import { RecentBookings } from "@/components/recent-bookings"
import { StudioOverview } from "@/components/studio-overview"

export default function DashboardPage() {
  return (
    <SidebarProvider defaultOpen={false}>
      <div className="flex min-h-screen bg-[#161616] text-white">
        <DashboardSidebar />
        <DashboardShell>
          <DashboardHeader title="Dashboard" />
          <div className="grid gap-6 p-6 md:grid-cols-2">
            <StudioCalendar />
            <RecentBookings />
            <StudioOverview className="md:col-span-2" />
          </div>
        </DashboardShell>
      </div>
    </SidebarProvider>
  )
}
